<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Jobs\EditRange;
use AlexHackney\Tmesis\Data\Jobs\EditSpec;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\MediaEditRequest;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use PHPUnit\Framework\AssertionFailedError;

const FAKE_EDIT_PRESIGNED = 'https://uploads.example.net/abc.mov?X-Amz-Signature=deadbeefsig';

function fakeClip(string $key = 'clip-5521'): MediaEditRequest
{
    return MediaEditRequest::make(
        JobSource::prefix('tsp-vod', 'replays/941', '720p'),
        EditSpec::make()->trim(120.0, 148.5)->crop(656, 0, 608, 1080)->scale(1080, 1920),
        JobTarget::mediaEdit('tsp-clips', 'clips/941/c1.mp4', '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
        $key,
    )->labels(['clip_id' => '5521']);
}

function validatesAs(string $component, mixed $body): void
{
    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/'.$component, "The fake's {$component}");
}

it('queues an edit with 202 and answers the same key with 200 and the existing job', function (): void {
    $fake = Tmesis::fake();

    $first = Tmesis::node()->transcodeJobs()->createMediaEdit(fakeClip());
    $again = Tmesis::node()->transcodeJobs()->createMediaEdit(fakeClip()->priority('high'));

    expect($first->created)->toBeTrue()
        ->and($first->state())->toBe(JobStatus::STATE_QUEUED)
        ->and($first->id())->toStartWith('job_')
        ->and($first->kind())->toBe('media_edit')
        ->and($first->cut()->mode())->toBe('keyframe')
        ->and($first->operations())->toHaveCount(3)
        ->and($again->created)->toBeFalse()
        ->and($again->id())->toBe($first->id())
        ->and($again->priority())->toBe('normal');

    validatesAs('MediaEditAccepted', $first->toArray());
    validatesAs('MediaEditAccepted', $again->toArray());

    $fake->assertMediaEditSubmitted('clip-5521')
        ->assertMediaEditSubmitted('clip-5521', fn (array $body): bool => $body['source']['rendition'] === '720p')
        ->assertCalledCount('createMediaEdit', 2);

    expect(fn () => $fake->assertMediaEditSubmitted('nope'))->toThrow(AssertionFailedError::class);

    $job = Tmesis::node()->transcodeJobs()->getTranscodeJob($first->id());

    expect($job->outputs())->toHaveCount(1)
        ->and($job->outputs()[0]->kind())->toBe('media_edit')
        ->and($job->source()->prefix())->toBe('replays/941')
        ->and($job->source()->rendition())->toBe('720p')
        ->and($job->edit())->toBeNull()
        ->and($job->labels())->toBe(['clip_id' => '5521']);
});

it('drives an edit through stage edit and completes it with an edit block and a media_edit output', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createMediaEdit(fakeClip())->id();

    $fake->startJob($id)->progressJob($id, 'edit', 40.0);

    expect($fake->job($id)->progress()->stage())->toBe('edit')
        ->and(fn () => $fake->progressJob($id, 'transmux', 1.0))->toThrow(InvalidConfigurationException::class);

    $generations = $fake->completeJob($id);
    $job = Tmesis::node()->transcodeJobs()->getTranscodeJob($id);

    expect(array_keys($generations))->toBe(['media_edit'])
        ->and($job->state())->toBe(JobStatus::STATE_COMPLETED)
        ->and($job->outputs()[0]->url())->toBe('s3://tsp-clips/clips/941/c1.mp4')
        ->and($job->edit()?->mode())->toBe('keyframe')
        ->and($job->edit()?->inputs()[0]->kind())->toBe('hls')
        ->and($job->edit()?->inputs()[0]->location())->toBe('s3://tsp-vod/replays/941')
        ->and($job->edit()?->ranges())->toHaveCount(1)
        ->and($job->edit()?->ranges()[0]->requestedInSeconds())->toBe(120.0)
        ->and($job->edit()?->ranges()[0]->actualOutSeconds())->toBe(148.5)
        // crop and scale re-encode every kept frame.
        ->and($job->edit()?->ranges()[0]->reencodedWhole())->toBeTrue();

    validatesAs('JobStatus', $job->toArray());
});

it('lets a test choose where the cut landed', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createMediaEdit(
        MediaEditRequest::make(JobSource::prefix('tsp-vod', 'replays/941'), EditSpec::make()->trim(120.0, 148.5), JobTarget::mediaEdit('tsp-clips', 'c.mp4'), 'clip-snap'),
    )->id();

    $fake->completeJob($id, edit: ['ranges' => [[
        'input' => 0, 'requested_in_seconds' => 120, 'actual_in_seconds' => 118.4, 'requested_out_seconds' => 148.5,
        'actual_out_seconds' => 149.2, 'output_offset_seconds' => 0, 'reencoded_gops' => 0, 'reencoded_whole' => false,
    ]]]);

    $range = $fake->job($id)->edit()?->ranges()[0];

    expect($range?->actualInSeconds())->toBe(118.4)
        ->and($range?->actualOutSeconds())->toBe(149.2)
        ->and($range?->reencodedGops())->toBe(0);
});

it('reports a frame-accurate cut as re-encoding the head and tail', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createMediaEdit(
        MediaEditRequest::make(JobSource::prefix('tsp-vod', 'replays/941'), EditSpec::make()->trim(10.0, 20.0)->frameAccurate(), JobTarget::mediaEdit('tsp-clips', 'c.mp4'), 'clip-exact'),
    )->id();

    $fake->completeJob($id);

    expect($fake->job($id)->edit()?->mode())->toBe('frame_accurate')
        ->and($fake->job($id)->edit()?->ranges()[0]->reencodedGops())->toBe(2)
        ->and($fake->job($id)->edit()?->ranges()[0]->reencodedWhole())->toBeFalse();
});

it('reports a concat across sources as one input each, in first-use order, with the output offsets summed', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createMediaEdit(MediaEditRequest::make(
        JobSource::prefix('tsp-vod', 'replays/941'),
        EditSpec::make()->concat([
            EditRange::make(0.0, 10.0),
            EditRange::make(5.0, 8.0, JobSource::destinationRef('tsp-vod', 'replays/942.mp4')),
            EditRange::make(20.0, 25.0),
        ]),
        JobTarget::mediaEdit('tsp-clips', 'c.mp4'),
        'clip-concat',
    )->labels(['clip_id' => '5522']))->id();

    $fake->completeJob($id);
    $edit = $fake->job($id)->edit();

    expect($edit?->inputs())->toHaveCount(2)
        ->and($edit?->inputs()[1]->location())->toBe('s3://tsp-vod/replays/942.mp4')
        ->and(array_map(static fn ($range): int => $range->input(), $edit?->ranges() ?? []))->toBe([0, 1, 0])
        ->and(array_map(static fn ($range): float => $range->outputOffsetSeconds(), $edit?->ranges() ?? []))->toBe([0.0, 10.0, 13.0]);

    validatesAs('JobStatus', $fake->job($id)->toArray());
});

it('records a presigned edit source without its query string, in the request and in the job', function (): void {
    $fake = Tmesis::fake();

    $accepted = Tmesis::node()->transcodeJobs()->createMediaEdit(MediaEditRequest::make(
        JobSource::url(FAKE_EDIT_PRESIGNED),
        EditSpec::make()->concat([EditRange::make(0.0, 5.0, JobSource::url(FAKE_EDIT_PRESIGNED))]),
        JobTarget::mediaEdit('tsp-clips', 'c.mp4'),
        'clip-presigned',
    ));

    $fake->completeJob($accepted->id());

    expect(json_encode($fake->calls()))->not->toContain('deadbeefsig')
        ->and(json_encode($fake->job($accepted->id())->toArray()))->not->toContain('deadbeefsig')
        ->and($fake->job($accepted->id())->edit()?->inputs()[0]->location())->toBe('https://uploads.example.net/abc.mov');
});

it('takes an edit on the job route too, range operations and a media_edit output beside a ladder', function (): void {
    $fake = Tmesis::fake();

    $accepted = Tmesis::node()->transcodeJobs()->createTranscodeJob(
        Spec::make(JobSource::prefix('tsp-vod', 'replays/941'), 'edit-job')
            ->edit(EditSpec::make()->trim(10.0, 20.0))
            ->ladder('vod-1080p')
            ->target(JobTarget::mediaEdit('tsp-clips', 'clips/c.mp4'), JobTarget::hlsLadder('tsp-clips', 'clips/hls')),
    );

    $generations = $fake->completeJob($accepted->id());

    expect(array_keys($generations))->toBe(['media_edit', 'hls_ladder'])
        ->and($fake->job($accepted->id())->edit()?->ranges())->toHaveCount(1);

    $fake->assertJobSubmitted('edit-job', fn (array $body): bool => $body['edit']['operations'][0]['op'] === 'trim');
});

it('finalizes the edit as a job_media_edit generation held until released', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createMediaEdit(fakeClip())->id();
    $generation = $fake->completeJob($id)['media_edit'];

    $artifact = Tmesis::node()->jobArtifacts()->getJobArtifact($id, $generation);
    $plan = Tmesis::node()->jobArtifacts()->getJobTransferPlan($id, $generation);

    expect($artifact->kind())->toBe(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and($artifact->jobId())->toBe($id)
        ->and($plan->targets()[0]->artifact())->toBe(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and($plan->targets()[0]->key())->toBe('clips/941/c1.mp4')
        ->and($plan->targets()[0]->prefix())->toBeNull();

    expect(fn () => $fake->assertEveryFinalizedGenerationReleased())->toThrow(AssertionFailedError::class);

    Tmesis::node()->jobArtifacts()->releaseJobArtifact($id, $generation, ReleaseRequest::make((int) $artifact->planRevision(), (string) $artifact->inventorySha256(), 'copied'));

    $fake->assertEveryFinalizedGenerationReleased()->assertJobArtifactReleased($id, $generation, DecisionStatus::ACTION_RELEASE);
});

it('parks and un-parks a static relay leg, enabled being the effective value', function (): void {
    $fake = Tmesis::fake();

    $fake->withRelays('show-2041-main', [
        ['id' => 'origin', 'url' => 'srt://origin-atl-01.internal:8890', 'enabled' => true, 'source' => 'static',
            'state' => 'connected', 'bytes_out' => 10, 'dropped_bytes' => 0, 'reconnects' => 0,
            'feed' => 'auto', 'feed_resolved' => 'ingest'],
    ]);

    $seeded = Tmesis::node()->relays()->getRelay('show-2041-main', 'origin');

    expect($seeded->aliases())->toBe([])
        ->and($seeded->lastSession())->toBeNull()
        ->and($seeded->parked())->toBeFalse();

    validatesAs('RelayRecord', $seeded->toArray());

    $parked = Tmesis::node()->relays()->updateRelay('show-2041-main', 'origin', RelayPatch::make(enabled: false));

    // A parked leg reads enabled:false, parked:true, state:idle.
    expect($parked->enabled())->toBeFalse()
        ->and($parked->parked())->toBeTrue()
        ->and($parked->state())->toBe('idle');

    validatesAs('RelayRecord', $parked->toArray());

    $lifted = Tmesis::node()->relays()->updateRelay('show-2041-main', 'origin', RelayPatch::make(enabled: true));

    expect($lifted->enabled())->toBeTrue()
        ->and($lifted->parked())->toBeFalse()
        ->and($lifted->state())->toBe('connecting');

    validatesAs('RelayRecord', $lifted->toArray());
});

it('does not mark an API relay parked when it is disabled', function (): void {
    Tmesis::fake();

    $created = Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://backup-den-02.internal:8890', id: 'backup'));
    $stopped = Tmesis::node()->relays()->updateRelay('show-2041-main', 'backup', RelayPatch::make(enabled: false));

    expect($created->aliases())->toBe([])
        ->and($created->parked())->toBeFalse()
        ->and($stopped->enabled())->toBeFalse()
        ->and($stopped->parked())->toBeFalse();

    validatesAs('RelayRecord', $created->toArray());
    validatesAs('RelayRecord', $stopped->toArray());
});

it('gives every fake transfer object the nullable last_attempt the response schema requires', function (): void {
    $fake = Tmesis::fake();
    $id = Tmesis::node()->transcodeJobs()->createMediaEdit(fakeClip())->id();
    $generation = $fake->completeJob($id)['media_edit'];

    $transfers = Tmesis::node()->jobArtifacts()->listJobTransfers($id, $generation);

    expect($transfers->items())->not->toBeEmpty();

    foreach ($transfers->items() as $object) {
        expect($object->lastAttempt())->toBeNull();
        validatesAs('ObjectStatus', $object->toArray());
    }
});
