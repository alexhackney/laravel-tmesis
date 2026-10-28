<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\JobCompleted;
use AlexHackney\Tmesis\Events\JobEvent;
use AlexHackney\Tmesis\Events\JobFailed;
use AlexHackney\Tmesis\Events\JobProgress;
use AlexHackney\Tmesis\Events\JobQueued;
use AlexHackney\Tmesis\Events\JobStarted;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * @return array<string, mixed>
 */
function jobExample(string $event): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(ContractDir::example($event)), true, 512, JSON_THROW_ON_ERROR);
}

it('maps each job event to its class on the flat envelope, about a job and not a stream', function (string $event, string $class): void {
    $instance = EventMap::make(jobExample($event));

    expect($instance)->toBeInstanceOf($class)->toBeInstanceOf(JobEvent::class)
        ->and($instance->isKnownEvent())->toBeTrue()
        ->and($instance::EVENT)->toBe($event);

    /** @var JobEvent $instance */
    expect($instance->job())->toBe('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB')
        ->and($instance->stream())->toBeNull()
        ->and($instance->labels())->toBe(['video_id' => '941'])
        ->and($instance->node())->toBe('origin-atl-01')
        ->and($instance->toArray())->toBe(jobExample($event));
})->with([
    ['job.queued', JobQueued::class],
    ['job.started', JobStarted::class],
    ['job.progress', JobProgress::class],
    ['job.completed', JobCompleted::class],
    ['job.failed', JobFailed::class],
]);

it('reads job.queued', function (): void {
    /** @var JobQueued $event */
    $event = EventMap::make(jobExample('job.queued'));

    expect($event->state())->toBe('queued')
        ->and($event->reason())->toBe(JobQueued::REASON_SUBMITTED)
        ->and($event->isSubmission())->toBeTrue()
        ->and($event->priority())->toBe('normal')
        ->and($event->idempotencyKey())->toBe('video-941-v2')
        ->and($event->outputs())->toBe(['hls_ladder', 'audio'])
        ->and($event->source()->destinationRef())->toBe('tsp-media')
        ->and($event->source()->key())->toBe('uploads/abc.mov')
        ->and($event->attempts())->toBe(0)
        ->and($event->evictions())->toBe(0);
});

it('names every queue reason the schema allows', function (): void {
    $schema = ContractDir::readJson(ContractDir::schema('job.queued'));
    $description = (string) $schema['$defs']['JobQueued']['properties']['reason']['description'];

    foreach (JobQueued::REASONS as $reason) {
        expect($description)->toContain($reason);
    }

    /** @var JobQueued $evicted */
    $evicted = EventMap::make(array_replace(jobExample('job.queued'), ['reason' => 'live_preempted', 'evictions' => 1, 'idempotency_key' => null]));

    expect($evicted->isSubmission())->toBeFalse()
        ->and($evicted->evictions())->toBe(1)
        ->and($evicted->idempotencyKey())->toBeNull();
});

it('reads job.started', function (): void {
    /** @var JobStarted $event */
    $event = EventMap::make(jobExample('job.started'));

    expect($event->state())->toBe('running')
        ->and($event->attempt())->toBe(1)
        ->and($event->queuedSeconds())->toBe(8.8)
        ->and($event->backend())->toBe('libx264');
});

it('reads job.progress, whose outputs are only the ones already landed', function (): void {
    /** @var JobProgress $event */
    $event = EventMap::make(jobExample('job.progress'));

    expect($event->percent())->toBe(41.7)
        ->and($event->stage())->toBe('encode')
        ->and($event->speed())->toBe(3.9)
        ->and($event->etaSeconds())->toBe(402.0)
        ->and($event->outputs())->toHaveCount(1)
        ->and($event->outputs()[0]->kind())->toBe('audio')
        ->and($event->outputs()[0]->isCompleted())->toBeTrue()
        ->and($event->outputs()[0]->format())->toBe('aac');

    /** @var JobProgress $early */
    $early = EventMap::make(array_replace(jobExample('job.progress'), ['outputs' => [], 'eta_seconds' => null]));

    expect($early->outputs())->toBe([])
        ->and($early->etaSeconds())->toBeNull();
});

it('reads job.completed, every output naming its generation', function (): void {
    /** @var JobCompleted $event */
    $event = EventMap::make(jobExample('job.completed'));

    expect($event->state())->toBe('completed')
        ->and($event->durationSeconds())->toBe(1802.4)
        ->and($event->encodeSeconds())->toBe(1284.0)
        ->and($event->attempts())->toBe(1)
        ->and($event->evictions())->toBe(0)
        ->and($event->source()->bytes())->toBe(8931245121)
        ->and($event->outputs())->toHaveCount(2)
        ->and($event->outputs()[0]->generation())->toBe('01J8ZD02R7F3H9NQ4KTX8VD5Y1')
        ->and($event->outputs()[0]->url())->toBe('s3://tsp-media/videos/941/playlist.m3u8')
        ->and($event->probe()?->video())->toBe('h264 3840x2160 yuv420p 29.97fps')
        ->and($event->probe()?->audio())->toBe('aac 48000 stereo')
        ->and($event->probe()?->durationSeconds())->toBe(1802.4)
        ->and($event->probe()?->toArray())->toHaveKey('video');

    /** @var JobCompleted $noProbe */
    $noProbe = EventMap::make(array_replace(jobExample('job.completed'), ['probe' => null]));

    expect($noProbe->probe())->toBeNull();
});

it('reads job.failed, and tells a cancel from a failure', function (): void {
    /** @var JobFailed $event */
    $event = EventMap::make(jobExample('job.failed'));

    expect($event->state())->toBe(JobFailed::STATE_FAILED)
        ->and($event->isCancelled())->toBeFalse()
        ->and($event->stage())->toBe('fetch')
        ->and($event->attempts())->toBe(1)
        ->and($event->evictions())->toBe(0)
        ->and($event->error()->code())->toBe('source_unreadable')
        ->and($event->error()->isPermanent())->toBeTrue()
        ->and($event->landed())->toBe([]);

    $cancel = array_replace(jobExample('job.failed'), [
        'state' => 'cancelled',
        'stage' => null,
        'error' => ['code' => 'cancelled', 'class' => 'permanent', 'message' => 'cancelled by DELETE', 'retryable' => false],
        'landed' => [['generation' => '01J8ZD02R7F3H9NQ4KTX8VD5Y3', 'target_id' => '01J8ZCK7T4N2QWX6VM3RKB9PD8', 'object_key' => 'videos/941/audio.m4a']],
    ]);

    SchemaValidator::forSchema('job.failed')->assertValid(json_decode((string) json_encode($cancel), false), null, 'job.failed cancelled');

    /** @var JobFailed $cancelled */
    $cancelled = EventMap::make($cancel);

    expect($cancelled->isCancelled())->toBeTrue()
        ->and($cancelled->stage())->toBeNull()
        ->and($cancelled->error()->isCancellation())->toBeTrue()
        ->and($cancelled->landed())->toHaveCount(1)
        ->and($cancelled->landed()[0]->generation())->toBe('01J8ZD02R7F3H9NQ4KTX8VD5Y3')
        ->and($cancelled->landed()[0]->targetId())->toBe('01J8ZCK7T4N2QWX6VM3RKB9PD8')
        ->and($cancelled->landed()[0]->objectKey())->toBe('videos/941/audio.m4a')
        ->and($cancelled->landed()[0]->toArray())->toHaveKey('object_key');
});
