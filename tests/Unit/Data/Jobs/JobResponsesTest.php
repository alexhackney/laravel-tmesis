<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Jobs\JobError;
use AlexHackney\Tmesis\Data\Jobs\JobOutputReport;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthJobs;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

it('reads a completed output report with every per-kind field (API.md 9.11)', function (): void {
    $thumbs = JobOutputReport::fromArray([
        'kind' => 'thumbnails', 'generation' => '01J8ZD02R7F3H9NQ4KTX8VD5Y4', 'state' => 'completed',
        'url' => 's3://tsp-assets/thumbs/941/', 'count' => 184,
        'mode_counts' => ['interval' => 180, 'at_seconds' => 2, 'at_percent' => 1, 'random' => 1], 'seed' => 8817264401,
    ]);
    $mp4 = JobOutputReport::fromArray(['kind' => 'mp4', 'generation' => 'g', 'state' => 'completed', 'rung' => '1080p', 'bytes' => 1893746152, 'video_only' => true]);
    $transcript = JobOutputReport::fromArray(['kind' => 'transcript', 'generation' => 'g', 'state' => 'completed', 'language' => 'en']);
    $audio = JobOutputReport::fromArray(['kind' => 'audio', 'generation' => null, 'state' => 'failed', 'format' => 'aac', 'error' => [
        'code' => 'source_unsupported', 'class' => 'permanent', 'message' => 'no audio', 'retryable' => false, 'stage' => 'audio',
    ]]);

    expect($thumbs->isCompleted())->toBeTrue()
        ->and($thumbs->count())->toBe(184)
        ->and($thumbs->modeCounts())->toBe(['interval' => 180, 'at_seconds' => 2, 'at_percent' => 1, 'random' => 1])
        ->and($thumbs->seed())->toBe(8817264401)
        ->and($thumbs->url())->toBe('s3://tsp-assets/thumbs/941/')
        ->and($thumbs->error())->toBeNull()
        ->and($thumbs->videoOnly())->toBeFalse()
        ->and($mp4->rung())->toBe('1080p')
        ->and($mp4->bytes())->toBe(1893746152)
        ->and($mp4->videoOnly())->toBeTrue()
        ->and($transcript->language())->toBe('en')
        ->and($audio->generation())->toBeNull()
        ->and($audio->format())->toBe('aac')
        ->and($audio->isCompleted())->toBeFalse()
        ->and($audio->error()?->code())->toBe('source_unsupported')
        ->and($audio->error()?->stage())->toBe('audio')
        ->and($audio->toArray()['kind'])->toBe('audio');
});

it('reads the batch provider on a transcript output, and null where the node sends none', function (): void {
    // tmesis v0.9.3, C4: optional, and only on a transcript output. The node never
    // sends it null, but the schema allows null, so it reads as an absent field.
    $transcript = JobOutputReport::fromArray([
        'kind' => 'transcript', 'generation' => 'g', 'state' => 'completed', 'language' => 'en', 'provider' => 'deepgram',
    ]);
    $unanswered = JobOutputReport::fromArray([
        'kind' => 'transcript', 'generation' => null, 'state' => 'failed', 'language' => 'en',
        'error' => ['code' => 'timeout', 'class' => 'permanent', 'message' => 'no provider answered', 'retryable' => false, 'stage' => 'transcript'],
    ]);
    $mp4 = JobOutputReport::fromArray(['kind' => 'mp4', 'generation' => 'g', 'state' => 'completed', 'rung' => '1080p']);
    $nullProvider = JobOutputReport::fromArray(['kind' => 'transcript', 'generation' => 'g', 'state' => 'completed', 'language' => 'en', 'provider' => null]);

    expect($transcript->provider())->toBe('deepgram')
        ->and($transcript->language())->toBe('en')
        ->and($unanswered->provider())->toBeNull()
        ->and($mp4->provider())->toBeNull()
        ->and($nullProvider->provider())->toBeNull()
        ->and($nullProvider->toArray()['provider'])->toBeNull();
});

it('reads a job error, and tells a cancellation from a failure', function (): void {
    $failed = JobError::fromArray(['code' => 'timeout', 'class' => 'permanent', 'message' => 'deadline', 'retryable' => true]);
    $cancelled = JobError::fromArray(['code' => 'cancelled', 'class' => 'permanent', 'message' => 'DELETE', 'retryable' => false]);
    $transient = JobError::fromArray(['code' => 'internal', 'class' => 'transient', 'message' => 'bug', 'retryable' => true, 'stage' => 'encode']);

    expect($failed->isPermanent())->toBeTrue()
        ->and($failed->retryable())->toBeTrue()
        ->and($failed->isCancellation())->toBeFalse()
        ->and($failed->stage())->toBeNull()
        ->and($failed->message())->toBe('deadline')
        ->and($cancelled->isCancellation())->toBeTrue()
        ->and($transient->isPermanent())->toBeFalse()
        ->and($transient->class())->toBe(JobError::CLASS_TRANSIENT)
        ->and($transient->toArray()['stage'])->toBe('encode');
});

it('reads a failed job, terminal, with its whole error', function (): void {
    $job = JobStatus::fromArray([
        'id' => 'job_1', 'state' => 'failed', 'priority' => 'high', 'node' => 'n', 'idempotency_key' => null,
        'submitted_at' => '2026-09-27T19:40:00Z', 'started_at' => '2026-09-27T19:40:02Z', 'completed_at' => '2026-09-27T19:52:10Z',
        'attempts' => 3, 'evictions' => 0, 'progress' => ['percent' => 0, 'stage' => null, 'speed' => 0, 'eta_seconds' => null],
        'source' => ['url' => 'https://uploads.example.net/abc.mov'], 'outputs' => [],
        'error' => ['code' => 'source_unreadable', 'class' => 'permanent', 'message' => '404', 'retryable' => false, 'stage' => 'fetch'],
        'labels' => [],
    ]);

    expect($job->isTerminal())->toBeTrue()
        ->and($job->idempotencyKey())->toBeNull()
        ->and($job->priority())->toBe('high')
        ->and($job->evictions())->toBe(0)
        ->and($job->submittedAt()->format('H:i'))->toBe('19:40')
        ->and($job->startedAt()?->format('H:i:s'))->toBe('19:40:02')
        ->and($job->completedAt()?->format('H:i:s'))->toBe('19:52:10')
        ->and($job->progress()->stage())->toBeNull()
        ->and($job->progress()->etaSeconds())->toBeNull()
        ->and($job->progress()->speed())->toBe(0.0)
        ->and($job->source()->url())->toBe('https://uploads.example.net/abc.mov')
        ->and($job->source()->key())->toBeNull()
        ->and($job->source()->bytes())->toBeNull()
        ->and($job->error()?->code())->toBe('source_unreadable')
        ->and($job->labels())->toBe([]);
});

it('gives a job generation the six job kinds, the prefix and key rule, and a nullable identity', function (): void {
    $status = ArtifactStatus::fromArray(['stream' => null, 'session' => null, 'session_epoch' => null, 'job_id' => 'job_1', 'kind' => 'job_mp4']);
    $stream = ArtifactStatus::fromArray(['stream' => 's', 'session' => 'x', 'session_epoch' => 3, 'job_id' => null, 'kind' => 'hls_sealed']);

    expect($status->isJobGeneration())->toBeTrue()
        ->and($status->jobId())->toBe('job_1')
        ->and($status->stream())->toBeNull()
        ->and($status->sessionEpoch())->toBeNull()
        ->and($stream->isJobGeneration())->toBeFalse()
        ->and($stream->sessionEpoch())->toBe(3)
        ->and(ArtifactStatus::JOB_KINDS)->toHaveCount(6)
        ->and(ArtifactStatus::KINDS)->toHaveCount(9);

    $prefix = [
        PlanTarget::jobHls('d', 'p'), PlanTarget::jobMp4('d', 'p'), PlanTarget::jobThumbnails('d', 'p'), PlanTarget::jobTranscript('d', 'p'),
    ];
    $key = [PlanTarget::jobAudio('d', 'k'), PlanTarget::jobProbe('d', 'k')];

    foreach ($prefix as $target) {
        expect($target->prefix())->toBe('p')->and($target->key())->toBeNull()
            ->and(in_array($target->artifact(), ArtifactStatus::PREFIX_KINDS, true))->toBeTrue();
    }

    foreach ($key as $target) {
        expect($target->key())->toBe('k')->and($target->prefix())->toBeNull();
    }

    expect(PlanTarget::jobHls('d', 'p', 'm.m3u8', 'id-1')->toArray())
        ->toBe(['id' => 'id-1', 'artifact' => 'job_hls', 'destination_ref' => 'd', 'prefix' => 'p', 'master_name' => 'm.m3u8']);
});

it('routes forKind by the prefix and key rule for every kind, and refuses a master name on a kind with none', function (string $kind, bool $prefix): void {
    $target = PlanTarget::forKind($kind, 'd', 'loc');

    expect($target->artifact())->toBe($kind)
        ->and($prefix ? $target->prefix() : $target->key())->toBe('loc')
        ->and($prefix ? $target->key() : $target->prefix())->toBeNull();

    if (isset(ArtifactStatus::MASTER_NAMES[$kind])) {
        expect(PlanTarget::forKind($kind, 'd', 'loc', 'x.m3u8')->masterName())->toBe('x.m3u8');
    } else {
        expect(fn () => PlanTarget::forKind($kind, 'd', 'loc', 'x.m3u8'))->toThrow(InvalidConfigurationException::class, 'master_name');
    }
})->with([
    ['hls_sealed', true], ['recording_ingest', false], ['recording_master', false],
    ['job_hls', true], ['job_mp4', true], ['job_audio', false], ['job_thumbnails', true], ['job_transcript', true], ['job_probe', false],
]);

it('reads the job slots on health, and capacity stays null until measured', function (): void {
    $jobs = HealthJobs::fromArray(['queued' => 2, 'running' => 1, 'slots' => 1]);

    expect($jobs->slots())->toBe(1)
        ->and($jobs->running())->toBe(1)
        ->and($jobs->queued())->toBe(2)
        ->and($jobs->hasFreeSlot())->toBeFalse()
        ->and(HealthJobs::fromArray(['queued' => 0, 'running' => 1, 'slots' => 6])->hasFreeSlot())->toBeTrue();

    $health = HealthFull::fromArray(['jobs' => ['queued' => 0, 'running' => 0, 'slots' => 1], 'capacity' => null]);

    expect($health->capacity())->toBeNull()
        ->and($health->jobs()?->slots())->toBe(1);
});
