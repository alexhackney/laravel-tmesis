<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Jobs\AudioOpts;
use AlexHackney\Tmesis\Data\Jobs\JobSignedTarget;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\MP4Opts;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Data\Jobs\ThumbnailOpts;
use AlexHackney\Tmesis\Data\Jobs\TranscriptOpts;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

const SPEC_PRESIGNED = 'https://uploads.example.net/abc.mov?X-Amz-Credential=AKIAEXAMPLE&X-Amz-Signature=deadbeefsig';

function specBase(): Spec
{
    return Spec::make(JobSource::destinationRef('tsp-media', 'uploads/abc.mov'), 'video-941-v2');
}

it('renders the API.md 9.10 example request exactly, and it validates against Spec', function (): void {
    $spec = specBase()
        ->ladder('vod-1080p')
        ->target(
            JobTarget::hlsLadder('tsp-media', 'videos/941', id: '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
            JobTarget::mp4('tsp-media', 'videos/941/mp4', '01J8ZCK7T4N2QWX6VM3RKB9PD9'),
            JobTarget::thumbnails('tsp-assets', 'thumbs/941', '01J8ZCK7T4N2QWX6VM3RKB9PDA'),
            JobTarget::transcript('tsp-media', 'videos/941/captions', '01J8ZCK7T4N2QWX6VM3RKB9PDB'),
        )
        ->callbackUrl('https://app.example.com/api/webhooks/tmesis')
        ->priority('normal')
        ->transcript(TranscriptOpts::make('deepgram', 'en', ['vtt', 'json']))
        ->labels(['video_id' => '941']);

    $body = $spec->toArray();

    expect($body)->toEqual([
        'source' => ['destination_ref' => 'tsp-media', 'key' => 'uploads/abc.mov'],
        'outputs' => ['hls_ladder', 'mp4', 'thumbnails', 'transcript'],
        'targets' => [
            'hls_ladder' => [['id' => '01J8ZCK7T4N2QWX6VM3RKB9PD8', 'destination_ref' => 'tsp-media', 'prefix' => 'videos/941']],
            'mp4' => [['id' => '01J8ZCK7T4N2QWX6VM3RKB9PD9', 'destination_ref' => 'tsp-media', 'prefix' => 'videos/941/mp4']],
            'thumbnails' => [['id' => '01J8ZCK7T4N2QWX6VM3RKB9PDA', 'destination_ref' => 'tsp-assets', 'prefix' => 'thumbs/941']],
            'transcript' => [['id' => '01J8ZCK7T4N2QWX6VM3RKB9PDB', 'destination_ref' => 'tsp-media', 'prefix' => 'videos/941/captions']],
        ],
        'idempotency_key' => 'video-941-v2',
        'ladder' => 'vod-1080p',
        'callback_url' => 'https://app.example.com/api/webhooks/tmesis',
        'priority' => 'normal',
        'transcript' => ['provider' => 'deepgram', 'language' => 'en', 'formats' => ['vtt', 'json']],
        'labels' => ['video_id' => '941'],
    ]);

    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/Spec', 'The Spec body');
});

it('derives the outputs from the targets in first-given order, and files a repeat output\'s targets together', function (): void {
    $spec = specBase()
        ->target(JobTarget::audio('tsp-media', 'a/audio.m4a', 'id-1'))
        ->target(JobTarget::probe('tsp-media', 'a/probe.json', 'id-2'), JobTarget::audio('backup', 'b/audio.m4a', 'id-3'));

    expect($spec->outputs())->toBe(['audio', 'probe'])
        ->and(array_map(static fn (JobTarget $t): string => $t->id(), $spec->targets()['audio']))->toBe(['id-1', 'id-3'])
        ->and($spec->toArray())->not->toHaveKey('ladder');
});

it('refuses what the node refuses on the request alone', function (Closure $build, string $key): void {
    expect($build)->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'no output' => [fn () => specBase()->toArray(), '[outputs]'],
    'hls without a ladder' => [fn () => specBase()->target(JobTarget::hlsLadder('d', 'p'))->toArray(), '[ladder]'],
    'mp4 without a ladder' => [fn () => specBase()->target(JobTarget::mp4('d', 'p'))->toArray(), '[ladder]'],
    'an empty idempotency key' => [fn () => Spec::make(JobSource::url('https://x.example/a'), ''), '[idempotency_key]'],
    'a 256-byte idempotency key' => [fn () => Spec::make(JobSource::url('https://x.example/a'), str_repeat('k', 256)), '[idempotency_key]'],
    'a target id twice' => [fn () => specBase()->target(JobTarget::audio('d', 'k', 'same'), JobTarget::probe('d', 'k2', 'same')), '[targets.probe]'],
    'an unknown priority' => [fn () => specBase()->priority('urgent'), '[priority]'],
    'a relative callback' => [fn () => specBase()->callbackUrl('/webhooks'), '[callback_url]'],
    'a ftp callback' => [fn () => specBase()->callbackUrl('ftp://x.example/'), '[callback_url]'],
    'a short timeout' => [fn () => specBase()->timeoutSeconds(59), '[timeout_seconds]'],
    'a long timeout' => [fn () => specBase()->timeoutSeconds(172801), '[timeout_seconds]'],
    'seventeen labels' => [fn () => specBase()->labels(array_fill_keys(range('a', 'q'), 'v')), '[labels]'],
    'a long label key' => [fn () => specBase()->labels([str_repeat('k', 65) => 'v']), '[labels.'],
    'a long label value' => [fn () => specBase()->labels(['k' => str_repeat('v', 257)]), '[labels.k]'],
    'an empty ladder' => [fn () => specBase()->ladder(' '), '[ladder]'],
    'a http source' => [fn () => JobSource::url('http://x.example/a.mov'), '[source.url]'],
    'a relative source' => [fn () => JobSource::url('a.mov'), '[source.url]'],
    'an empty destination' => [fn () => JobSource::destinationRef('', 'k'), '[source.destination_ref]'],
    'a destination with no key' => [fn () => JobSource::destinationRef('d', ''), '[source.key]'],
    'an unknown audio format' => [fn () => AudioOpts::make('flac'), '[audio.format]'],
    'a low audio bitrate' => [fn () => AudioOpts::make(bitrateKbps: 31), '[audio.bitrate_kbps]'],
    'a high audio bitrate' => [fn () => AudioOpts::make(bitrateKbps: 513), '[audio.bitrate_kbps]'],
    'a zero interval' => [fn () => ThumbnailOpts::make(0), '[thumbnails.interval_seconds]'],
    'an odd width' => [fn () => ThumbnailOpts::make(width: 641), '[thumbnails.width]'],
    'a narrow width' => [fn () => ThumbnailOpts::make(width: 14), '[thumbnails.width]'],
    'a zero count' => [fn () => ThumbnailOpts::make(count: 0), '[thumbnails.count]'],
    'a negative offset' => [fn () => ThumbnailOpts::make(atSeconds: [-1]), '[thumbnails.at_seconds]'],
    'a percent past 100' => [fn () => ThumbnailOpts::make(atPercent: [101]), '[thumbnails.at_percent]'],
    'too many random frames' => [fn () => ThumbnailOpts::make(randomCount: 1001), '[thumbnails.random_count]'],
    'no rungs' => [fn () => MP4Opts::make([]), '[mp4.rungs]'],
    'an empty rung' => [fn () => MP4Opts::make(['1080p', '']), '[mp4.rungs[1]]'],
    'no transcript formats' => [fn () => TranscriptOpts::make(formats: []), '[transcript.formats]'],
    'an unknown transcript format' => [fn () => TranscriptOpts::make(formats: ['txt']), '[transcript.formats]'],
    'a signed target addressing' => [fn () => JobSignedTarget::make('r', 'b', addressing: 'dns'), '[signed.addressing]'],
    'a signed target part size' => [fn () => JobSignedTarget::make('r', 'b', partSizeMb: 4), '[signed.part_size_mb]'],
    'a signed target concurrency' => [fn () => JobSignedTarget::make('r', 'b', uploadConcurrency: 65), '[signed.upload_concurrency]'],
]);

it('renders only what was set on each option object, and omits an empty one', function (): void {
    $body = specBase()
        ->target(JobTarget::thumbnails('d', 'p'))
        ->audio(AudioOpts::make())
        ->thumbnails(ThumbnailOpts::make(width: 320))
        ->transcript(TranscriptOpts::make(language: 'es'))
        ->labels([])
        ->toArray();

    expect($body)->not->toHaveKey('audio')
        ->and($body)->not->toHaveKey('labels')
        ->and($body['thumbnails'])->toBe(['width' => 320])
        ->and($body['transcript'])->toBe(['language' => 'es']);
});

it('reads back every option it was given', function (): void {
    $thumbs = ThumbnailOpts::make(2.5, 640, 10, [1, 2], [50.0], 4);
    $audio = AudioOpts::make('mp3', 192);
    $mp4 = MP4Opts::make(['1080p']);
    $transcript = TranscriptOpts::make('deepgram', 'en', ['vtt', 'vtt', 'srt']);

    expect([$thumbs->intervalSeconds(), $thumbs->width(), $thumbs->count(), $thumbs->atSeconds(), $thumbs->atPercent(), $thumbs->randomCount()])
        ->toBe([2.5, 640, 10, [1.0, 2.0], [50.0], 4])
        ->and($thumbs->toArray())->toBe(['interval_seconds' => 2.5, 'width' => 640, 'count' => 10, 'at_seconds' => [1.0, 2.0], 'at_percent' => [50.0], 'random_count' => 4])
        ->and([$audio->format(), $audio->bitrateKbps()])->toBe(['mp3', 192])
        ->and($mp4->rungs())->toBe(['1080p'])
        ->and($mp4->toArray())->toBe(['rungs' => ['1080p']])
        ->and([$transcript->provider(), $transcript->language(), $transcript->formats()])->toBe(['deepgram', 'en', ['vtt', 'srt']]);
});

it('builds each target with the location field its output takes', function (): void {
    $signed = JobSignedTarget::make('us-east-1', 'tsp-assets', 'https://s3.example.net', 'virtual', 32, 4);

    $hls = JobTarget::hlsLadder($signed, 'videos/941', 'master.m3u8', 'id-h');
    $audio = JobTarget::audio('tsp-media', 'videos/941/audio.m4a');

    expect($hls->output())->toBe('hls_ladder')
        ->and($hls->signed())->toBe($signed)
        ->and($hls->destinationRef())->toBeNull()
        ->and($hls->toArray())->toBe([
            'id' => 'id-h',
            'signed' => ['region' => 'us-east-1', 'bucket' => 'tsp-assets', 'endpoint' => 'https://s3.example.net', 'addressing' => 'virtual', 'part_size_mb' => 32, 'upload_concurrency' => 4],
            'prefix' => 'videos/941',
            'master_name' => 'master.m3u8',
        ])
        ->and($audio->key())->toBe('videos/941/audio.m4a')
        ->and($audio->prefix())->toBeNull()
        ->and($audio->masterName())->toBeNull()
        ->and(strlen($audio->id()))->toBe(26)
        ->and([$signed->region(), $signed->bucket(), $signed->endpoint(), $signed->addressing(), $signed->partSizeMb(), $signed->uploadConcurrency()])
        ->toBe(['us-east-1', 'tsp-assets', 'https://s3.example.net', 'virtual', 32, 4]);

    foreach ([JobTarget::mp4('d', 'p'), JobTarget::thumbnails('d', 'p'), JobTarget::transcript('d', 'p')] as $target) {
        expect($target->prefix())->toBe('p')->and($target->key())->toBeNull();
    }

    expect(JobTarget::probe('d', 'k')->key())->toBe('k');
});

it('turns a job target into the plan target the node files for its generation', function (): void {
    $plan = JobTarget::hlsLadder(JobSignedTarget::make('us-east-1', 'b', partSizeMb: 8), 'videos/941', 'm.m3u8', 'id-1')->toPlanTarget();

    expect($plan->artifact())->toBe(ArtifactStatus::KIND_JOB_HLS)
        ->and($plan->id())->toBe('id-1')
        ->and($plan->prefix())->toBe('videos/941')
        ->and($plan->masterName())->toBe('m.m3u8')
        ->and($plan->signed())->toBeInstanceOf(SignedDestination::class)
        ->and($plan->signed()?->partSizeMb())->toBe(8);

    $probe = JobTarget::probe('tsp-media', 'videos/941/probe.json')->toPlanTarget();

    expect($probe->artifact())->toBe(ArtifactStatus::KIND_JOB_PROBE)
        ->and($probe->key())->toBe('videos/941/probe.json')
        ->and($probe->destinationRef())->toBe('tsp-media');
});

it('keeps a presigned source URL out of the redacted body, and only there', function (): void {
    $spec = Spec::make(JobSource::url(SPEC_PRESIGNED), 'k')->target(JobTarget::probe('d', 'k'));

    expect($spec->toArray()['source'])->toBe(['url' => SPEC_PRESIGNED])
        ->and($spec->toRedactedArray()['source'])->toBe(['url' => 'https://uploads.example.net/abc.mov'])
        ->and(json_encode($spec->toRedactedArray()))->not->toContain('deadbeefsig')
        ->and($spec->source()->sourceUrl())->toBe(SPEC_PRESIGNED)
        ->and($spec->source()->destination())->toBeNull()
        ->and($spec->idempotencyKey())->toBe('k');

    $destination = JobSource::destinationRef('tsp-media', 'uploads/abc.mov');

    expect($destination->redacted())->toBe($destination->toArray())
        ->and($destination->key())->toBe('uploads/abc.mov')
        ->and($destination->sourceUrl())->toBeNull();
});

it('names every v1 output with its generation kind, and none of the reserved ones', function (): void {
    expect(Spec::OUTPUTS)->toBe(array_keys(Spec::GENERATION_KINDS))
        ->and(Spec::OUTPUTS)->not->toContain('storyboard')
        ->and(Spec::OUTPUTS)->not->toContain('channel_ready');
});

it('refuses a source URL carrying credentials', function (string $url): void {
    expect(fn () => JobSource::url($url))
        ->toThrow(InvalidConfigurationException::class, '[source.url] is invalid: credentials in a source URL are not accepted; use a presigned URL');
})->with([
    'user and password' => ['https://user:password@uploads.example.net/abc.mov'],
    'user alone' => ['https://token@uploads.example.net/abc.mov'],
]);

it('strips userinfo, query and fragment in redacted() even on a URL that bypassed the constructor', function (): void {
    $source = (new ReflectionClass(JobSource::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(JobSource::class, '__construct'))->invoke(
        $source, null, null, 'https://user:password@uploads.example.net:8443/abc.mov?X-Amz-Signature=deadbeefsig#frag',
    );

    expect($source->redacted())->toBe(['url' => 'https://uploads.example.net:8443/abc.mov'])
        ->and(json_encode($source->redacted()))->not->toContain('password')
        ->and(json_encode($source->redacted()))->not->toContain('deadbeefsig');
});
