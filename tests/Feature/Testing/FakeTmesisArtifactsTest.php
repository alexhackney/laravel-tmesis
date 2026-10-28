<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InitialAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Data\Webhooks\ArtifactIdentity;
use AlexHackney\Tmesis\Exceptions\BadRequestException;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

const FAKE_ART_STREAM = 'show-2041-main';

const FAKE_ART_GENERATION = '01J8ZCK5R7V2QNX8RW4TKB6PD2';

const FAKE_ART_ABANDONED = '01J8ZCK5R7V2QNX8RW4TKB6PZZ';

const FAKE_ART_PRIMARY = '01J8ZCK7T4N2QWX6VM3RKB9PD8';

const FAKE_ART_BACKUP = '01J8ZCK8T4N2QWX6VM3RKB9PD8';

const FAKE_ART_SECRET_URL = 'https://archive.example.net/backup/vod.m3u8?X-Amz-Credential=AKIAEXAMPLE&X-Amz-Signature=deadbeefsig';

function fakeArtifacts(): Artifacts
{
    return Tmesis::node()->artifacts();
}

/**
 * The fake's answer as the node would put it on the wire, validated against its
 * openapi component.
 */
function assertFakeArtifactValid(object $dto, string $component): void
{
    /** @var array<string, mixed> $raw */
    $raw = $dto->toArray();

    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode($raw), false),
        "/components/schemas/{$component}",
        "The fake's {$component}",
    );
}

function fakeArtifactConflict(Closure $call): ConflictException
{
    try {
        $call();
    } catch (ConflictException $e) {
        return $e;
    }

    throw new RuntimeException('expected a ConflictException');
}

function fakeArtifactSha(): string
{
    return (string) fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION)->inventorySha256();
}

function fakeArtifactPlan(int $expected = 0, ?string $requestId = null): PlanRequest
{
    return PlanRequest::make(fakeArtifactSha(), $expected, $requestId)
        ->withTarget(PlanTarget::hlsSealed('csn-vod', 'csn/2026/show', 'playlist.m3u8', FAKE_ART_PRIMARY));
}

function fakeArtifactSigned(array $authorizations = []): SignedDestination
{
    return SignedDestination::make('us-east-1', 'backup', 'https://archive.example.net', 'path', 16, 4, $authorizations);
}

function fakeArtifactPut(string $method = 'PUT'): AuthorizationBundle
{
    return AuthorizationBundle::make(put: SignedRequest::make($method, FAKE_ART_SECRET_URL, ['x-amz-security-token' => 'hdr-secret-token'], '2026-09-26T12:00:00Z'));
}

beforeEach(function (): void {
    $this->fake = Tmesis::fake()
        ->artifactFinalized(FAKE_ART_STREAM, FAKE_ART_GENERATION, labels: ['show_id' => '2041'])
        ->artifactAbandoned(FAKE_ART_STREAM, FAKE_ART_ABANDONED);
});

it('seeds a finalized generation every read answers with a valid body', function (): void {
    Http::fake();

    $artifact = fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION);

    expect($artifact->isFinalized())->toBeTrue()
        ->and($artifact->kind())->toBe(ArtifactStatus::KIND_HLS_SEALED)
        ->and($artifact->inventorySha256())->toMatch('/^[0-9a-f]{64}$/')
        ->and($artifact->objectCount())->toBe(5)
        ->and($artifact->planRevision())->toBe(0)
        ->and($artifact->releaseState())->toBe(ArtifactStatus::RELEASE_UNRELEASED)
        ->and($artifact->holdReasons())->toBe(['retention_not_elapsed', 'release_required']);

    $plan = fakeArtifacts()->getTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION);

    expect($plan->revision())->toBe(0)
        ->and($plan->requestId())->toBe('baseline')
        ->and($plan->current())->toBeTrue()
        ->and($plan->targets())->toBe([]);

    assertFakeArtifactValid($artifact, 'ArtifactStatus');
    assertFakeArtifactValid($plan, 'PlanStatus');
    assertFakeArtifactValid(fakeArtifacts()->listArtifacts(FAKE_ART_STREAM), 'ArtifactPage');
    assertFakeArtifactValid(fakeArtifacts()->getArtifactInventory(FAKE_ART_STREAM, FAKE_ART_GENERATION), 'InventoryPage');
    assertFakeArtifactValid(fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED), 'ArtifactStatus');

    expect(fakeArtifacts()->listArtifacts(FAKE_ART_STREAM)->items())->toHaveCount(2)
        ->and(fakeArtifacts()->listArtifacts('another-stream')->items())->toBe([]);

    Http::assertNothingSent();
});

it('seeds a recording with its own inventory and a given inventory hash', function (): void {
    $this->fake->artifactFinalized(
        FAKE_ART_STREAM,
        '01J8ZCK5R7V2QNX8RW4TKB6PR1',
        ArtifactStatus::KIND_RECORDING_INGEST,
        [['path' => 'recording.ts', 'size_bytes' => 4096, 'publish_order' => 0]],
        str_repeat('ab', 32),
    );

    $artifact = fakeArtifacts()->getArtifact(FAKE_ART_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PR1');
    $entries = fakeArtifacts()->allArtifactInventory(FAKE_ART_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PR1')->all();

    expect($artifact->inventorySha256())->toBe(str_repeat('ab', 32))
        ->and($artifact->recordingId())->toBe('01J8ZCK5R7V2QNX8RW4TKB6PR1')
        ->and($artifact->registeredAt())->not->toBeNull()
        ->and($artifact->sizeBytes())->toBe(4096)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->contentType())->toBe('video/mp2t');

    assertFakeArtifactValid($artifact, 'ArtifactStatus');

    $plan = fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PR1', PlanRequest::make(str_repeat('ab', 32), 0)
        ->withTarget(PlanTarget::recordingIngest('csn-vod', 'csn/2026/show/show-ingest.ts')));

    expect(fakeArtifacts()->listTransfers(FAKE_ART_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PR1')->items()[0]->objectKey())
        ->toBe('csn/2026/show/show-ingest.ts')
        ->and($plan->targets()[0]->masterName())->toBeNull();

    assertFakeArtifactValid($plan, 'PlanStatus');
});

it('refuses a kind the node does not have', function (): void {
    expect(fn () => $this->fake->artifactFinalized(FAKE_ART_STREAM, 'x', 'hls_live'))
        ->toThrow(InvalidConfigurationException::class);
});

it('creates revision 1 with a 201 and a later revision with a 200', function (): void {
    $first = fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());

    expect($first->created)->toBeTrue()
        ->and($first->revision())->toBe(1)
        ->and($first->current())->toBeTrue()
        ->and($first->counts()->queued())->toBe(5)
        ->and($first->targets()[0]->authorization())->toBe('configured')
        ->and($first->targets()[0]->masterName())->toBe('playlist.m3u8')
        ->and($first->targets()[0]->counts()->total())->toBe(5);

    $keys = array_map(fn (ObjectStatus $object): string => $object->objectKey(), fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items());

    // The told prefix joined to each path, the master renamed: the node inserts nothing.
    expect($keys)->toContain('csn/2026/show/v1/seg_00001.m4s')
        ->and($keys)->toContain('csn/2026/show/playlist.m3u8')
        ->and($keys)->not->toContain('csn/2026/show/vod.m3u8');

    $second = fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan(1));

    expect($second->created)->toBeFalse()
        ->and($second->revision())->toBe(2)
        ->and(fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION)->planRevision())->toBe(2)
        ->and(fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION)->holdReasons())
        ->toBe(['retention_not_elapsed', 'copies_incomplete', 'release_required']);

    assertFakeArtifactValid($first, 'PlanStatus');
    assertFakeArtifactValid(fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION), 'TransferPage');
});

it('answers an exact retry with the stored revision and a truthful current', function (): void {
    $plan = fakeArtifactPlan();

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, $plan);
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan(1));

    $retry = fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, $plan);

    expect($retry->created)->toBeFalse()
        ->and($retry->revision())->toBe(1)
        ->and($retry->current())->toBeFalse()
        ->and(fakeArtifacts()->getTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION)->revision())->toBe(2);
});

it('refuses the same request id with another body as request_conflict', function (): void {
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan(requestId: '01J8ZCK6R3M8QWX2VN5TKB7PD4'));

    $e = fakeArtifactConflict(fn () => fakeArtifacts()->replaceTransferPlan(
        FAKE_ART_STREAM,
        FAKE_ART_GENERATION,
        fakeArtifactPlan(requestId: '01J8ZCK6R3M8QWX2VN5TKB7PD4')->withReason('another body'),
    ));

    expect($e->code())->toBe('request_conflict')
        ->and($e->status())->toBe(409);
});

it('refuses a plan against a revision that is not current as transfer_plan_stale', function (): void {
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan(0)))->code())
        ->toBe('transfer_plan_stale');
});

it('refuses another inventory as artifact_identity_mismatch', function (): void {
    $plan = PlanRequest::make(str_repeat('0', 64), 0);

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, $plan))->code())
        ->toBe('artifact_identity_mismatch');
});

it('refuses a plan and the finalized reads on an unfinalized generation', function (Closure $call): void {
    expect(fakeArtifactConflict($call)->code())->toBe('artifact_not_finalized');
})->with([
    'plan' => [fn () => fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_ABANDONED, PlanRequest::make(str_repeat('0', 64), 0))],
    'inventory' => [fn () => fakeArtifacts()->getArtifactInventory(FAKE_ART_STREAM, FAKE_ART_ABANDONED)],
    'transfer plan' => [fn () => fakeArtifacts()->getTransferPlan(FAKE_ART_STREAM, FAKE_ART_ABANDONED)],
    'transfers' => [fn () => fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_ABANDONED)],
]);

it('answers a generation under another stream with 404, never a 409', function (Closure $call): void {
    expect($call)->toThrow(NotFoundException::class);
})->with([
    'read' => [fn () => fakeArtifacts()->getArtifact('another-stream', FAKE_ART_GENERATION)],
    'unknown' => [fn () => fakeArtifacts()->getArtifact(FAKE_ART_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6P00')],
    'plan' => [fn () => fakeArtifacts()->replaceTransferPlan('another-stream', FAKE_ART_GENERATION, PlanRequest::make(str_repeat('0', 64), 0))],
    'release' => [fn () => fakeArtifacts()->releaseArtifact('another-stream', FAKE_ART_GENERATION, ReleaseRequest::make(0, str_repeat('0', 64), 'done'))],
]);

it('refuses a target of another kind than the generation with a 422', function (): void {
    $plan = PlanRequest::make(fakeArtifactSha(), 0)->withTarget(PlanTarget::recordingIngest('csn-vod', 'x.ts'));

    try {
        fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, $plan);
        $this->fail('a kind mismatch was accepted');
    } catch (ValidationException $e) {
        expect($e->details()[0]->path())->toBe('targets[0].artifact');
    }
});

it('refuses a release until every target is complete, then records it once', function (): void {
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());

    $release = ReleaseRequest::make(1, fakeArtifactSha(), 'All requested copies verified');

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, $release))->code())
        ->toBe('transfer_incomplete');

    $this->fake->completeTransfer(FAKE_ART_STREAM, FAKE_ART_GENERATION, FAKE_ART_PRIMARY);

    $plan = fakeArtifacts()->getTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION);
    $object = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items()[0];

    expect($plan->counts()->complete())->toBe(5)
        ->and($plan->counts()->allComplete())->toBeTrue()
        ->and($object->state())->toBe(ObjectStatus::STATE_COMPLETE)
        ->and($object->completedAt())->not->toBeNull();

    $first = fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, $release);
    $again = fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, $release);

    expect($first->recorded)->toBeTrue()
        ->and($first->action())->toBe(DecisionStatus::ACTION_RELEASE)
        ->and($first->releaseState())->toBe(ArtifactStatus::RELEASE_RELEASED)
        ->and($first->current())->toBeTrue()
        ->and($again->recorded)->toBeFalse()
        ->and($again->requestId())->toBe($release->requestId());

    $artifact = fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION);

    expect($artifact->releaseState())->toBe(ArtifactStatus::RELEASE_RELEASED)
        ->and($artifact->holdReasons())->toBe(['retention_not_elapsed']);

    assertFakeArtifactValid($first, 'DecisionStatus');
    assertFakeArtifactValid($object, 'ObjectStatus');
    assertFakeArtifactValid($artifact, 'ArtifactStatus');

    $this->fake->assertReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION, DecisionStatus::ACTION_RELEASE);
});

it('releases an empty plan explicitly', function (): void {
    $decision = fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(0, fakeArtifactSha(), 'nothing to copy'));

    expect($decision->recorded)->toBeTrue()
        ->and($decision->planRevision())->toBe(0);
});

it('refuses the decisions the journal refuses, with the node\'s code', function (): void {
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());
    $this->fake->completeTransfer(FAKE_ART_STREAM, FAKE_ART_GENERATION, FAKE_ART_PRIMARY);
    $sha = fakeArtifactSha();

    $stale = fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(0, $sha, 'baseline'));
    $identity = fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(1, str_repeat('0', 64), 'wrong'));

    expect(fakeArtifactConflict($stale)->code())->toBe('transfer_plan_stale')
        ->and(fakeArtifactConflict($identity)->code())->toBe('artifact_identity_mismatch');

    fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(1, $sha, 'done', '01J8ZCKAT4N2QWX6VM3RKB9PD8'));

    $conflict = fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(1, $sha, 'changed', '01J8ZCKAT4N2QWX6VM3RKB9PD8'));
    $second = fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(1, $sha, 'done again'));
    // Release and override share one idempotency family.
    $family = fn () => fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, OverrideRequest::override(1, $sha, 'done', [], '01J8ZCKAT4N2QWX6VM3RKB9PD8'));

    expect(fakeArtifactConflict($conflict)->code())->toBe('request_conflict')
        ->and(fakeArtifactConflict($second)->code())->toBe('release_conflict')
        ->and(fakeArtifactConflict($family)->code())->toBe('request_conflict');
});

it('lets an override waive exactly the incomplete targets', function (): void {
    $sha = fakeArtifactSha();

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, PlanRequest::make($sha, 0)->withTargets(
        PlanTarget::hlsSealed('csn-vod', 'csn/2026/show', null, FAKE_ART_PRIMARY),
        PlanTarget::hlsSealed('csn-backup', 'backup/show', null, FAKE_ART_BACKUP),
    ));

    $this->fake->completeTransfer(FAKE_ART_STREAM, FAKE_ART_GENERATION, FAKE_ART_PRIMARY)
        ->failTransfer(FAKE_ART_STREAM, FAKE_ART_GENERATION, FAKE_ART_BACKUP);

    $failed = collect(fakeArtifacts()->allTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->all())
        ->first(fn (ObjectStatus $object): bool => $object->targetId() === FAKE_ART_BACKUP);

    expect($failed->state())->toBe(ObjectStatus::STATE_FAILED)
        ->and($failed->error()?->code())->toBe('attempts_exhausted')
        ->and(fakeArtifactConflict(fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(1, $sha, 'x')))->code())
        ->toBe('transfer_incomplete');

    assertFakeArtifactValid($failed, 'ObjectStatus');

    $refused = [
        'omits the incomplete target' => OverrideRequest::override(1, $sha, 'x', []),
        'names an unknown target' => OverrideRequest::override(1, $sha, 'x', [FAKE_ART_BACKUP, '01J8ZCK8T4N2QWX6VM3RKB9P00']),
        'names a target twice' => OverrideRequest::override(1, $sha, 'x', [FAKE_ART_BACKUP, FAKE_ART_BACKUP]),
        'a discard that omits a complete target' => OverrideRequest::discard(1, $sha, 'x', [FAKE_ART_BACKUP]),
    ];

    foreach ($refused as $override) {
        expect(fn () => fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, $override))
            ->toThrow(ValidationException::class);
    }

    $decision = fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, OverrideRequest::override(1, $sha, 'backup waived', [FAKE_ART_BACKUP]));

    expect($decision->recorded)->toBeTrue()
        ->and($decision->action())->toBe(DecisionStatus::ACTION_OVERRIDE)
        ->and($decision->waivedTargets())->toBe([FAKE_ART_BACKUP])
        ->and($decision->releaseState())->toBe(ArtifactStatus::RELEASE_OVERRIDDEN)
        ->and(fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION)->releaseState())->toBe(ArtifactStatus::RELEASE_OVERRIDDEN);

    $this->fake->assertReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION)
        ->assertReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION, DecisionStatus::ACTION_OVERRIDE);
});

it('discards every target, completed ones included', function (): void {
    $sha = fakeArtifactSha();
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());

    $decision = fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, OverrideRequest::discard(1, $sha, 'show cancelled', [FAKE_ART_PRIMARY]));

    expect($decision->action())->toBe(DecisionStatus::ACTION_DISCARD)
        ->and($decision->releaseState())->toBe(ArtifactStatus::RELEASE_OVERRIDDEN);
});

it('makes a released generation unreleased again when a later plan replaces the revision', function (): void {
    $sha = fakeArtifactSha();
    $release = ReleaseRequest::make(0, $sha, 'nothing to copy');

    fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, $release);
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan(0));

    $retry = fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, $release);

    expect(fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION)->releaseState())->toBe(ArtifactStatus::RELEASE_UNRELEASED)
        ->and($retry->recorded)->toBeFalse()
        ->and($retry->current())->toBeFalse();

    $this->fake->assertNotReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION);
});

it('discards an abandoned generation with the exact shape only', function (): void {
    $release = fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED, ReleaseRequest::make(0, str_repeat('0', 64), 'x'));
    $override = fn () => fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED, OverrideRequest::override(0, str_repeat('0', 64), 'x', []));
    $discard = fn () => fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED, OverrideRequest::discard(0, str_repeat('0', 64), 'x', []));

    expect(fakeArtifactConflict($release)->code())->toBe('artifact_not_finalized')
        ->and(fakeArtifactConflict($override)->code())->toBe('artifact_not_finalized')
        ->and(fakeArtifactConflict($discard)->code())->toBe('artifact_not_finalized');

    $abandoned = OverrideRequest::discardAbandoned('encoder came back with another shape');

    $first = fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED, $abandoned);
    $again = fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED, $abandoned);

    expect($first->recorded)->toBeTrue()
        ->and($first->planRevision())->toBe(0)
        ->and($first->action())->toBe(DecisionStatus::ACTION_DISCARD)
        ->and($first->releaseState())->toBe(ArtifactStatus::RELEASE_OVERRIDDEN)
        ->and($first->retentionExpiresAt())->not->toBeNull()
        ->and($again->recorded)->toBeFalse();

    $artifact = fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED);

    expect($artifact->state())->toBe(ArtifactStatus::STATE_HELD)
        ->and($artifact->releaseState())->toBe(ArtifactStatus::RELEASE_OVERRIDDEN)
        ->and($artifact->holdReasons())->toBe(['not_finalized', 'retention_not_elapsed']);

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED, OverrideRequest::discardAbandoned('twice')))->code())
        ->toBe('release_conflict');

    assertFakeArtifactValid($first, 'DecisionStatus');
    assertFakeArtifactValid($artifact, 'ArtifactStatus');

    $this->fake->assertReleased(FAKE_ART_STREAM, FAKE_ART_ABANDONED, DecisionStatus::ACTION_DISCARD);
});

it('refuses the abandoned shape on a finalized generation, whose inventory it does not name', function (): void {
    expect(fakeArtifactConflict(fn () => fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, OverrideRequest::discardAbandoned('x')))->code())
        ->toBe('artifact_identity_mismatch');
});

it('holds a signed target\'s objects for authorization and takes a refresh by revision', function (): void {
    $sha = fakeArtifactSha();

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, PlanRequest::make($sha, 0)
        ->withTarget(PlanTarget::hlsSealed(fakeArtifactSigned(), 'backup/show', null, FAKE_ART_BACKUP)));

    $plan = fakeArtifacts()->getTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION);
    $object = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items()[0];

    expect($plan->targets()[0]->authorization())->toBe('signed')
        ->and($plan->targets()[0]->credentialProfile())->toBeNull()
        ->and($plan->targets()[0]->uploadConcurrency())->toBe(4)
        ->and($plan->targets()[0]->partSizeBytes())->toBe(16 * 1048576)
        ->and($plan->counts()->waitingAuthorization())->toBe(5)
        ->and($object->state())->toBe(ObjectStatus::STATE_WAITING_AUTHORIZATION)
        ->and($object->authorizationRequired())->toBe(['put'])
        ->and($object->error()?->code())->toBe('authorization_missing');

    assertFakeArtifactValid($plan, 'PlanStatus');

    $refresh = AuthorizationRequest::nextFor($object, $sha, fakeArtifactPut());
    $accepted = fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $refresh);

    expect($accepted->state())->toBe(ObjectStatus::STATE_QUEUED)
        ->and($accepted->authorizationRevision())->toBe(1)
        ->and($accepted->authorizationAvailable())->toBeTrue()
        ->and($accepted->error())->toBeNull()
        ->and(fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $refresh)->authorizationRevision())
        ->toBe(1);

    assertFakeArtifactValid($accepted, 'ObjectStatus');

    $changed = AuthorizationRequest::nextFor($object, $sha, fakeArtifactPut('POST'));
    $skipped = AuthorizationRequest::make(1, $sha, FAKE_ART_BACKUP, $object->objectKey(), 3, null, fakeArtifactPut());
    $wrongKey = AuthorizationRequest::make(1, $sha, FAKE_ART_BACKUP, 'elsewhere.m4s', 2, null, fakeArtifactPut());

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $changed))->code())
        ->toBe('request_conflict')
        ->and(fakeArtifactConflict(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $skipped))->code())
        ->toBe('transfer_identity_mismatch')
        ->and(fakeArtifactConflict(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $wrongKey))->code())
        ->toBe('transfer_identity_mismatch');

    expect(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, '01J8ZCK9T4N2QWX6VM3RKB9P00', $refresh))
        ->toThrow(NotFoundException::class);
});

it('refuses a refresh naming another plan revision or inventory as transfer_identity_mismatch, as the node does', function (): void {
    $sha = fakeArtifactSha();

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, PlanRequest::make($sha, 0)
        ->withTarget(PlanTarget::hlsSealed(fakeArtifactSigned(), 'backup/show', null, FAKE_ART_BACKUP)));

    $object = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items()[0];

    $otherRevision = AuthorizationRequest::make(2, $sha, FAKE_ART_BACKUP, $object->objectKey(), 1, null, fakeArtifactPut());
    $otherInventory = AuthorizationRequest::make(1, str_repeat('0', 64), FAKE_ART_BACKUP, $object->objectKey(), 1, null, fakeArtifactPut());

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $otherRevision))->code())
        ->toBe('transfer_identity_mismatch')
        ->and(fakeArtifactConflict(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $otherInventory))->code())
        ->toBe('transfer_identity_mismatch');
});

it('refuses a binding refresh for an object of a replaced revision as transfer_plan_stale', function (): void {
    $sha = fakeArtifactSha();
    $signed = fn (string $id): PlanTarget => PlanTarget::hlsSealed(fakeArtifactSigned(), 'backup/show', null, $id);

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, PlanRequest::make($sha, 0)->withTarget($signed(FAKE_ART_BACKUP)));
    $object = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items()[0];

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, PlanRequest::make($sha, 1)->withTarget($signed(FAKE_ART_BACKUP)));

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), AuthorizationRequest::nextFor($object, $sha, fakeArtifactPut())))->code())
        ->toBe('transfer_plan_stale');
});

it('refuses a refresh for a configured target', function (): void {
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());
    $object = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items()[0];

    $refresh = AuthorizationRequest::nextFor($object, fakeArtifactSha(), fakeArtifactPut());

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), $refresh))->code())
        ->toBe('transfer_identity_mismatch');
});

it('installs initial authorizations and refuses one that does not bind', function (): void {
    $sha = fakeArtifactSha();
    $initial = fn (string $path, string $key): InitialAuthorization => InitialAuthorization::make($path, $key, fakeArtifactPut());
    $plan = fn (InitialAuthorization $authorization): PlanRequest => PlanRequest::make($sha, 0)
        ->withTarget(PlanTarget::hlsSealed(fakeArtifactSigned([$authorization]), 'backup/show', null, FAKE_ART_BACKUP));

    expect(fn () => fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, $plan($initial('not/in/inventory.m4s', 'backup/show/x.m4s'))))
        ->toThrow(ValidationException::class)
        ->and(fakeArtifactConflict(fn () => fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, $plan($initial('v1/init.mp4', 'elsewhere/init.mp4'))))->code())
        ->toBe('transfer_identity_mismatch')
        // A refused plan commits nothing.
        ->and(fakeArtifacts()->getTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION)->revision())->toBe(0);

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, $plan($initial('v1/init.mp4', 'backup/show/v1/init.mp4')));

    $init = collect(fakeArtifacts()->allTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->all())
        ->first(fn (ObjectStatus $object): bool => $object->artifactPath() === 'v1/init.mp4');

    expect($init->state())->toBe(ObjectStatus::STATE_QUEUED)
        ->and($init->authorizationRevision())->toBe(1)
        ->and($init->authorizationAvailable())->toBeTrue()
        ->and(fakeArtifacts()->getTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION)->counts()->waitingAuthorization())->toBe(4);
});

it('never records a signed URL or header value', function (): void {
    $sha = fakeArtifactSha();

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, PlanRequest::make($sha, 0)->withTarget(PlanTarget::hlsSealed(
        fakeArtifactSigned([InitialAuthorization::make('v1/init.mp4', 'backup/show/v1/init.mp4', fakeArtifactPut())]),
        'backup/show',
        null,
        FAKE_ART_BACKUP,
    )));

    $object = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items()[1];
    fakeArtifacts()->supplyTransferAuthorization(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id(), AuthorizationRequest::nextFor($object, $sha, fakeArtifactPut()));

    $log = (string) json_encode($this->fake->calls());

    foreach (['AKIAEXAMPLE', 'deadbeefsig', 'hdr-secret-token', 'X-Amz'] as $secret) {
        expect($log)->not->toContain($secret);
    }

    $this->fake->assertTransferPlanPut(FAKE_ART_STREAM, FAKE_ART_GENERATION, fn (array $plan): bool => $plan['targets'][0]['signed']['authorizations'][0]['supplied'] === ['put'])
        ->assertCalled('supplyTransferAuthorization', fn (array $args): bool => $args['supplied'] === ['put']);
});

it('walks pages behind cursors bound to their scope and revision', function (): void {
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());

    $page = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION, 2);

    expect($page->items())->toHaveCount(2)
        ->and($page->limit())->toBe(2)
        ->and($page->nextCursor())->not->toBeNull()
        ->and(fakeArtifacts()->allTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION, 2)->count())->toBe(5)
        ->and(fakeArtifacts()->listArtifacts(FAKE_ART_STREAM, 5000)->limit())->toBe(1000);

    expect(fn () => fakeArtifacts()->getArtifactInventory(FAKE_ART_STREAM, FAKE_ART_GENERATION, 2, $page->nextCursor()))
        ->toThrow(BadRequestException::class);

    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan(1));

    expect(fakeArtifactConflict(fn () => fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION, 2, $page->nextCursor()))->code())
        ->toBe('transfer_plan_stale')
        ->and(fn () => fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION, 0))
        ->toThrow(InvalidConfigurationException::class);
});

it('models no multipart: every object has an empty parts page', function (): void {
    fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan());
    $object = fakeArtifacts()->listTransfers(FAKE_ART_STREAM, FAKE_ART_GENERATION)->items()[0];

    $parts = fakeArtifacts()->listTransferParts(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id());

    expect($parts->items())->toBe([])
        ->and(fakeArtifacts()->allTransferParts(FAKE_ART_STREAM, FAKE_ART_GENERATION, $object->id())->all())->toBe([])
        ->and(fn () => fakeArtifacts()->listTransferParts(FAKE_ART_STREAM, FAKE_ART_GENERATION, 'no-such-object'))
        ->toThrow(NotFoundException::class);

    assertFakeArtifactValid($parts, 'PartPage');
});

it('throws what throwOn() names, after recording the call', function (): void {
    $this->fake->throwOn('releaseArtifact', new ConflictException('down'));

    expect(fn () => fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(0, fakeArtifactSha(), 'x')))
        ->toThrow(ConflictException::class, 'down');

    $this->fake->assertCalledCount('releaseArtifact', 1)
        ->assertNotReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION);
});

it('passes and fails the release assertions on the ledger\'s state', function (): void {
    $fake = $this->fake;

    expect(fn () => $fake->assertReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION))->toThrow(AssertionFailedError::class, 'was not released')
        ->and(fn () => $fake->assertEveryFinalizedGenerationReleased())->toThrow(AssertionFailedError::class, FAKE_ART_STREAM.'/'.FAKE_ART_GENERATION)
        ->and(fn () => $fake->assertReleased(FAKE_ART_STREAM, 'never-seeded'))->toThrow(AssertionFailedError::class, 'was seeded');

    $fake->assertNotReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION)
        // Another stream's generations are not this stream's obligation.
        ->assertEveryFinalizedGenerationReleased('another-stream');

    fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(0, fakeArtifactSha(), 'nothing to copy'));

    // The abandoned generation is not finalized, so it is not part of the obligation.
    $fake->assertReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION)
        ->assertEveryFinalizedGenerationReleased()
        ->assertEveryFinalizedGenerationReleased(FAKE_ART_STREAM);

    expect(fn () => $fake->assertNotReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION))->toThrow(AssertionFailedError::class, 'was released')
        ->and(fn () => $fake->assertReleased(FAKE_ART_STREAM, FAKE_ART_GENERATION, DecisionStatus::ACTION_OVERRIDE))->toThrow(AssertionFailedError::class, 'not [override]');
});

it('passes and fails assertEveryGenerationDecided, abandoned generations included', function (): void {
    $fake = $this->fake;

    expect(fn () => $fake->assertEveryGenerationDecided())->toThrow(AssertionFailedError::class, FAKE_ART_STREAM.'/'.FAKE_ART_GENERATION.' (finalized, not released)');

    fakeArtifacts()->releaseArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION, ReleaseRequest::make(0, fakeArtifactSha(), 'nothing to copy'));

    // Every finalized generation is released, and the abandoned one still fails this.
    $fake->assertEveryFinalizedGenerationReleased()
        ->assertEveryGenerationDecided('another-stream');

    expect(fn () => $fake->assertEveryGenerationDecided(FAKE_ART_STREAM))->toThrow(AssertionFailedError::class, FAKE_ART_STREAM.'/'.FAKE_ART_ABANDONED.' (abandoned, not discarded)');

    fakeArtifacts()->overrideArtifact(FAKE_ART_STREAM, FAKE_ART_ABANDONED, OverrideRequest::discardAbandoned('crashed window'));

    $fake->assertEveryGenerationDecided()
        ->assertEveryGenerationDecided(FAKE_ART_STREAM);
});

it('passes and fails assertTransferPlanPut', function (): void {
    $fake = $this->fake;

    expect(fn () => $fake->assertTransferPlanPut(FAKE_ART_STREAM, FAKE_ART_GENERATION))->toThrow(AssertionFailedError::class);

    // Refused plans are still puts: the assertion reads the call log.
    try {
        fakeArtifacts()->replaceTransferPlan(FAKE_ART_STREAM, FAKE_ART_GENERATION, fakeArtifactPlan(3));
    } catch (TmesisException) {
    }

    $fake->assertTransferPlanPut(FAKE_ART_STREAM, FAKE_ART_GENERATION)
        ->assertTransferPlanPut(FAKE_ART_STREAM, FAKE_ART_GENERATION, fn (array $plan): bool => $plan['expected_revision'] === 3
            && $plan['targets'][0]['prefix'] === 'csn/2026/show');

    expect(fn () => $fake->assertTransferPlanPut(FAKE_ART_STREAM, FAKE_ART_GENERATION, fn (array $plan): bool => $plan['expected_revision'] === 0))
        ->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertTransferPlanPut('another-stream', FAKE_ART_GENERATION))
        ->toThrow(AssertionFailedError::class);
});

it('hands out the identity block an artifact webhook carries', function (): void {
    $identity = $this->fake->artifactIdentity(FAKE_ART_STREAM, FAKE_ART_GENERATION);
    $read = ArtifactIdentity::fromArray($identity);

    expect(array_keys($identity))->toBe(['stream', 'session', 'session_epoch', 'job_id', 'generation', 'kind', 'inventory_sha256', 'labels'])
        ->and($identity['job_id'])->toBeNull()
        ->and($identity['labels'])->toBe(['show_id' => '2041'])
        ->and($identity['inventory_sha256'])->toBe(fakeArtifactSha())
        ->and($read->stream())->toBe(FAKE_ART_STREAM)
        ->and($read->session())->toBe(fakeArtifacts()->getArtifact(FAKE_ART_STREAM, FAKE_ART_GENERATION)->session())
        ->and($this->fake->artifactIdentity(FAKE_ART_STREAM, FAKE_ART_ABANDONED)['inventory_sha256'])->toBe('');
});

it('refuses a completion toggle for a target that is not on the current revision', function (): void {
    expect(fn () => $this->fake->completeTransfer(FAKE_ART_STREAM, FAKE_ART_GENERATION, FAKE_ART_PRIMARY))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $this->fake->completeTransfer(FAKE_ART_STREAM, FAKE_ART_ABANDONED, FAKE_ART_PRIMARY))
        ->toThrow(InvalidConfigurationException::class);
});
