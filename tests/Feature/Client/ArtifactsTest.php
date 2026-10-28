<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Exceptions\BadRequestException;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\ServerException;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

const ART_STREAM = 'show-2041-main';

const ART_GENERATION = '01J8ZCK5R7V2QNX8RW4TKB6PD2';

const ART_OBJECT = '01J8ZCK9T4N2QWX6VM3RKB9PD8';

const ART_INVENTORY = '9f2c1d5e6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f';

const ART_BASE = 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/artifacts';

/** A presigned URL and a signed header value: both credentials. */
const ART_SECRET_URL = 'https://archive.example.net/backup/show-ingest.ts?X-Amz-Credential=AKIAEXAMPLE&X-Amz-Signature=deadbeefsig';

const ART_SECRET_PARTS = ['AKIAEXAMPLE', 'deadbeefsig', 'hdr-secret-token'];

/**
 * @return array<string, mixed>
 */
function artifactError(string $code, string $message = 'refused'): array
{
    return ['error' => [
        'code' => $code,
        'message' => $message,
        'details' => null,
        'request_id' => 'req_01J8ZC',
        'milestone' => null,
    ]];
}

/**
 * @return array<string, mixed>
 */
function artifactBody(Request $request): array
{
    /** @var array<string, mixed> */
    return json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR) ?: [];
}

function artifactSecretRequest(): AuthorizationRequest
{
    return AuthorizationRequest::make(
        1,
        ART_INVENTORY,
        '01J8ZCK8T4N2QWX6VM3RKB9PD8',
        'backup/show-ingest.ts',
        3,
        'provider-upload-id',
        AuthorizationBundle::make(parts: [PartAuthorization::make(101, SignedRequest::make(
            'PUT',
            ART_SECRET_URL,
            ['x-amz-security-token' => 'hdr-secret-token'],
            '2026-09-22T22:30:00.000Z',
        ))]),
    );
}

function carriesAnArtifactSecret(string $rendered): bool
{
    foreach (ART_SECRET_PARTS as $part) {
        if (str_contains($rendered, $part)) {
            return true;
        }
    }

    return false;
}

it('lists a stream\'s generations, sending limit and cursor only when given', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listArtifacts.json'))]);

    $page = Tmesis::node()->artifacts()->listArtifacts(ART_STREAM);
    Tmesis::node()->artifacts()->listArtifacts(ART_STREAM, 50, 'opaque');

    expect($page->items())->toHaveCount(2)
        ->and($page->items()[0])->toBeInstanceOf(ArtifactStatus::class)
        ->and($page->items()[0]->kind())->toBe(ArtifactStatus::KIND_RECORDING_INGEST)
        ->and($page->items()[1]->inventorySha256())->toBeNull()
        ->and($page->items()[1]->planRevision())->toBeNull()
        ->and($page->items()[1]->retentionExpiresAt())->toBeNull()
        ->and($page->limit())->toBe(100)
        ->and($page->nextCursor())->not->toBeNull();

    $recorded = Http::recorded();

    expect($recorded[0][0]->method())->toBe('GET')
        ->and($recorded[0][0]->url())->toBe(ART_BASE)
        ->and($recorded[1][0]->url())->toBe(ART_BASE.'?limit=50&cursor=opaque');
});

it('reads one generation, its hold reasons and its retention', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getArtifact.json'))]);

    $artifact = Tmesis::node()->artifacts()->getArtifact(ART_STREAM, ART_GENERATION);

    expect($artifact->id())->toBe(ART_GENERATION)
        ->and($artifact->isFinalized())->toBeTrue()
        ->and($artifact->held())->toBeTrue()
        ->and($artifact->holdReasons())->toBe(['retention_not_elapsed', 'release_required'])
        ->and($artifact->planRevision())->toBe(1)
        ->and($artifact->releaseState())->toBe(ArtifactStatus::RELEASE_UNRELEASED)
        ->and($artifact->retentionExpiresAt()?->format('Y-m-d'))->toBe('2026-09-25');

    Http::assertSent(fn (Request $request): bool => $request->url() === ART_BASE.'/'.ART_GENERATION);
});

it('encodes every path segment', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listTransferParts.json'))]);

    Tmesis::node()->artifacts()->listTransferParts(ART_STREAM, 'gen/1', 'obj 2');

    Http::assertSent(fn (Request $request): bool => $request->url() === ART_BASE.'/gen%2F1/transfers/obj%202/parts');
});

it('reads the inventory, the current plan, the transfers and the parts', function (): void {
    Http::fake([
        '*/inventory*' => Http::response($this->fixtureJson('responses/getArtifactInventory.json')),
        '*/transfer-plan' => Http::response($this->fixtureJson('responses/getTransferPlan.json')),
        '*/parts*' => Http::response($this->fixtureJson('responses/listTransferParts.json')),
        '*/transfers*' => Http::response($this->fixtureJson('responses/listTransfers.json')),
    ]);

    $artifacts = Tmesis::node()->artifacts();

    $inventory = $artifacts->getArtifactInventory(ART_STREAM, ART_GENERATION);
    $plan = $artifacts->getTransferPlan(ART_STREAM, ART_GENERATION);
    $transfers = $artifacts->listTransfers(ART_STREAM, ART_GENERATION);
    $parts = $artifacts->listTransferParts(ART_STREAM, ART_GENERATION, ART_OBJECT);

    expect($inventory->items()[0]->path())->toBe('recording.ts')
        ->and($inventory->nextCursor())->toBeNull()
        ->and($plan->created)->toBeFalse()
        ->and($plan->revision())->toBe(1)
        ->and($plan->targets())->toHaveCount(2)
        ->and($plan->targets()[1]->credentialProfile())->toBeNull()
        ->and($plan->counts()->waitingAuthorization())->toBe(1)
        ->and($transfers->items()[1]->state())->toBe(ObjectStatus::STATE_WAITING_AUTHORIZATION)
        ->and($transfers->items()[1]->error()?->code())->toBe('authorization_missing')
        ->and($transfers->items()[0]->error())->toBeNull()
        ->and($parts->items()[0]->partNumber())->toBe(100)
        ->and($parts->items()[1]->etag())->toBeNull();

    $urls = array_map(static fn (array $pair): string => $pair[0]->url(), Http::recorded()->all());

    expect($urls)->toBe([
        ART_BASE.'/'.ART_GENERATION.'/inventory',
        ART_BASE.'/'.ART_GENERATION.'/transfer-plan',
        ART_BASE.'/'.ART_GENERATION.'/transfers',
        ART_BASE.'/'.ART_GENERATION.'/transfers/'.ART_OBJECT.'/parts',
    ]);
});

it('puts a transfer plan and reports whether it created revision 1', function (int $status, bool $created): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/replaceTransferPlan.json'), $status)]);

    $plan = PlanRequest::make(ART_INVENTORY, 0, '01J8ZCK6R3M8QWX2VN5TKB7PD4')
        ->withTarget(PlanTarget::recordingIngest('csn-vod', 'csn/2026/show/show-ingest.ts', '01J8ZCK7T4N2QWX6VM3RKB9PD8'));

    $answer = Tmesis::node()->artifacts()->replaceTransferPlan(ART_STREAM, ART_GENERATION, $plan);

    expect($answer->created)->toBe($created)
        ->and($answer->current())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === ART_BASE.'/'.ART_GENERATION.'/transfer-plan'
        && artifactBody($request) === [
            'request_id' => '01J8ZCK6R3M8QWX2VN5TKB7PD4',
            'expected_revision' => 0,
            'inventory_sha256' => ART_INVENTORY,
            'targets' => [[
                'id' => '01J8ZCK7T4N2QWX6VM3RKB9PD8',
                'artifact' => 'recording_ingest',
                'destination_ref' => 'csn-vod',
                'key' => 'csn/2026/show/show-ingest.ts',
            ]],
        ]);
})->with([
    '201 created revision 1' => [201, true],
    '200 a later revision or a duplicate' => [200, false],
]);

it('sends an authorization refresh and gets back only the safe status', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/supplyTransferAuthorization.json'))]);

    $status = Tmesis::node()->artifacts()->supplyTransferAuthorization(ART_STREAM, ART_GENERATION, ART_OBJECT, artifactSecretRequest());

    expect($status->authorizationAvailable())->toBeTrue()
        ->and($status->authorizationRevision())->toBe(3)
        ->and(carriesAnArtifactSecret((string) json_encode($status->toArray())))->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $body = artifactBody($request);

        // The one place the credential goes: the outbound body, in full.
        return $request->method() === 'PUT'
            && $request->url() === ART_BASE.'/'.ART_GENERATION.'/transfers/'.ART_OBJECT.'/authorization'
            && $body['authorization_revision'] === 3
            && $body['multipart_upload_id'] === 'provider-upload-id'
            && $body['requests']['parts'][0]['request']['url'] === ART_SECRET_URL
            && $body['requests']['parts'][0]['request']['headers'] === ['x-amz-security-token' => ['hdr-secret-token']]
            && $body['requests']['put'] === null;
    });
});

it('never puts a signed URL or header on an exception, a log line or a dump', function (): void {
    Log::spy();
    Http::fake(['*' => Http::response(artifactError('transfer_identity_mismatch', 'the upload is not the object\'s'), 409)]);

    $request = artifactSecretRequest();

    try {
        Tmesis::node()->artifacts()->supplyTransferAuthorization(ART_STREAM, ART_GENERATION, ART_OBJECT, $request);
        $this->fail('A 409 threw nothing.');
    } catch (TmesisException $e) {
        expect($e)->toBeInstanceOf(ConflictException::class)
            ->and(carriesAnArtifactSecret($e->getMessage()))->toBeFalse()
            ->and(carriesAnArtifactSecret((string) json_encode($e->error()?->toArray())))->toBeFalse();
    }

    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');

    expect(carriesAnArtifactSecret(print_r($request, true)))->toBeFalse();

    ob_start();
    var_dump($request);
    $dumped = (string) ob_get_clean();

    expect(carriesAnArtifactSecret($dumped))->toBeFalse()
        ->and($dumped)->toContain('archive.example.net');
});

it('releases, and tells a first decision from an exact duplicate', function (int $status, bool $recorded): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/releaseArtifact.json'), $status)]);

    $decision = Tmesis::node()->artifacts()->releaseArtifact(
        ART_STREAM,
        ART_GENERATION,
        ReleaseRequest::make(2, ART_INVENTORY, 'All requested copies verified and accepted', '01J8ZCKAT4N2QWX6VM3RKB9PD8'),
    );

    expect($decision->recorded)->toBe($recorded)
        ->and($decision->releaseState())->toBe(ArtifactStatus::RELEASE_RELEASED);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === ART_BASE.'/'.ART_GENERATION.'/release'
        && artifactBody($request) === [
            'request_id' => '01J8ZCKAT4N2QWX6VM3RKB9PD8',
            'plan_revision' => 2,
            'inventory_sha256' => ART_INVENTORY,
            'reason' => 'All requested copies verified and accepted',
        ]);
})->with([
    '202 recorded' => [202, true],
    '200 duplicate' => [200, false],
]);

it('overrides, and tells a first decision from an exact duplicate', function (int $status, bool $recorded): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/overrideArtifact.json'), $status)]);

    $decision = Tmesis::node()->artifacts()->overrideArtifact(
        ART_STREAM,
        ART_GENERATION,
        OverrideRequest::override(2, ART_INVENTORY, 'Primary accepted; backup copy waived', ['01J8ZCK8T4N2QWX6VM3RKB9PD8']),
    );

    expect($decision->recorded)->toBe($recorded)
        ->and($decision->waivedTargets())->toBe(['01J8ZCK8T4N2QWX6VM3RKB9PD8']);
})->with([
    '202 recorded' => [202, true],
    '200 duplicate' => [200, false],
]);

it('discards an abandoned generation with the one shape the node accepts for it', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/overrideArtifact.json'), 202)]);

    Tmesis::node()->artifacts()->overrideArtifact(
        ART_STREAM,
        ART_GENERATION,
        OverrideRequest::discardAbandoned('Crashed window, encoder shape changed', '01J8ZCKBT4N2QWX6VM3RKB9PD8'),
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === ART_BASE.'/'.ART_GENERATION.'/override'
        && $request->body() === '{"request_id":"01J8ZCKBT4N2QWX6VM3RKB9PD8","plan_revision":0,"inventory_sha256":"","action":"discard","reason":"Crashed window, encoder shape changed","waived_targets":[]}');
});

it('walks every page lazily, following next_cursor until it is null', function (string $method, array $args, string $fixture): void {
    $first = $this->fixtureJson("responses/{$fixture}.json");
    $second = $first;
    $first['next_cursor'] = 'page-2';
    $second['next_cursor'] = null;

    Http::fake(['*' => Http::sequence()->push($first)->push($second)]);

    $walk = Tmesis::node()->artifacts()->{$method}(...$args);

    expect($walk)->toBeInstanceOf(LazyCollection::class);
    Http::assertNothingSent();

    $items = $walk->all();

    expect($items)->toHaveCount(count($first['items']) * 2)
        ->and(array_keys($items))->toBe(range(0, count($items) - 1));

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2)
        ->and(parse_url($recorded[0][0]->url(), PHP_URL_QUERY))->toBe('limit=25')
        ->and(parse_url($recorded[1][0]->url(), PHP_URL_QUERY))->toBe('limit=25&cursor=page-2');
})->with([
    'allArtifacts' => ['allArtifacts', [ART_STREAM, 25], 'listArtifacts'],
    'allArtifactInventory' => ['allArtifactInventory', [ART_STREAM, ART_GENERATION, 25], 'getArtifactInventory'],
    'allTransfers' => ['allTransfers', [ART_STREAM, ART_GENERATION, 25], 'listTransfers'],
    'allTransferParts' => ['allTransferParts', [ART_STREAM, ART_GENERATION, ART_OBJECT, 25], 'listTransferParts'],
]);

it('refuses a page size that is not positive before any request', function (Closure $call): void {
    Http::fake();

    expect($call)->toThrow(InvalidConfigurationException::class, 'positive integer');

    Http::assertNothingSent();
})->with([
    'listArtifacts 0' => [fn () => Tmesis::node()->artifacts()->listArtifacts(ART_STREAM, 0)],
    'getArtifactInventory -1' => [fn () => Tmesis::node()->artifacts()->getArtifactInventory(ART_STREAM, ART_GENERATION, -1)],
    'listTransfers 0' => [fn () => Tmesis::node()->artifacts()->listTransfers(ART_STREAM, ART_GENERATION, 0)],
    'listTransferParts 0' => [fn () => Tmesis::node()->artifacts()->listTransferParts(ART_STREAM, ART_GENERATION, ART_OBJECT, 0)],
    'allTransfers 0, before the walk is read' => [fn () => Tmesis::node()->artifacts()->allTransfers(ART_STREAM, ART_GENERATION, 0)],
]);

it('lets a limit above 1000 through for the node to clamp', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listArtifacts.json'))]);

    Tmesis::node()->artifacts()->listArtifacts(ART_STREAM, 5000);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '?limit=5000'));
});

it('maps each of the nine artifact conflicts to ConflictException with its code', function (string $code): void {
    Http::fake(['*' => Http::response(artifactError($code), 409)]);

    try {
        Tmesis::node()->artifacts()->releaseArtifact(ART_STREAM, ART_GENERATION, ReleaseRequest::make(1, ART_INVENTORY, 'done'));
        $this->fail("{$code} threw nothing.");
    } catch (ConflictException $e) {
        expect($e->code())->toBe($code)
            ->and($e->status())->toBe(409);
    }
})->with([
    'artifact_not_finalized',
    'artifact_identity_mismatch',
    'transfer_plan_stale',
    'transfer_identity_mismatch',
    'transfer_incomplete',
    'request_conflict',
    'release_conflict',
    'artifact_retiring',
    'artifact_reconciliation_required',
]);

it('answers a generation of another stream with NotFoundException, never a conflict', function (): void {
    Http::fake(['*' => Http::response(artifactError('not_found', 'no such generation on this stream'), 404)]);

    expect(fn () => Tmesis::node()->artifacts()->getArtifact('show-2042-main', ART_GENERATION))
        ->toThrow(NotFoundException::class);
});

it('answers a cursor from another scope with BadRequestException', function (): void {
    Http::fake(['*' => Http::response(artifactError('bad_request', 'cursor is not one this route issued'), 400)]);

    expect(fn () => Tmesis::node()->artifacts()->listTransfers(ART_STREAM, ART_GENERATION, cursor: 'from-another-route'))
        ->toThrow(BadRequestException::class);
});

it('stops a walk on a stale transfers cursor with the conflict', function (): void {
    $first = $this->fixtureJson('responses/listTransfers.json');
    $first['next_cursor'] = 'revision-1-page-2';

    Http::fake(['*' => Http::sequence()->push($first)->push(artifactError('transfer_plan_stale'), 409)]);

    expect(fn () => Tmesis::node()->artifacts()->allTransfers(ART_STREAM, ART_GENERATION)->all())
        ->toThrow(ConflictException::class);
});

it('stops a walk whose node answers the same cursor twice in a row, rather than looping', function (): void {
    $page = $this->fixtureJson('responses/listTransfers.json');
    $page['next_cursor'] = 'stuck';

    Http::fake(['*' => Http::sequence()->push($page)->push($page)->push($page)]);

    expect(fn () => Tmesis::node()->artifacts()->allTransfers(ART_STREAM, ART_GENERATION)->all())
        ->toThrow(ServerException::class, 'same next_cursor twice');

    expect(Http::recorded())->toHaveCount(2);
});

it('carries a request id through to the artifacts resource', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getArtifact.json'))]);

    Tmesis::node()->withRequestId('req-artifacts-1')->artifacts()->getArtifact(ART_STREAM, ART_GENERATION);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Request-Id', 'req-artifacts-1'));
});

it('does not reach a node from the fake: the fake answers artifacts from its own ledger', function (): void {
    Tmesis::fake()->artifactFinalized(ART_STREAM, ART_GENERATION);
    Http::fake();

    expect(Tmesis::node()->artifacts()->getArtifact(ART_STREAM, ART_GENERATION)->id())->toBe(ART_GENERATION);

    Http::assertNothingSent();
});
