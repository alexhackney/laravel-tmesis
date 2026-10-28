<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Testing\ArrayNodeResolver;
use AlexHackney\Tmesis\Testing\FakeTransferSigner;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Tests\Doubles\TransferNode;
use AlexHackney\Tmesis\Transfers\RefreshTransferAuthorization;
use AlexHackney\Tmesis\Transfers\TransferAuthorization;
use AlexHackney\Tmesis\Transfers\TransferAuthorizationNeed;
use AlexHackney\Tmesis\Transfers\TransferSigner;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
});

/** Every credential fragment the signed requests below carry. */
const XFER_SECRETS = ['X-Amz-Signature=', 'sig-', 'tok-'];

function xferRequest(string $method, string $marker): SignedRequest
{
    return SignedRequest::make(
        $method,
        'https://archive.example.net/backup/'.TransferNode::KEY.'?X-Amz-Signature=sig-'.$marker,
        ['x-amz-security-token' => 'tok-'.$marker],
        now()->addHour()->toDateTimeImmutable(),
    );
}

/**
 * Every operation and owed part the need asks for, signed.
 */
function xferSignAll(TransferAuthorizationNeed $need): TransferAuthorization
{
    $parts = array_map(
        static fn (int $n): PartAuthorization => PartAuthorization::make($n, xferRequest('PUT', 'part-'.$n)),
        $need->owedPartNumbers(),
    );

    return TransferAuthorization::make(
        put: $need->requires('put') ? xferRequest('PUT', 'put') : null,
        parts: $need->requires('parts') ? $parts : null,
        abort: $need->requires('abort') ? xferRequest('DELETE', 'abort') : null,
        head: $need->requiresHead() ? xferRequest('HEAD', 'head') : null,
    );
}

/**
 * @return array<string, mixed>
 */
function xferBody(Request $request): array
{
    /** @var array<string, mixed> */
    return json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
}

function xferAssertValidRefresh(Request $request): void
{
    SchemaValidator::forOpenApi()->assertValid(
        json_decode($request->body()),
        '/components/schemas/AuthorizationRequest',
        'The refresh body',
    );
}

it('supplies a put, bound to the object, at its authorization revision plus 1', function (): void {
    $node = TransferNode::put()->fake();
    FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    $supplies = TransferNode::supplies();

    expect($supplies)->toHaveCount(1)
        ->and($supplies[0]->url())->toBe(TransferNode::BASE.'/transfers/'.TransferNode::OBJECT.'/authorization');

    $body = xferBody($supplies[0]);

    expect(array_keys($body))->toBe(['plan_revision', 'inventory_sha256', 'target_id', 'object_key', 'authorization_revision', 'multipart_upload_id', 'requests'])
        ->and($body['plan_revision'])->toBe(1)
        ->and($body['inventory_sha256'])->toBe(TransferNode::INVENTORY)
        ->and($body['target_id'])->toBe(TransferNode::TARGET)
        ->and($body['object_key'])->toBe(TransferNode::KEY)
        ->and($body['authorization_revision'])->toBe(1)
        ->and($body['multipart_upload_id'])->toBeNull()
        ->and($body['requests']['put']['method'])->toBe('PUT')
        ->and($body['requests']['put']['url'])->toContain('sig-put')
        ->and($body['requests']['head'])->toBeNull()
        ->and($body['requests']['parts'])->toBeNull();

    xferAssertValidRefresh($supplies[0]);
});

it('answers a completion probe with a HEAD and nothing else', function (): void {
    $node = TransferNode::probe()->fake();
    $signer = FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    $supplies = TransferNode::supplies();
    $body = xferBody($supplies[0]);

    expect($supplies)->toHaveCount(1)
        ->and($body['authorization_revision'])->toBe(2)
        ->and($body['requests']['head']['method'])->toBe('HEAD')
        ->and(array_filter($body['requests'], fn (mixed $value): bool => $value !== null))->toHaveKeys(['head'])->toHaveCount(1);

    $signer->assertSignedHead(TransferNode::OBJECT);
    xferAssertValidRefresh($supplies[0]);
});

it('supplies the owed parts and the abort, bound to the committed upload', function (): void {
    $node = TransferNode::multipart(parts: 4, complete: 1)->fake();
    $signer = FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    $body = xferBody(TransferNode::supplies()[0]);

    expect($body['authorization_revision'])->toBe(3)
        ->and($body['multipart_upload_id'])->toBe('provider-upload-id')
        ->and(array_column($body['requests']['parts'], 'part_number'))->toBe([2, 3, 4])
        ->and($body['requests']['abort']['method'])->toBe('DELETE');

    $signer->assertSignedParts(TransferNode::OBJECT, [2, 3, 4]);
    xferAssertValidRefresh(TransferNode::supplies()[0]);
});

it('chunks more than 128 parts into successive revisions, each after the last one\'s answer', function (): void {
    $node = TransferNode::multipart(parts: 300, complete: 0)->fake();
    FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    $bodies = array_map(xferBody(...), TransferNode::supplies());

    expect($bodies)->toHaveCount(3)
        ->and(array_column($bodies, 'authorization_revision'))->toBe([3, 4, 5])
        ->and(array_map(fn (array $b): int => count($b['requests']['parts']), $bodies))->toBe([128, 128, 44])
        ->and($bodies[1]['requests']['parts'][0]['part_number'])->toBe(129)
        ->and($bodies[0]['requests']['abort'])->not->toBeNull()
        ->and($bodies[1]['requests']['abort'])->toBeNull()
        ->and($bodies[2]['requests']['abort'])->toBeNull();

    foreach (TransferNode::supplies() as $supply) {
        xferAssertValidRefresh($supply);
    }
});

it('supplies nothing when the signer leaves the object waiting', function (?TransferAuthorization $answer): void {
    $node = TransferNode::put()->fake();
    $signer = FakeTransferSigner::fake($answer);

    event($node->toEvent());

    $signer->assertSignedCount(1);
    expect(TransferNode::supplies())->toBe([]);
})->with([
    'null' => [null],
    'an empty answer' => [fn () => TransferAuthorization::make()],
]);

it('signs nothing and supplies nothing for a superseded plan revision', function (): void {
    $node = TransferNode::put();
    $node->plan['revision'] = 2;
    $node->fake();
    $signer = FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    $signer->assertNothingSigned();
    expect(TransferNode::supplies())->toBe([])
        ->and(Http::recorded())->toHaveCount(1);
});

it('stops at a 409 and logs it without a URL or a header value', function (string $code): void {
    $node = TransferNode::multipart(parts: 300, complete: 0);
    $node->supply = fn (array $body, int $call) => $call === 1
        ? Http::response(array_merge($node->object, ['authorization_revision' => $body['authorization_revision']]))
        : Http::response(['error' => ['code' => $code, 'message' => 'refused', 'details' => null, 'request_id' => 'req_1', 'milestone' => null]], 409);
    $node->fake();
    FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    event($node->toEvent());

    expect(TransferNode::supplies())->toHaveCount(2);

    $warning = array_values(array_filter($logged, fn (string $line): bool => str_contains($line, 'refused')));

    expect($warning)->toHaveCount(1)
        ->and($warning[0])->toContain($code)
        ->and($warning[0])->toContain('"authorization_revision":4');

    foreach (XFER_SECRETS as $secret) {
        expect(implode("\n", $logged))->not->toContain($secret);
    }
})->with(['request_conflict', 'transfer_identity_mismatch', 'transfer_plan_stale']);

it('rethrows a 422 so the job retries from the read', function (): void {
    $node = TransferNode::put();
    $node->supply = fn () => Http::response(['error' => [
        'code' => 'validation_failed',
        'message' => 'signed request does not match its pinned operation',
        'details' => [],
        'request_id' => 'req_2',
        'milestone' => null,
    ]], 422);
    $node->fake();
    FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    try {
        event($node->toEvent());
        $this->fail('Expected the 422 to be rethrown.');
    } catch (ValidationException $e) {
        foreach (XFER_SECRETS as $secret) {
            expect($e->getMessage())->not->toContain($secret);
        }
    }
});

it('answers a signed delivery end to end, reading from the node that sent it', function (): void {
    $node = TransferNode::put()->fake();
    $signer = FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    $this->signedPost(TransferAuthorizationRequired::EVENT, $node->event)->assertNoContent();

    $signer->assertSignedObject(TransferNode::OBJECT);
    expect($signer->needs()[0]->node())->toBe('edge-venue-07')
        ->and(TransferNode::supplies())->toHaveCount(1);
});

it('reads from a node the resolver supplies when the event names it', function (): void {
    ArrayNodeResolver::bind([new NodeConfig('origin-dyn-01', 'http://10.0.0.9:7000', 'dyn-token', 'dyn-secret')]);

    $node = TransferNode::put();
    $node->event['node'] = 'origin-dyn-01';
    $node->fake();
    $signer = FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    $supplies = TransferNode::supplies();

    expect($signer->needs()[0]->node())->toBe('origin-dyn-01')
        ->and($supplies)->toHaveCount(1)
        ->and($supplies[0]->url())->toStartWith('http://10.0.0.9:7000/api/v1/streams/')
        ->and($supplies[0]->header('Authorization'))->toBe(['Bearer dyn-token']);

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://127.0.0.1:7000/'));
});

it('does not listen when no signer is configured or bound', function (): void {
    expect(config('tmesis.transfers.signer'))->toBeNull()
        ->and(RefreshTransferAuthorization::signerConfigured(app()))->toBeFalse()
        ->and(Event::hasListeners(TransferAuthorizationRequired::class))->toBeFalse();

    $node = TransferNode::put()->fake();

    event($node->toEvent());

    Http::assertNothingSent();
});

it('listens when the config names a signer', function (): void {
    $this->withTransferSigner(FakeTransferSigner::class);

    expect(app('events')->getRawListeners()[TransferAuthorizationRequired::class] ?? [])
        ->toContain(RefreshTransferAuthorization::class);
});

it('counts a signer bound in the container as configured', function (): void {
    app()->instance(TransferSigner::class, new FakeTransferSigner);

    expect(RefreshTransferAuthorization::signerConfigured(app()))->toBeTrue();
});

it('resolves the configured class when nothing is bound, and refuses one that is not a signer', function (): void {
    $node = TransferNode::put()->fake();
    config()->set('tmesis.transfers.signer', stdClass::class);
    Event::listen(TransferAuthorizationRequired::class, RefreshTransferAuthorization::class);

    event($node->toEvent());
})->throws(InvalidConfigurationException::class, 'tmesis.transfers.signer');

it('puts the job on the configured queue', function (): void {
    config()->set('tmesis.transfers.queue', 'transfers');

    expect(app(RefreshTransferAuthorization::class)->viaQueue())->toBe('transfers');

    config()->set('tmesis.transfers.queue', '');

    expect(app(RefreshTransferAuthorization::class)->viaQueue())->toBeNull();
});

it('is queued, and the job carries the event alone', function (): void {
    Queue::fake();
    $node = TransferNode::put()->fake();
    $signer = FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === RefreshTransferAuthorization::class
        && $job->data[0] instanceof TransferAuthorizationRequired);
    $signer->assertNothingSigned();
    Http::assertNothingSent();
});

it('supplies a job generation\'s refresh to the job route, never to a stream route (v0.6.0)', function (): void {
    $node = TransferNode::put()->asJobGeneration()->fake();
    FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    event($node->toEvent());

    $supplies = TransferNode::supplies();

    expect($supplies)->toHaveCount(1)
        ->and($supplies[0]->url())->toBe(TransferNode::JOB_BASE.'/transfers/'.TransferNode::OBJECT.'/authorization')
        ->and(xferBody($supplies[0])['authorization_revision'])->toBe(1);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/streams/'));
    xferAssertValidRefresh($supplies[0]);
});

it('logs a refused job refresh with the job and no URL', function (): void {
    $node = TransferNode::put()->asJobGeneration();
    $node->supply = fn (): mixed => Http::response(['error' => ['code' => 'transfer_plan_stale', 'message' => 'stale', 'details' => null, 'request_id' => 'r', 'milestone' => null]], 409);
    $node->fake();
    FakeTransferSigner::fake(fn (TransferAuthorizationNeed $need) => xferSignAll($need));

    Log::spy();

    event($node->toEvent());

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $flat = json_encode($context);

        return $context['job'] === TransferNode::JOB && $context['stream'] === null && ! str_contains((string) $flat, 'sig-');
    });
});
