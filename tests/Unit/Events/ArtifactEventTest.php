<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Webhooks\ArtifactIdentity;
use AlexHackney\Tmesis\Events\ArtifactDeleted;
use AlexHackney\Tmesis\Events\ArtifactEvent;
use AlexHackney\Tmesis\Events\ArtifactFinalized;
use AlexHackney\Tmesis\Events\ArtifactReleased;
use AlexHackney\Tmesis\Events\RecordingRegistered;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Events\TransferCompleted;
use AlexHackney\Tmesis\Events\TransferFailed;
use AlexHackney\Tmesis\Keys\SessionEpoch;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * The generated example at the resolved contract root.
 *
 * @return array<string, mixed>
 */
function artifactExample(string $event): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(ContractDir::example($event)), true, 512, JSON_THROW_ON_ERROR);
}

it('reads the shared identity block the same way on all six events', function (string $event, string $class): void {
    $instance = EventMap::make(artifactExample($event));

    expect($instance)->toBeInstanceOf($class)->toBeInstanceOf(ArtifactEvent::class);

    /** @var ArtifactEvent $instance */
    $identity = $instance->identity();

    expect($identity)->toBeInstanceOf(ArtifactIdentity::class)
        ->and($identity->stream())->toBe('show-2041-main')
        ->and($identity->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($identity->sessionEpoch())->toBeInstanceOf(SessionEpoch::class)
        ->and($identity->sessionEpoch()->value)->toBe(3)
        ->and($identity->generation())->toBe('01J8ZCK5R7V2QNX8RW4TKB6PD2')
        ->and($identity->kind())->toBe(ArtifactIdentity::KIND_HLS_SEALED)
        ->and($identity->isRecording())->toBeFalse()
        ->and($identity->inventorySha256())->toBe('9f2c1d5e6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f')
        ->and($identity->labels())->toBe(['show_id' => '2041', 'site' => 'csn'])
        ->and($identity->jobId())->toBeNull()
        ->and($identity->isJobGeneration())->toBeFalse()
        ->and(array_keys($identity->toArray()))->toBe(['stream', 'session', 'session_epoch', 'job_id', 'generation', 'kind', 'inventory_sha256', 'labels'])
        ->and($instance->generation())->toBe($identity->generation())
        ->and($instance->jobId())->toBeNull()
        ->and($instance->stream())->toBe($identity->stream());
})->with([
    'artifact.finalized' => ['artifact.finalized', ArtifactFinalized::class],
    'artifact.released' => ['artifact.released', ArtifactReleased::class],
    'artifact.deleted' => ['artifact.deleted', ArtifactDeleted::class],
    'transfer.completed' => ['transfer.completed', TransferCompleted::class],
    'transfer.failed' => ['transfer.failed', TransferFailed::class],
    'transfer.authorization_required' => ['transfer.authorization_required', TransferAuthorizationRequired::class],
]);

it('reads a JOB generation on all six events: no stream, session or epoch, and the job instead (v0.6.0)', function (string $event): void {
    $payload = array_replace(artifactExample($event), [
        'stream' => null,
        'session' => null,
        'session_epoch' => null,
        'job_id' => 'job_01J8ZCA2R7F3H9NQ4KTX8VD5YB',
        'kind' => 'job_hls',
        'labels' => ['video_id' => '941'],
    ]);

    /** @var ArtifactEvent $instance */
    $instance = EventMap::make($payload);
    $identity = $instance->identity();

    expect($identity->stream())->toBeNull()
        ->and($identity->session())->toBeNull()
        ->and($identity->sessionEpoch())->toBeNull()
        ->and($identity->jobId())->toBe('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB')
        ->and($identity->isJobGeneration())->toBeTrue()
        ->and($identity->isRecording())->toBeFalse()
        ->and($identity->labels())->toBe(['video_id' => '941'])
        ->and($instance->stream())->toBeNull()
        ->and($instance->jobId())->toBe('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB');

    $validator = SchemaValidator::forSchema($event);
    $validator->assertValid(json_decode((string) json_encode($payload), false), null, "{$event} as a job generation");
})->with(['artifact.finalized', 'artifact.released', 'artifact.deleted', 'transfer.completed', 'transfer.failed', 'transfer.authorization_required']);

it('tells a job generation by its kind alone', function (): void {
    expect(ArtifactIdentity::fromArray(['kind' => 'job_probe'])->isJobGeneration())->toBeTrue()
        ->and(ArtifactIdentity::fromArray(['kind' => 'hls_sealed'])->isJobGeneration())->toBeFalse();
});

it('reads a recording generation\'s kind', function (): void {
    $identity = ArtifactIdentity::fromArray(['kind' => 'recording_ingest', 'session_epoch' => 0]);

    expect($identity->isRecording())->toBeTrue()
        ->and($identity->sessionEpoch()->value)->toBe(0)
        ->and($identity->labels())->toBe([])
        ->and($identity->generation())->toBe('');
});

it('reads artifact.finalized', function (): void {
    /** @var ArtifactFinalized $event */
    $event = EventMap::make(artifactExample('artifact.finalized'));

    expect($event->recordingId())->toBeNull()
        ->and($event->objectCount())->toBe(1146)
        ->and($event->sizeBytes())->toBe(19204817408)
        ->and($event->finalizedAtRaw())->toBe('2026-08-28T21:45:00Z')
        ->and($event->finalizedAt()->format(DATE_ATOM))->toBe('2026-08-28T21:45:00+00:00')
        ->and($event->registeredAtRaw())->toBeNull()
        ->and($event->registeredAt())->toBeNull();
});

it('reads a recording generation finalized and registered in the same instant', function (): void {
    $payload = array_replace(artifactExample('artifact.finalized'), [
        'kind' => 'recording_ingest',
        'recording_id' => '01J8ZCK5M2V8QNX4RW7TKB3PD9',
        'registered_at' => '2026-08-28T21:45:00Z',
    ]);

    /** @var ArtifactFinalized $event */
    $event = EventMap::make($payload);

    expect($event->recordingId())->toBe('01J8ZCK5M2V8QNX4RW7TKB3PD9')
        ->and($event->identity()->isRecording())->toBeTrue()
        ->and($event->registeredAtRaw())->toBe('2026-08-28T21:45:00Z')
        ->and($event->registeredAt()?->format('Y-m-d'))->toBe('2026-08-28');
});

it('reads artifact.released', function (): void {
    /** @var ArtifactReleased $event */
    $event = EventMap::make(artifactExample('artifact.released'));

    expect($event->planRevision())->toBe(2)
        ->and($event->requestId())->toBe('01J8ZCKBT4N2QWX6VM3RKB9PD8')
        ->and($event->action())->toBe(ArtifactReleased::ACTION_OVERRIDE)
        ->and($event->actor())->toBe(ArtifactReleased::ACTOR_ADMIN_TOKEN)
        ->and($event->reason())->toBe('Primary accepted; backup copy waived')
        ->and($event->waivedTargets())->toBe(['01J8ZCK8T4N2QWX6VM3RKB9PD8'])
        ->and($event->isAbandonedDiscard())->toBeFalse();
});

it('names the abandoned generation\'s discard, and only that shape', function (): void {
    $abandoned = array_replace(artifactExample('artifact.released'), [
        'action' => 'discard',
        'plan_revision' => 0,
        'inventory_sha256' => '',
        'waived_targets' => [],
    ]);

    /** @var ArtifactReleased $event */
    $event = EventMap::make($abandoned);

    expect($event->isAbandonedDiscard())->toBeTrue()
        ->and($event->identity()->inventorySha256())->toBe('')
        ->and($event->waivedTargets())->toBe([]);

    // A discard of a finalized generation waives every target and keeps its hash.
    /** @var ArtifactReleased $finalized */
    $finalized = EventMap::make(array_replace($abandoned, ['plan_revision' => 1, 'inventory_sha256' => str_repeat('a', 64)]));

    expect($finalized->isAbandonedDiscard())->toBeFalse();
});

it('reads artifact.deleted', function (): void {
    /** @var ArtifactDeleted $event */
    $event = EventMap::make(artifactExample('artifact.deleted'));

    expect($event->planRevision())->toBe(2)
        ->and($event->requestId())->toBe('01J8ZCKBT4N2QWX6VM3RKB9PD8')
        ->and($event->deletedAtRaw())->toBe('2026-08-29T22:16:04.89Z')
        ->and($event->deletedAt()->format('u'))->toBe('890000');
});

it('reads transfer.completed', function (): void {
    /** @var TransferCompleted $event */
    $event = EventMap::make(artifactExample('transfer.completed'));

    expect($event->planRevision())->toBe(2)
        ->and($event->targetId())->toBe('01J8ZCK7T4N2QWX6VM3RKB9PD8')
        ->and($event->objectCount())->toBe(1146)
        ->and($event->sizeBytes())->toBe(19204817408)
        ->and($event->completedAtRaw())->toBe('2026-08-28T22:03:11.47Z')
        ->and($event->completedAt()->format('u'))->toBe('470000');
});

it('reads transfer.failed', function (): void {
    /** @var TransferFailed $event */
    $event = EventMap::make(artifactExample('transfer.failed'));

    expect($event->planRevision())->toBe(2)
        ->and($event->targetId())->toBe('01J8ZCK8T4N2QWX6VM3RKB9PD8')
        ->and($event->objectId())->toBe('01J8ZCK9T4N2QWX6VM3RKB9PD8')
        ->and($event->code())->toBe(TransferFailed::CODE_ATTEMPTS_EXHAUSTED)
        ->and($event->message())->toBe('The transfer reported attempts_exhausted');
});

it('reads transfer.authorization_required', function (): void {
    /** @var TransferAuthorizationRequired $event */
    $event = EventMap::make(artifactExample('transfer.authorization_required'));

    expect($event->planRevision())->toBe(2)
        ->and($event->targetId())->toBe('01J8ZCK8T4N2QWX6VM3RKB9PD8')
        ->and($event->objectId())->toBe('01J8ZCK9T4N2QWX6VM3RKB9PD8')
        ->and($event->objectKey())->toBe('backup/2026/show/720p/seg_000412.m4s')
        ->and($event->multipartUploadId())->toBe('provider-upload-id')
        ->and($event->requiredOperations())->toBe([TransferAuthorizationRequired::OPERATION_PARTS, TransferAuthorizationRequired::OPERATION_ABORT])
        ->and($event->requires(TransferAuthorizationRequired::OPERATION_PARTS))->toBeTrue()
        ->and($event->requires(TransferAuthorizationRequired::OPERATION_PUT))->toBeFalse()
        ->and($event->requiresHead())->toBeFalse()
        ->and($event->reason())->toBe(TransferAuthorizationRequired::REASON_AUTHORIZATION_EXPIRED)
        ->and($event->isCompletionProbe())->toBeFalse();
});

it('asks for a HEAD on a completion probe', function (): void {
    $payload = array_replace(artifactExample('transfer.authorization_required'), [
        'reason' => 'completion_probe_required',
        'required' => ['head'],
        'multipart_upload_id' => null,
    ]);

    /** @var TransferAuthorizationRequired $event */
    $event = EventMap::make($payload);

    expect($event->isCompletionProbe())->toBeTrue()
        ->and($event->requiresHead())->toBeTrue()
        ->and($event->requiredOperations())->toBe(['head'])
        ->and($event->multipartUploadId())->toBeNull();
});

it('drops anything that is not a string from a list field', function (): void {
    $payload = array_replace(artifactExample('transfer.authorization_required'), ['required' => ['put', 7, null, 'head']]);

    /** @var TransferAuthorizationRequired $event */
    $event = EventMap::make($payload);

    expect($event->requiredOperations())->toBe(['put', 'head']);
});

it('reads recording.registered\'s generation, and null for a recording from before v0.5.0', function (): void {
    /** @var RecordingRegistered $event */
    $event = EventMap::make(artifactExample('recording.registered'));

    expect($event->generation())->toBe('01J8ZCK5R7V2QNX8RW4TKB6PD3');

    /** @var RecordingRegistered $legacy */
    $legacy = EventMap::make(array_replace(artifactExample('recording.registered'), ['generation' => null]));

    expect($legacy->generation())->toBeNull();
});
