<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobSignedTarget;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Exceptions\BadRequestException;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Webhooks\EventMap;
use PHPUnit\Framework\AssertionFailedError;

const FAKEJOB_PRESIGNED = 'https://uploads.example.net/abc.mov?X-Amz-Signature=deadbeefsig';

function fakeJobSpec(string $key = 'video-941-v2'): Spec
{
    return Spec::make(JobSource::destinationRef('tsp-media', 'uploads/abc.mov'), $key)
        ->ladder('vod-1080p')
        ->target(
            JobTarget::hlsLadder('tsp-media', 'videos/941', id: '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
            JobTarget::audio(JobSignedTarget::make('us-east-1', 'tsp-assets'), 'audio/941.m4a', '01J8ZCK7T4N2QWX6VM3RKB9PD9'),
        )
        ->labels(['video_id' => '941']);
}

/**
 * Release every generation the job's outputs finalized, as the platform does after
 * each transfer.completed.
 */
function fakeJobReleaseAll(string $job): void
{
    $resource = Tmesis::node()->jobArtifacts();

    foreach ($resource->allJobArtifacts($job) as $generation) {
        $resource->releaseJobArtifact($job, $generation->id(), ReleaseRequest::make(
            (int) $generation->planRevision(),
            (string) $generation->inventorySha256(),
            'copied',
        ));
    }
}

it('queues a job with 202 and answers the same idempotency key with 200 and the existing job', function (): void {
    $fake = Tmesis::fake();

    $first = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec());
    $again = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec()->priority('high'));

    expect($first->created)->toBeTrue()
        ->and($first->state())->toBe(JobStatus::STATE_QUEUED)
        ->and($first->id())->toStartWith('job_')
        ->and($again->created)->toBeFalse()
        ->and($again->id())->toBe($first->id())
        ->and($again->priority())->toBe('normal');

    $fake->assertJobSubmitted('video-941-v2')
        ->assertJobSubmitted('video-941-v2', fn (array $body): bool => $body['outputs'] === ['hls_ladder', 'audio'])
        ->assertCalledCount('createTranscodeJob', 2);

    $job = Tmesis::node()->transcodeJobs()->getTranscodeJob($first->id());

    expect($job->outputs())->toHaveCount(2)
        ->and($job->outputs()[0]->state())->toBe('pending')
        ->and($job->labels())->toBe(['video_id' => '941'])
        ->and($job->source()->destinationRef())->toBe('tsp-media');
});

it('fails assertJobSubmitted for a key never submitted', function (): void {
    $fake = Tmesis::fake();

    expect(fn () => $fake->assertJobSubmitted('nope'))->toThrow(AssertionFailedError::class);
});

it('records a presigned source without its query string, and reports it that way', function (): void {
    $fake = Tmesis::fake();

    $accepted = Tmesis::node()->transcodeJobs()->createTranscodeJob(
        Spec::make(JobSource::url(FAKEJOB_PRESIGNED), 'k')->target(JobTarget::probe('tsp-media', 'p.json')),
    );

    expect(json_encode($fake->calls()))->not->toContain('deadbeefsig')
        ->and($fake->job($accepted->id())->source()->url())->toBe('https://uploads.example.net/abc.mov')
        ->and(json_encode($fake->job($accepted->id())->toArray()))->not->toContain('deadbeefsig');
});

it('drives a job to completion: one finalized generation per output, the Spec\'s targets filed as revision 1, every object landed', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();

    $fake->startJob($id)->progressJob($id, 'encode', 41.7);

    expect($fake->job($id)->state())->toBe(JobStatus::STATE_RUNNING)
        ->and($fake->job($id)->progress()->percent())->toBe(41.7)
        ->and($fake->job($id)->attempts())->toBe(1);

    $generations = $fake->completeJob($id);

    $job = Tmesis::node()->transcodeJobs()->getTranscodeJob($id);

    expect(array_keys($generations))->toBe(['hls_ladder', 'audio'])
        ->and($job->state())->toBe(JobStatus::STATE_COMPLETED)
        ->and($job->outputs()[0]->generation())->toBe($generations['hls_ladder'])
        ->and($job->outputs()[0]->url())->toBe('s3://tsp-media/videos/941/playlist.m3u8')
        ->and($job->outputs()[1]->url())->toBe('s3://tsp-assets/audio/941.m4a')
        ->and($job->completedAt())->not->toBeNull();

    $hls = Tmesis::node()->jobArtifacts()->getJobArtifact($id, $generations['hls_ladder']);
    $plan = Tmesis::node()->jobArtifacts()->getJobTransferPlan($id, $generations['hls_ladder']);

    expect($hls->kind())->toBe(ArtifactStatus::KIND_JOB_HLS)
        ->and($hls->jobId())->toBe($id)
        ->and($hls->stream())->toBeNull()
        ->and($hls->planRevision())->toBe(1)
        ->and($plan->targets()[0]->id())->toBe('01J8ZCK7T4N2QWX6VM3RKB9PD8')
        ->and($plan->targets()[0]->masterName())->toBe('playlist.m3u8')
        ->and($plan->counts()->complete())->toBe(5);

    $audio = Tmesis::node()->jobArtifacts()->allJobTransfers($id, $generations['audio'])->all();

    expect($audio)->toHaveCount(1)
        ->and($audio[0]->objectKey())->toBe('audio/941.m4a');

    // Nothing auto-releases: the platform owes a release per job generation.
    expect(fn () => $fake->assertEveryFinalizedGenerationReleased())->toThrow(AssertionFailedError::class);

    fakeJobReleaseAll($id);

    $fake->assertEveryFinalizedGenerationReleased()
        ->assertJobArtifactReleased($id, $generations['audio'], DecisionStatus::ACTION_RELEASE)
        ->assertCalled('releaseJobArtifact', fn (array $args): bool => $args['jobId'] === $id);
});

it('fails an optional output while the job completes', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();

    $generations = $fake->completeJob($id, ['audio' => ['state' => 'failed', 'error' => ['code' => 'source_unsupported', 'class' => 'permanent', 'message' => 'no audio', 'retryable' => false]]]);

    $job = $fake->job($id);

    expect(array_keys($generations))->toBe(['hls_ladder'])
        ->and($job->state())->toBe(JobStatus::STATE_COMPLETED)
        ->and($job->outputs()[1]->error()?->code())->toBe('source_unsupported')
        ->and($job->outputs()[1]->generation())->toBeNull();
});

it('cancels a job, and a terminal one is 409 job_not_cancellable', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();

    $cancelled = Tmesis::node()->transcodeJobs()->cancelTranscodeJob($id);

    expect($cancelled->state())->toBe('cancelled')
        ->and($fake->job($id)->error()?->isCancellation())->toBeTrue();

    $fake->assertJobCancelled($id);

    try {
        Tmesis::node()->transcodeJobs()->cancelTranscodeJob($id);
        $this->fail('expected a conflict');
    } catch (ConflictException $e) {
        expect($e->code())->toBe('job_not_cancellable');
    }

    expect(fn () => $fake->assertJobCancelled('job_unknown'))->toThrow(AssertionFailedError::class);
});

it('fails a running job terminally with its error, and refuses to fail or cancel a completed one', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();
    $generations = $fake->completeJob($id);

    // A completed job cannot fail; drive a second job instead and fail it on the node.
    expect(fn () => $fake->failJob($id))->toThrow(InvalidConfigurationException::class);

    $second = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec('video-942'))->id();
    $fake->startJob($second);

    expect($fake->failJob($second, 'timeout', 'permanent', 'encode'))->toBe([])
        ->and($fake->job($second)->state())->toBe(JobStatus::STATE_FAILED)
        ->and($fake->job($second)->error()?->stage())->toBe('encode');

    // The completed one's cancellation is refused like any terminal job.
    expect(fn () => $fake->cancelJob($id))->toThrow(ConflictException::class)
        ->and($generations)->toHaveCount(2);
});

it('reports what had landed when a job with finalized generations is cancelled', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();
    $fake->artifactFinalizedForJob($id, '01J8ZD02R7F3H9NQ4KTX8VD5Y3', ArtifactStatus::KIND_JOB_AUDIO, labels: ['video_id' => '941']);

    $plan = Tmesis::node()->jobArtifacts()->replaceJobTransferPlan($id, '01J8ZD02R7F3H9NQ4KTX8VD5Y3', PlanRequest::make(
        (string) Tmesis::node()->jobArtifacts()->getJobArtifact($id, '01J8ZD02R7F3H9NQ4KTX8VD5Y3')->inventorySha256(),
        0,
    )->withTargets(PlanTarget::jobAudio('tsp-media', 'videos/941/audio.m4a', 'T1')));

    $fake->completeJobTransfer($id, '01J8ZD02R7F3H9NQ4KTX8VD5Y3', 'T1');

    expect($plan->created)->toBeTrue()
        ->and($fake->jobArtifactIdentity($id, '01J8ZD02R7F3H9NQ4KTX8VD5Y3'))->toMatchArray([
            'stream' => null, 'session' => null, 'session_epoch' => null, 'job_id' => $id, 'kind' => 'job_audio', 'labels' => ['video_id' => '941'],
        ]);

    $identity = EventMap::make(['event' => 'artifact.finalized', ...$fake->jobArtifactIdentity($id, '01J8ZD02R7F3H9NQ4KTX8VD5Y3')]);

    expect($identity->stream())->toBeNull();

    expect($fake->jobLanded($id))->toBe([]);

    Tmesis::node()->transcodeJobs()->cancelTranscodeJob($id);

    expect($fake->jobLanded($id))->toBe([
        ['generation' => '01J8ZD02R7F3H9NQ4KTX8VD5Y3', 'target_id' => 'T1', 'object_key' => 'videos/941/audio.m4a'],
    ]);

    // Superseded by an empty revision: nothing more is sent, and it stays held.
    $superseded = Tmesis::node()->jobArtifacts()->getJobTransferPlan($id, '01J8ZD02R7F3H9NQ4KTX8VD5Y3');

    expect($superseded->revision())->toBe(2)
        ->and($superseded->targets())->toBe([]);
});

it('lists jobs newest first, filtered and paged', function (): void {
    $fake = Tmesis::fake();
    $a = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec('a'))->id();
    $b = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec('b'))->id();
    $fake->startJob($b);

    $all = Tmesis::node()->transcodeJobs()->allTranscodeJobs(null, 1)->all();
    $running = Tmesis::node()->transcodeJobs()->listTranscodeJobs('running');

    expect(array_map(static fn ($item): string => $item->id(), $all))->toBe([$b, $a])
        ->and($running->items())->toHaveCount(1)
        ->and($running->items()[0]->stage())->toBe('fetch');

    $page = Tmesis::node()->transcodeJobs()->listTranscodeJobs(null, 1);

    expect(fn () => Tmesis::node()->transcodeJobs()->listTranscodeJobs('queued', 1, $page->nextCursor()))->toThrow(BadRequestException::class);
});

it('answers an unknown job and a generation of another job with 404', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();
    $fake->artifactFinalizedForJob($id, 'G1');

    expect(fn () => Tmesis::node()->transcodeJobs()->getTranscodeJob('job_unknown'))->toThrow(NotFoundException::class)
        ->and(fn () => Tmesis::node()->jobArtifacts()->getJobArtifact('job_other', 'G1'))->toThrow(NotFoundException::class)
        ->and(fn () => Tmesis::node()->artifacts()->getArtifact($id, 'G1'))->toThrow(NotFoundException::class)
        ->and(fn () => $fake->artifactFinalizedForJob($id, 'G2', ArtifactStatus::KIND_HLS_SEALED))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $fake->artifactFinalized('show-2041-main', 'G3', ArtifactStatus::KIND_JOB_HLS))->toThrow(InvalidConfigurationException::class);
});

it('throws what throwOn names for a job operation, as for any other', function (): void {
    $fake = Tmesis::fake()->throwOn('createTranscodeJob', new NotImplementedException('no job runner'));

    expect(fn () => Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec()))->toThrow(NotImplementedException::class);

    $fake->assertCalled('createTranscodeJob');
});

it('reaches the fake job resources through a request id', function (): void {
    Tmesis::fake();
    $id = Tmesis::node()->withRequestId('r1')->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();

    expect(Tmesis::node()->withRequestId('r2')->jobArtifacts()->listJobArtifacts($id)->items())->toBe([]);
});

it('refuses to drive a job out of order', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(fakeJobSpec())->id();

    expect(fn () => $fake->progressJob($id, 'encode', 10))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $fake->startJob($id)->progressJob($id, 'dance', 10))->toThrow(InvalidConfigurationException::class);
});

it('keeps a presigned source\'s query string and signature out of the ledger and the call log, in every rendering', function (): void {
    $fake = Tmesis::fake();

    $id = Tmesis::node()->transcodeJobs()->createTranscodeJob(
        Spec::make(JobSource::url(FAKEJOB_PRESIGNED), 'presigned-941')
            ->target(JobTarget::audio(JobSignedTarget::make('us-east-1', 'tsp-assets'), 'audio/941.m4a'))
            ->target(JobTarget::probe('tsp-media', 'videos/941/probe.json')),
    )->id();

    // Completion runs from what the ledger kept, not from the Spec.
    $fake->completeJob($id);

    $renderings = [];

    foreach (['ledger' => $fake->jobLedger(), 'calls' => $fake->calls()] as $name => $subject) {
        $renderings["{$name} print_r"] = print_r($subject, true);
        $renderings["{$name} var_export"] = var_export($subject, true);
        $renderings["{$name} json_encode"] = (string) json_encode($subject);
        $renderings["{$name} array cast"] = print_r((array) $subject, true);
    }

    foreach ($renderings as $name => $text) {
        expect($text)->not->toContain('deadbeefsig', "{$name} leaks the signature")
            ->and($text)->not->toContain('X-Amz-Signature', "{$name} leaks the query string");
    }

    expect($fake->job($id)->state())->toBe(JobStatus::STATE_COMPLETED)
        ->and($fake->job($id)->outputs()[0]->url())->toBe('s3://tsp-assets/audio/941.m4a')
        ->and($fake->job($id)->source()->url())->toBe('https://uploads.example.net/abc.mov');
});
