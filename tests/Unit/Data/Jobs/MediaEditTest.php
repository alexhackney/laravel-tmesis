<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Jobs\EditCut;
use AlexHackney\Tmesis\Data\Jobs\EditOperation;
use AlexHackney\Tmesis\Data\Jobs\EditRange;
use AlexHackney\Tmesis\Data\Jobs\EditSpec;
use AlexHackney\Tmesis\Data\Jobs\JobEdit;
use AlexHackney\Tmesis\Data\Jobs\JobSignedTarget;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobSourceReport;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\MediaEditAccepted;
use AlexHackney\Tmesis\Data\Jobs\MediaEditRequest;
use AlexHackney\Tmesis\Data\Jobs\MediaEditTargets;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Events\JobCompleted;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

const EDIT_PRESIGNED = 'https://uploads.example.net/abc.mov?X-Amz-Signature=deadbeefsig';

/**
 * @return array<string, mixed>
 */
function editBlock(): array
{
    return [
        'request' => [
            'operations' => [
                ['op' => 'concat', 'ranges' => [
                    ['in_seconds' => 120, 'out_seconds' => 148.5],
                    ['in_seconds' => 10, 'out_seconds' => 20, 'source' => ['url' => 'https://uploads.example.net/abc.mov']],
                ]],
            ],
            'cut' => ['mode' => 'frame_accurate'],
        ],
        'mode' => 'frame_accurate',
        'inputs' => [
            ['kind' => 'hls', 'location' => 's3://tsp-vod/replays/941'],
            ['kind' => 'file', 'location' => 's3://tsp-vod/replays/942.mp4', 'bytes' => 1893746152, 'etag' => '"abc"'],
        ],
        'ranges' => [
            ['input' => 0, 'requested_in_seconds' => 120, 'actual_in_seconds' => 118.4, 'requested_out_seconds' => 148.5, 'actual_out_seconds' => 148.5, 'output_offset_seconds' => 0, 'reencoded_gops' => 2, 'reencoded_whole' => false],
            ['input' => 1, 'requested_in_seconds' => 10, 'actual_in_seconds' => 10, 'requested_out_seconds' => 20, 'actual_out_seconds' => 19.98, 'output_offset_seconds' => 30.1, 'reencoded_gops' => 0, 'reencoded_whole' => true],
        ],
    ];
}

it('builds an HLS replay source that excludes a key, and renders the optional rendition only when named', function (): void {
    expect(JobSource::prefix('tsp-vod', 'replays/941')->toArray())->toBe(['destination_ref' => 'tsp-vod', 'prefix' => 'replays/941'])
        ->and(JobSource::prefix('tsp-vod', 'replays/941', '720p')->toArray())->toBe(['destination_ref' => 'tsp-vod', 'prefix' => 'replays/941', 'rendition' => '720p'])
        ->and(JobSource::prefix('tsp-vod', 'replays/941', '720p')->sourcePrefix())->toBe('replays/941')
        ->and(JobSource::prefix('tsp-vod', 'replays/941', '720p')->rendition())->toBe('720p')
        ->and(JobSource::prefix('tsp-vod', 'replays/941')->key())->toBeNull()
        ->and(JobSource::destinationRef('tsp-vod', 'a.mp4')->sourcePrefix())->toBeNull();

    expect(fn () => JobSource::prefix('', 'replays/941'))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => JobSource::prefix('tsp-vod', ''))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => JobSource::prefix('tsp-vod', 'replays/941', ''))->toThrow(InvalidConfigurationException::class);
});

it('renders every operation with only the fields it takes', function (): void {
    $spec = EditSpec::make()
        ->trim(120.0, 148.5)
        ->crop(656, 0, 608, 1080)
        ->scale(1080, 1920, 'cover');

    expect($spec->toArray())->toBe(['operations' => [
        ['op' => 'trim', 'in_seconds' => 120.0, 'out_seconds' => 148.5],
        ['op' => 'crop', 'x' => 656, 'y' => 0, 'width' => 608, 'height' => 1080],
        ['op' => 'scale', 'width' => 1080, 'height' => 1920, 'fit' => 'cover'],
    ]]);

    expect(EditSpec::make()->rewrap()->toArray())->toBe(['operations' => [['op' => 'rewrap']]])
        ->and(EditSpec::make()->extractAudio()->toArray())->toBe(['operations' => [['op' => 'extract_audio', 'codec' => 'aac']]])
        ->and(EditSpec::make()->extractAudio('opus', 96)->toArray())->toBe(['operations' => [['op' => 'extract_audio', 'codec' => 'opus', 'kbps' => 96]]])
        ->and(EditSpec::make()->scale(640, 360)->toArray())->toBe(['operations' => [['op' => 'scale', 'width' => 640, 'height' => 360]]]);
});

it('omits the cut for a keyframe edit and renders it for a frame-accurate one', function (): void {
    expect(EditSpec::make()->trim(0.0, 5.0)->toArray())->not->toHaveKey('cut')
        ->and(EditSpec::make()->trim(0.0, 5.0)->cut()->mode())->toBe(EditCut::MODE_KEYFRAME)
        ->and(EditSpec::make()->trim(0.0, 5.0)->frameAccurate()->toArray()['cut'])->toBe(['mode' => 'frame_accurate'])
        ->and(EditSpec::make()->trim(0.0, 5.0)->frameAccurate()->keyframe()->toArray())->not->toHaveKey('cut')
        ->and(EditCut::make('frame_accurate')->isFrameAccurate())->toBeTrue()
        ->and(fn () => EditCut::make('exact'))->toThrow(InvalidConfigurationException::class);
});

it('joins up to 32 ranges in order and refuses none or too many', function (): void {
    $range = static fn (float $in): EditRange => EditRange::make($in, $in + 1);

    $spec = EditSpec::make()->concat([$range(0), EditRange::make(5.0, 9.0, JobSource::destinationRef('tsp-vod', 'b.mp4'))]);

    expect($spec->toArray()['operations'][0]['ranges'])->toBe([
        ['in_seconds' => 0.0, 'out_seconds' => 1.0],
        ['in_seconds' => 5.0, 'out_seconds' => 9.0, 'source' => ['destination_ref' => 'tsp-vod', 'key' => 'b.mp4']],
    ])->and($spec->hasRangeSources())->toBeTrue();

    expect(fn () => EditOperation::concat([]))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => EditOperation::concat(array_map($range, range(0, 32))))->toThrow(InvalidConfigurationException::class)
        ->and(EditOperation::concat(array_map($range, range(0, 31)))->ranges())->toHaveCount(32);
});

it('refuses the field values the node refuses without reading a file', function (Closure $build): void {
    expect($build)->toThrow(InvalidConfigurationException::class);
})->with([
    'a negative in point' => [fn () => EditOperation::trim(-1.0, 5.0)],
    'an out point at the in point' => [fn () => EditOperation::trim(5.0, 5.0)],
    'an out point before the in point' => [fn () => EditRange::make(5.0, 4.0)],
    'a negative range in point' => [fn () => EditRange::make(-0.5, 4.0)],
    'an unknown codec' => [fn () => EditOperation::extractAudio('flac')],
    'kbps under 32' => [fn () => EditOperation::extractAudio('aac', 31)],
    'kbps over 512' => [fn () => EditOperation::extractAudio('aac', 513)],
    'a negative crop origin' => [fn () => EditOperation::crop(-2, 0, 100, 100)],
    'an odd crop width' => [fn () => EditOperation::crop(0, 0, 101, 100)],
    'a crop height under 2' => [fn () => EditOperation::crop(0, 0, 100, 0)],
    'an odd scale height' => [fn () => EditOperation::scale(100, 101)],
    'an unknown fit' => [fn () => EditOperation::scale(100, 100, 'zoom')],
]);

it('holds the shape rules of an edit before it is sent', function (Closure $build): void {
    expect($build)->toThrow(InvalidConfigurationException::class);
})->with([
    'two range operations' => [fn () => EditSpec::make()->trim(0.0, 1.0)->concat([EditRange::make(2.0, 3.0)])],
    'a range operation after a frame one' => [fn () => EditSpec::make()->crop(0, 0, 100, 100)->trim(0.0, 1.0)],
    'rewrap with crop' => [fn () => EditSpec::make()->rewrap()->crop(0, 0, 100, 100)],
    'crop then rewrap' => [fn () => EditSpec::make()->crop(0, 0, 100, 100)->rewrap()],
    'rewrap with scale' => [fn () => EditSpec::make()->rewrap()->scale(100, 100)],
    'rewrap with extract_audio' => [fn () => EditSpec::make()->rewrap()->extractAudio()],
    'extract_audio with crop' => [fn () => EditSpec::make()->extractAudio()->crop(0, 0, 100, 100)],
    'scale then extract_audio' => [fn () => EditSpec::make()->scale(100, 100)->extractAudio()],
    'more than 32 operations' => [function (): EditSpec {
        $spec = EditSpec::make();

        for ($i = 0; $i < 33; $i++) {
            $spec = $spec->crop(0, 0, 100, 100);
        }

        return $spec;
    }],
    'no operation at all' => [fn () => EditSpec::make()->toArray()],
]);

it('keeps a range operation first and allows crop then scale in array order', function (): void {
    $spec = EditSpec::make()->trim(0.0, 10.0)->crop(0, 0, 100, 100)->scale(50, 50);

    expect(array_map(static fn (EditOperation $operation): string => $operation->op(), $spec->operations()))->toBe(['trim', 'crop', 'scale'])
        ->and($spec->needsMediaEdits())->toBeTrue()
        ->and(EditSpec::make()->trim(0.0, 10.0)->needsMediaEdits())->toBeFalse()
        ->and(EditSpec::make()->extractAudio()->extractsAudio())->toBeTrue();
});

it('builds a media edit request with the clip target, the told key and the node defaults omitted', function (): void {
    $request = MediaEditRequest::make(
        JobSource::prefix('tsp-vod', 'replays/941'),
        EditSpec::make()->trim(120.0, 148.5),
        JobTarget::mediaEdit('tsp-clips', 'clips/941/c1.mp4', '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
        'clip-5521',
    );

    expect($request->toArray())->toBe([
        'source' => ['destination_ref' => 'tsp-vod', 'prefix' => 'replays/941'],
        'operations' => [['op' => 'trim', 'in_seconds' => 120.0, 'out_seconds' => 148.5]],
        'targets' => ['media_edit' => ['id' => '01J8ZCK7T4N2QWX6VM3RKB9PD8', 'destination_ref' => 'tsp-clips', 'key' => 'clips/941/c1.mp4']],
        'idempotency_key' => 'clip-5521',
    ]);
});

it('renders every optional request field, an HLS ladder with its ladder name', function (): void {
    $body = MediaEditRequest::make(
        JobSource::url('https://uploads.example.net/abc.mov'),
        EditSpec::make()->trim(0.0, 30.0)->frameAccurate(),
        JobTarget::mediaEdit(JobSignedTarget::make('us-east-1', 'clips'), 'clip.mov', '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
        'clip-1',
    )
        ->hlsLadder(JobTarget::hlsLadder('tsp-clips', 'clips/hls', id: '01J8ZCK7T4N2QWX6VM3RKB9PD9'), 'vod-1080p')
        ->container('mov')
        ->callbackUrl('https://app.example.com/hook')
        ->priority('high')
        ->labels(['clip_id' => '1'])
        ->timeoutSeconds(600)
        ->toArray();

    expect($body['cut'])->toBe(['mode' => 'frame_accurate'])
        ->and($body['ladder'])->toBe('vod-1080p')
        ->and($body['container'])->toBe('mov')
        ->and($body['callback_url'])->toBe('https://app.example.com/hook')
        ->and($body['priority'])->toBe('high')
        ->and($body['labels'])->toBe(['clip_id' => '1'])
        ->and($body['timeout_seconds'])->toBe(600)
        ->and(array_keys($body['targets']))->toBe(['media_edit', 'hls_ladder'])
        ->and($body['targets']['media_edit']['signed']['bucket'])->toBe('clips')
        ->and($body)->not->toHaveKey('destination');

    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/MediaEditRequest', 'The rendered edit');
});

it('refuses what an edit request can see is wrong before it is sent', function (Closure $build): void {
    expect($build)->toThrow(InvalidConfigurationException::class);
})->with([
    'an empty idempotency key' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), '')],
    'an idempotency key over 255 bytes' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), str_repeat('k', 256))],
    'a target that is not a media edit' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::probe('c', 'k'), 'x')],
    'an hls target that is not a ladder' => [fn () => MediaEditTargets::make(JobTarget::mediaEdit('c', 'k'), JobTarget::probe('c', 'p'))],
    'an unknown container' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), 'x')->container('avi')],
    'an audio container on a video edit' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), 'x')->container('m4a')->toArray()],
    'a video container on extract_audio' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->extractAudio(), JobTarget::mediaEdit('c', 'k'), 'x')->container('mp4')->toArray()],
    'a blank ladder' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), 'x')->hlsLadder(JobTarget::hlsLadder('c', 'p'), ' ')],
    'a relative callback url' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), 'x')->callbackUrl('/hook')],
    'a priority outside the set' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), 'x')->priority('urgent')],
    'a timeout under 60 seconds' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), 'x')->timeoutSeconds(59)],
    'too many labels' => [fn () => MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->rewrap(), JobTarget::mediaEdit('c', 'k'), 'x')->labels(array_fill_keys(range(1, 17), 'v'))],
]);

it('lets an audio container carry an extract_audio edit', function (): void {
    $body = MediaEditRequest::make(JobSource::prefix('a', 'b'), EditSpec::make()->extractAudio('opus'), JobTarget::mediaEdit('c', 'k.ogg'), 'x')->container('ogg')->toArray();

    expect($body['container'])->toBe('ogg');
});

it('redacts a presigned URL in a source and in a range source, in toRedactedArray() only', function (): void {
    $request = MediaEditRequest::make(
        JobSource::url(EDIT_PRESIGNED),
        EditSpec::make()->concat([EditRange::make(0.0, 5.0), EditRange::make(1.0, 2.0, JobSource::url(EDIT_PRESIGNED))]),
        JobTarget::mediaEdit('tsp-clips', 'clips/c1.mp4'),
        'clip-9',
    );

    expect(json_encode($request->toArray()))->toContain('deadbeefsig')
        ->and(json_encode($request->toRedactedArray()))->not->toContain('deadbeefsig')
        ->and($request->toRedactedArray()['operations'][0]['ranges'][1]['source'])->toBe(['url' => 'https://uploads.example.net/abc.mov'])
        ->and($request->toRedactedArray()['source'])->toBe(['url' => 'https://uploads.example.net/abc.mov']);

    $job = Spec::make(JobSource::prefix('tsp-vod', 'replays/941'), 'job-9')
        ->edit(EditSpec::make()->concat([EditRange::make(0.0, 5.0, JobSource::url(EDIT_PRESIGNED))]))
        ->target(JobTarget::mediaEdit('tsp-clips', 'c.mp4'));

    expect(json_encode($job->toArray()))->toContain('deadbeefsig')
        ->and(json_encode($job->toRedactedArray()))->not->toContain('deadbeefsig');
});

it('adds an edit and a media edit output to a transcode job, range operations only', function (): void {
    $job = Spec::make(JobSource::prefix('tsp-vod', 'replays/941', '1080p'), 'edit-941')
        ->edit(EditSpec::make()->trim(10.0, 20.0))
        ->container('mkv')
        ->ladder('vod-1080p')
        ->target(JobTarget::mediaEdit('tsp-clips', 'c.mkv'), JobTarget::hlsLadder('tsp-clips', 'c'));

    $body = $job->toArray();

    expect($body['outputs'])->toBe(['media_edit', 'hls_ladder'])
        ->and($body['edit'])->toBe(['operations' => [['op' => 'trim', 'in_seconds' => 10.0, 'out_seconds' => 20.0]]])
        ->and($body['container'])->toBe('mkv')
        ->and($body['source']['prefix'])->toBe('replays/941')
        ->and(Spec::GENERATION_KINDS[Spec::OUTPUT_MEDIA_EDIT])->toBe(ArtifactStatus::KIND_JOB_MEDIA_EDIT);

    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/Spec', 'The edit job');
});

it('refuses the edit rules a transcode job states on the request alone', function (Closure $build): void {
    expect($build)->toThrow(InvalidConfigurationException::class);
})->with([
    'a frame operation on the job route' => [fn () => Spec::make(JobSource::prefix('a', 'b'), 'k')->edit(EditSpec::make()->crop(0, 0, 100, 100))],
    'a rewrap on the job route' => [fn () => Spec::make(JobSource::prefix('a', 'b'), 'k')->edit(EditSpec::make()->rewrap())],
    'extract_audio on the job route' => [fn () => Spec::make(JobSource::prefix('a', 'b'), 'k')->edit(EditSpec::make()->extractAudio())],
    'a media edit output with no edit' => [fn () => Spec::make(JobSource::destinationRef('a', 'b'), 'k')->target(JobTarget::mediaEdit('c', 'k.mp4'))->toArray()],
    'a container with no media edit output' => [fn () => Spec::make(JobSource::destinationRef('a', 'b'), 'k')->edit(EditSpec::make()->trim(0.0, 1.0))->container('mkv')->target(JobTarget::probe('c', 'p.json'))->toArray()],
    'a prefix source with no edit' => [fn () => Spec::make(JobSource::prefix('a', 'b'), 'k')->target(JobTarget::probe('c', 'p.json'))->toArray()],
    'an unknown container' => [fn () => Spec::make(JobSource::destinationRef('a', 'b'), 'k')->container('avi')],
]);

it('is a KEY target for the job_media_edit generation, never a prefix', function (): void {
    $target = JobTarget::mediaEdit('tsp-clips', 'clips/c1.mp4', '01J8ZCK7T4N2QWX6VM3RKB9PD8');
    $plan = $target->toPlanTarget();

    expect($target->output())->toBe(Spec::OUTPUT_MEDIA_EDIT)
        ->and($target->key())->toBe('clips/c1.mp4')
        ->and($target->prefix())->toBeNull()
        ->and($plan->artifact())->toBe(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and($plan->key())->toBe('clips/c1.mp4')
        ->and($plan->prefix())->toBeNull()
        ->and(PlanTarget::jobMediaEdit('tsp-clips', 'k.mp4')->artifact())->toBe(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and(PlanTarget::forKind(ArtifactStatus::KIND_JOB_MEDIA_EDIT, 'tsp-clips', 'k.mp4')->key())->toBe('k.mp4')
        ->and(PlanTarget::forKind(ArtifactStatus::KIND_JOB_MEDIA_EDIT, 'tsp-clips', 'k.mp4')->prefix())->toBeNull()
        ->and(ArtifactStatus::JOB_KINDS)->toContain(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and(ArtifactStatus::KINDS)->toContain(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and(ArtifactStatus::PREFIX_KINDS)->not->toContain(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and(fn () => PlanTarget::forKind(ArtifactStatus::KIND_JOB_MEDIA_EDIT, 'tsp-clips', 'k.mp4', 'playlist.m3u8'))->toThrow(InvalidConfigurationException::class);
});

it('reads the media edit answer, 202 or 200, with its cut and operations as submitted', function (): void {
    $body = [
        'id' => 'job_01J8ZCA2R7F3H9NQ4KTX8VD5YC', 'state' => 'running', 'priority' => 'low',
        'submitted_at' => '2026-11-08T19:31:12.044Z', 'idempotency_key' => 'clip-5521', 'kind' => 'media_edit',
        'cut' => ['mode' => 'frame_accurate'],
        'operations' => [['op' => 'trim', 'in_seconds' => 120, 'out_seconds' => 148.5], ['op' => 'scale', 'width' => 1080, 'height' => 1920, 'fit' => 'cover']],
    ];

    $created = MediaEditAccepted::fromArray($body, created: true);
    $repeat = MediaEditAccepted::fromArray($body);

    expect($created->created)->toBeTrue()
        ->and($repeat->created)->toBeFalse()
        ->and($created->id())->toBe('job_01J8ZCA2R7F3H9NQ4KTX8VD5YC')
        ->and($created->state())->toBe(JobStatus::STATE_RUNNING)
        ->and($created->priority())->toBe('low')
        ->and($created->kind())->toBe(MediaEditAccepted::KIND)
        ->and($created->idempotencyKey())->toBe('clip-5521')
        ->and($created->submittedAt()->format('Y-m-d'))->toBe('2026-11-08')
        ->and($created->cut()->mode())->toBe(EditCut::MODE_FRAME_ACCURATE)
        ->and($created->operations())->toHaveCount(2)
        ->and($created->operations()[0]->op())->toBe('trim')
        ->and($created->operations()[0]->inSeconds())->toBe(120.0)
        ->and($created->operations()[0]->outSeconds())->toBe(148.5)
        ->and($created->operations()[1]->width())->toBe(1080)
        ->and($created->operations()[1]->height())->toBe(1920)
        ->and($created->operations()[1]->fit())->toBe('cover')
        ->and($created->toArray())->toBe($body)
        ->and(MediaEditAccepted::fromArray([...$body, 'idempotency_key' => null])->idempotencyKey())->toBeNull();
});

it('reads a concat operation echoed with a redacted range source', function (): void {
    $operation = EditOperation::fromArray(['op' => 'concat', 'ranges' => [
        ['in_seconds' => 1, 'out_seconds' => 2, 'source' => ['url' => EDIT_PRESIGNED]],
        ['in_seconds' => 3, 'out_seconds' => 4],
    ]]);

    expect($operation->ranges())->toHaveCount(2)
        ->and($operation->ranges()[0]->source()?->sourceUrl())->toBe('https://uploads.example.net/abc.mov')
        ->and($operation->ranges()[1]->source())->toBeNull()
        ->and(json_encode($operation->toArray()))->not->toContain('deadbeefsig');
});

it('reads where the cut landed from a status, and null for a job without an edit', function (): void {
    $withEdit = JobStatus::fromArray(['id' => 'job_1', 'edit' => editBlock()])->edit();

    expect($withEdit)->toBeInstanceOf(JobEdit::class)
        ->and($withEdit?->mode())->toBe('frame_accurate')
        ->and($withEdit?->inputs())->toHaveCount(2)
        ->and($withEdit?->inputs()[0]->kind())->toBe('hls')
        ->and($withEdit?->inputs()[0]->location())->toBe('s3://tsp-vod/replays/941')
        ->and($withEdit?->inputs()[0]->bytes())->toBeNull()
        ->and($withEdit?->inputs()[0]->etag())->toBeNull()
        ->and($withEdit?->inputs()[1]->bytes())->toBe(1893746152)
        ->and($withEdit?->inputs()[1]->etag())->toBe('"abc"')
        ->and($withEdit?->ranges())->toHaveCount(2)
        ->and($withEdit?->ranges()[0]->input())->toBe(0)
        ->and($withEdit?->ranges()[0]->requestedInSeconds())->toBe(120.0)
        ->and($withEdit?->ranges()[0]->actualInSeconds())->toBe(118.4)
        ->and($withEdit?->ranges()[0]->requestedOutSeconds())->toBe(148.5)
        ->and($withEdit?->ranges()[0]->actualOutSeconds())->toBe(148.5)
        ->and($withEdit?->ranges()[0]->outputOffsetSeconds())->toBe(0.0)
        ->and($withEdit?->ranges()[0]->reencodedGops())->toBe(2)
        ->and($withEdit?->ranges()[0]->reencodedWhole())->toBeFalse()
        ->and($withEdit?->ranges()[1]->outputOffsetSeconds())->toBe(30.1)
        ->and($withEdit?->ranges()[1]->reencodedWhole())->toBeTrue()
        ->and($withEdit?->request()->cut()->mode())->toBe('frame_accurate')
        ->and($withEdit?->request()->operations()[0]->ranges()[1]->source()?->sourceUrl())->toBe('https://uploads.example.net/abc.mov');

    expect(JobStatus::fromArray(['id' => 'job_1'])->edit())->toBeNull();
});

it('reads the HLS replay a job source report names', function (): void {
    $report = JobSourceReport::fromArray(['destination_ref' => 'tsp-vod', 'prefix' => 'replays/941', 'rendition' => '720p']);

    expect($report->prefix())->toBe('replays/941')
        ->and($report->rendition())->toBe('720p')
        ->and($report->key())->toBeNull()
        ->and(JobSourceReport::fromArray(['destination_ref' => 'a', 'key' => 'b'])->prefix())->toBeNull()
        ->and(JobSourceReport::fromArray(['destination_ref' => 'a', 'key' => 'b'])->rendition())->toBeNull();
});

it('decodes an edit block on job.completed, validated against the schema, and null without one', function (): void {
    $payload = json_decode((string) file_get_contents(ContractDir::example('job.completed')), true, 512, JSON_THROW_ON_ERROR);

    /** @var JobCompleted $plain */
    $plain = EventMap::make($payload);

    expect($plain->edit())->toBeNull();

    $payload['edit'] = editBlock();
    $payload['source'] = ['destination_ref' => 'tsp-vod', 'prefix' => 'replays/941', 'rendition' => '720p'];
    $payload['outputs'][0]['kind'] = 'media_edit';

    SchemaValidator::forSchema('job.completed')->assertValid(json_decode((string) json_encode($payload), false), null, 'job.completed with an edit');

    /** @var JobCompleted $event */
    $event = EventMap::make($payload);

    expect($event->edit()?->ranges()[0]->actualInSeconds())->toBe(118.4)
        ->and($event->edit()?->inputs()[1]->kind())->toBe('file')
        ->and($event->source()->prefix())->toBe('replays/941')
        ->and($event->source()->rendition())->toBe('720p');
});
