<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\PartStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTargetStatus;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\Doubles\TransferNode;
use AlexHackney\Tmesis\Transfers\TransferAuthorizationNeed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function xferResolve(TransferNode $node): ?TransferAuthorizationNeed
{
    $node->fake();

    return TransferAuthorizationNeed::resolve(Tmesis::node()->artifacts(), $node->toEvent(), 'edge-venue-07');
}

it('drops the complete parts and orders the owed ones', function (): void {
    $node = TransferNode::multipart();
    $parts = array_map(static fn (array $part): PartStatus => PartStatus::fromArray($part), array_reverse($node->parts));

    $need = TransferAuthorizationNeed::make(
        'edge-venue-07',
        $node->toEvent(),
        PlanTargetStatus::fromArray($node->plan['targets'][1]),
        ObjectStatus::fromArray($node->object),
        $parts,
    );

    expect($need->owedPartNumbers())->toBe([2, 3, 4])
        ->and($need->owedParts()[0]->offsetBytes())->toBe(16777216)
        ->and($need->owedParts()[0]->checksumSha256())->toBe(str_pad('2', 64, '0', STR_PAD_LEFT));
});

it('reads a multipart need: the plan target, the object and the owed parts', function (): void {
    $need = xferResolve(TransferNode::multipart(parts: 5, complete: 2));

    expect($need)->not->toBeNull()
        ->and($need->node())->toBe('edge-venue-07')
        ->and($need->stream())->toBe(TransferNode::STREAM)
        ->and($need->generation())->toBe(TransferNode::GENERATION)
        ->and($need->inventorySha256())->toBe(TransferNode::INVENTORY)
        ->and($need->labels())->toBe(['show_id' => '2041', 'site' => 'csn'])
        ->and($need->planRevision())->toBe(1)
        ->and($need->targetId())->toBe(TransferNode::TARGET)
        ->and($need->target()->bucket())->toBe('backup')
        ->and($need->target()->endpoint())->toBe('https://archive.example.net')
        ->and($need->objectId())->toBe(TransferNode::OBJECT)
        ->and($need->objectKey())->toBe(TransferNode::KEY)
        ->and($need->artifactPath())->toBe('recording.ts')
        ->and($need->multipartUploadId())->toBe('provider-upload-id')
        ->and($need->partSizeBytes())->toBe(16777216)
        ->and($need->sizeBytes())->toBe(19204817408)
        ->and($need->checksumSha256())->toHaveLength(64)
        ->and($need->completionBodySha256())->toBeNull()
        ->and($need->requiredOperations())->toBe(['parts', 'abort'])
        ->and($need->requires('abort'))->toBeTrue()
        ->and($need->requiresHead())->toBeFalse()
        ->and($need->reason())->toBe('authorization_expired')
        ->and($need->isCompletionProbe())->toBeFalse()
        ->and($need->owedPartNumbers())->toBe([3, 4, 5])
        ->and($need->object()->authorizationRevision())->toBe(2)
        ->and($need->identity()->kind())->toBe('hls_sealed')
        ->and($need->event()->objectId())->toBe(TransferNode::OBJECT);

    expect(Http::recorded())->toHaveCount(3);
});

it('reads no parts when the need has none', function (): void {
    $need = xferResolve(TransferNode::probe());

    expect($need)->not->toBeNull()
        ->and($need->requiresHead())->toBeTrue()
        ->and($need->isCompletionProbe())->toBeTrue()
        ->and($need->owedParts())->toBe([])
        ->and($need->object()->reconcileRequired())->toBeTrue();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/parts'));
});

it('is null, reading nothing else, when the event\'s plan revision is not current', function (array $plan): void {
    $node = TransferNode::put();
    $node->plan = array_merge($node->plan, $plan);

    Log::spy();

    expect(xferResolve($node))->toBeNull()
        ->and(Http::recorded())->toHaveCount(1);

    Log::shouldHaveReceived('debug')->withArgs(
        fn (string $message): bool => str_contains($message, 'the plan revision is no longer current')
    );
})->with([
    'a later revision' => [['revision' => 2]],
    'not current' => [['current' => false]],
]);

it('is null when the target is not signed', function (): void {
    $node = TransferNode::put();
    $node->plan['targets'][1]['authorization'] = 'configured';

    expect(xferResolve($node))->toBeNull();
});

it('is null when the object is not in the current revision', function (): void {
    $node = TransferNode::put();
    $node->object['id'] = '01J8ZCKZZZZZZZZZZZZZZZZZZZ';

    expect(xferResolve($node))->toBeNull();
});

it('is null when the object is no longer waiting', function (string $state): void {
    $node = TransferNode::put();
    $node->object['state'] = $state;

    expect(xferResolve($node))->toBeNull();
})->with(['uploading', 'complete', 'failed']);

it('is null when the object now waits on a newer need', function (): void {
    $node = TransferNode::put();
    $node->object['phase'] = 'initiate';
    $node->object['authorization_required'] = ['initiate'];

    expect(xferResolve($node))->toBeNull();
});

it('is null for a configured target missing its credentials: nothing to sign', function (): void {
    $node = TransferNode::put();
    $node->object['authorization_required'] = [];
    $node->event['required'] = [];
    $node->event['reason'] = 'credentials_missing';

    expect(xferResolve($node))->toBeNull();
});

it('pages through the transfers until it finds the object', function (): void {
    $node = TransferNode::put();

    Http::fake(function (Request $request) use ($node) {
        if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/transfer-plan')) {
            return Http::response($node->plan);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['cursor'] ?? null) === null
            ? Http::response(['items' => [array_merge($node->object, ['id' => 'other'])], 'limit' => 1000, 'next_cursor' => 'page-2'])
            : Http::response(['items' => [$node->object], 'limit' => 1000, 'next_cursor' => 'page-3']);
    });

    $need = TransferAuthorizationNeed::resolve(Tmesis::node()->artifacts(), $node->toEvent(), 'edge-venue-07');

    expect($need?->objectId())->toBe(TransferNode::OBJECT)
        ->and(Http::recorded())->toHaveCount(3);
});

it('reads a JOB generation\'s need through the job routes, never the stream routes (v0.6.0)', function (): void {
    $node = TransferNode::multipart(parts: 3, complete: 1)->asJobGeneration();
    $node->fake();

    $need = TransferAuthorizationNeed::resolve(Tmesis::node()->jobArtifacts(), $node->toEvent(), 'origin-atl-01');

    expect($need)->not->toBeNull()
        ->and($need->isJobGeneration())->toBeTrue()
        ->and($need->jobId())->toBe(TransferNode::JOB)
        ->and($need->stream())->toBeNull()
        ->and($need->owedPartNumbers())->toBe([2, 3])
        ->and(Http::recorded())->toHaveCount(3);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/streams/'));
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), TransferNode::JOB_BASE.'/transfers/'.TransferNode::OBJECT.'/parts'));
});

it('logs a skipped job need with the job and without a stream', function (): void {
    $node = TransferNode::put()->asJobGeneration();
    $node->plan['current'] = false;
    $node->fake();

    Log::spy();

    expect(TransferAuthorizationNeed::resolve(Tmesis::node()->jobArtifacts(), $node->toEvent(), 'origin-atl-01'))->toBeNull();

    Log::shouldHaveReceived('debug')->withArgs(
        fn (string $message, array $context): bool => $context['job'] === TransferNode::JOB && $context['stream'] === null
    );
});

it('refuses the resource that does not own the generation, reading nothing', function (): void {
    Http::fake();

    expect(fn () => TransferAuthorizationNeed::resolve(Tmesis::node()->artifacts(), TransferNode::put()->asJobGeneration()->toEvent(), 'n'))
        ->toThrow(InvalidConfigurationException::class, 'jobArtifacts()')
        ->and(fn () => TransferAuthorizationNeed::resolve(Tmesis::node()->jobArtifacts(), TransferNode::put()->toEvent(), 'n'))
        ->toThrow(InvalidConfigurationException::class, 'artifacts()');

    Http::assertNothingSent();
});
