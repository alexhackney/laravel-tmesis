<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Keys\KeyEpoch;

it('serialises nothing until something is set', function (): void {
    expect(StreamSpec::make()->toArray())->toBe([])
        ->and(StreamSpec::make()->mentionsNothing())->toBeTrue();
});

it('serialises only the fields that were set', function (): void {
    expect(StreamSpec::make()->ladder('broadcast-720p')->toArray())->toBe(['ladder' => 'broadcast-720p']);
});

it('keeps an explicit null as an instruction', function (): void {
    // Three states, and the middle one is the whole reason this class exists: not
    // mentioned, set, and mentioned-as-null which CLEARS the field.
    expect(StreamSpec::make()->mentions('min_key_epoch'))->toBeFalse()
        ->and(StreamSpec::make()->minKeyEpoch(null)->mentions('min_key_epoch'))->toBeTrue()
        ->and(StreamSpec::make()->minKeyEpoch(null)->toArray())->toBe(['min_key_epoch' => null])
        ->and(StreamSpec::make()->webhookUrl(null)->toArray())->toBe(['webhook_url' => null]);
});

it('survives being encoded, so a cleared field goes out as JSON null', function (): void {
    expect(json_encode(StreamSpec::make()->minKeyEpoch(null)->webhookUrl(null)->toArray()))
        ->toBe('{"min_key_epoch":null,"webhook_url":null}');
});

it('takes a KeyEpoch or a plain int for the key epoch floor', function (): void {
    expect(StreamSpec::make()->minKeyEpoch(KeyEpoch::fromInt(7))->toArray())->toBe(['min_key_epoch' => 7])
        ->and(StreamSpec::make()->minKeyEpoch(7)->toArray())->toBe(['min_key_epoch' => 7]);
});

it('is immutable: a setter returns a new spec', function (): void {
    $base = StreamSpec::make()->ladder('broadcast-720p');
    $other = $base->segmentSeconds(6);

    expect($base->toArray())->toBe(['ladder' => 'broadcast-720p'])
        ->and($other->toArray())->toBe(['ladder' => 'broadcast-720p', 'segment_seconds' => 6]);
});

it('refuses values the node would refuse, before a request is built', function (callable $build, string $key) {
    expect($build)->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'segment_seconds under 1' => [fn () => StreamSpec::make()->segmentSeconds(0), 'segment_seconds'],
    'segment_seconds over 30' => [fn () => StreamSpec::make()->segmentSeconds(31), 'segment_seconds'],
    'a negative key epoch floor' => [fn () => StreamSpec::make()->minKeyEpoch(-1), 'min_key_epoch'],
    'an empty webhook url' => [fn () => StreamSpec::make()->webhookUrl(''), 'webhook_url'],
    'a seventeenth label' => [fn () => StreamSpec::make()->labels(array_combine(
        array_map(static fn (int $i): string => "k{$i}", range(1, 17)),
        array_fill(0, 17, 'v'),
    )), 'labels'],
    'a label value over 256 bytes' => [fn () => StreamSpec::make()->labels(['show' => str_repeat('x', 257)]), 'labels.show'],
]);

it('serialises relays as an array of destination bodies', function (): void {
    $spec = StreamSpec::make()->relays([
        RelayInput::make('srt://backup-den-02.internal:8890?passphrase=hunter2'),
        RelayInput::make('rtmp://cdn.example.com/live/abc123', id: 'cdn', enabled: false),
    ]);

    expect($spec->toArray())->toBe(['relays' => [
        ['url' => 'srt://backup-den-02.internal:8890?passphrase=hunter2'],
        ['url' => 'rtmp://cdn.example.com/live/abc123', 'id' => 'cdn', 'enabled' => false],
    ]]);
});

it('redacts every relay URL in the loggable form of the body', function (): void {
    $spec = StreamSpec::make()
        ->ladder('broadcast-720p')
        ->relays([RelayInput::make('srt://backup-den-02.internal:8890?passphrase=hunter2')]);

    $redacted = $spec->toRedactedArray();

    expect(json_encode($redacted))->not->toContain('hunter2')
        ->and($redacted['relays'][0]['url'])->toBe('srt://backup-den-02.internal:8890')
        ->and($redacted['ladder'])->toBe('broadcast-720p')
        // The real body still carries it: the node has to dial it.
        ->and(json_encode($spec->toArray()))->toContain('hunter2');
});

it('offers no milestone field at all', function (string $method) {
    // dvr_window_seconds is 501 on both POST and PATCH, and encode.*, encryption,
    // delivery and recording are the same or are refused as unknown fields. A builder
    // that offered them would be offering an error. captions left this list at tmesis
    // v0.3.0; the block is four flat captions* methods, never a captions() taking an
    // array.
    expect(method_exists(StreamSpec::class, $method))->toBeFalse();
})->with([
    'dvrWindowSeconds', 'encode', 'captions', 'encryption', 'delivery', 'recording', 'probe', 'transcription',
]);

it('exposes exactly the seventeen writable fields', function (): void {
    $setters = array_values(array_filter(
        get_class_methods(StreamSpec::class),
        static fn (string $method): bool => ! in_array(
            $method,
            ['make', 'toArray', 'toRequestBody', 'toRedactedArray', 'mentions', 'mentionsNothing'],
            true,
        ),
    ));

    sort($setters);

    // The six flat fields, the four recording.ingest.* leaves and the two preview.*
    // leaves (rc7; destination_ref v0.6.0), the five captions.* leaves (v0.3.0;
    // keyterms v0.8.0), and fallback.end_seconds (v0.6.0). recording.master (422
    // unknown field since v0.8.0: the master is a request), the three reserved
    // fallback.* keys (501) and recording.ingest.container (422 unknown field) stay
    // off on purpose.
    expect($setters)->toBe([
        'captionsAdvertise', 'captionsEnabled', 'captionsKeyterms', 'captionsLanguages', 'captionsProvider',
        'fallbackEndSeconds',
        'ingestDestination', 'ingestDestinationRef', 'ingestRecordingEnabled', 'ingestRetentionHours',
        'labels', 'ladder', 'minKeyEpoch', 'previewEnabled', 'previewMaxMonitors',
        'relays', 'segmentSeconds', 'webhookUrl',
    ]);
});

it('serialises the recording and preview fields as nested blocks', function (): void {
    $spec = StreamSpec::make()
        ->ingestRecordingEnabled(true)
        ->ingestRetentionHours(48)
        ->ingestDestination('/mnt/archive/ingest')
        ->previewEnabled(false)
        ->previewMaxMonitors(10);

    // Two calls into recording.ingest are ONE object with three keys: the node reads
    // the dotted paths, and a builder that sent two half-objects would clobber one.
    expect($spec->toArray())->toBe([
        'recording' => ['ingest' => ['enabled' => true, 'retention_hours' => 48, 'destination' => '/mnt/archive/ingest']],
        'preview' => ['enabled' => false, 'max_monitors' => 10],
    ]);
});

it('sends a cleared destination as JSON null under recording.ingest', function (): void {
    expect(json_encode(StreamSpec::make()->ingestDestination(null)->toArray()))
        ->toBe('{"recording":{"ingest":{"destination":null}}}');
});

it('answers mentions() for a dotted path', function (): void {
    $spec = StreamSpec::make()->ingestDestination(null);

    expect($spec->mentions('recording'))->toBeTrue()
        ->and($spec->mentions('recording.ingest.destination'))->toBeTrue()
        ->and($spec->mentions('recording.ingest.enabled'))->toBeFalse()
        ->and($spec->mentions('preview.enabled'))->toBeFalse()
        ->and(StreamSpec::make()->mentions('recording.ingest.destination'))->toBeFalse();
});

it('accepts the one destination shape the node accepts since v0.4.0: an absolute path', function (string $destination): void {
    // s3://bucket/prefix was the second shape through tmesis v0.3.0. Destination WP1
    // (TMESIS-DELIVERY-3) made the node refuse any URL with 422, and bucket delivery
    // is a storage destination on the node now; the refusal case is below.
    expect(StreamSpec::make()->ingestDestination($destination)->toArray()['recording']['ingest']['destination'])
        ->toBe($destination);
})->with([
    'an absolute path' => ['/var/lib/tmesis/archive'],
    'an absolute path with a trailing slash' => ['/mnt/archive/ingest/'],
]);

it('refuses the recording and preview values the node refuses, with the node\'s sentence', function (callable $build, string $field, string $sentence): void {
    expect(fn () => $build())->toThrow(InvalidConfigurationException::class, $sentence)
        ->and(fn () => $build())->toThrow(InvalidConfigurationException::class, $field);
})->with([
    'retention 0' => [
        fn () => StreamSpec::make()->ingestRetentionHours(0),
        'recording.ingest.retention_hours',
        'zero would sweep the file the moment it lands',
    ],
    'max_monitors 0' => [
        fn () => StreamSpec::make()->previewMaxMonitors(0),
        'preview.max_monitors',
        'turn the preview off with preview.enabled instead',
    ],
    'destination empty' => [
        fn () => StreamSpec::make()->ingestDestination(''),
        'recording.ingest.destination',
        'null means the node default',
    ],
    'destination https' => [
        fn () => StreamSpec::make()->ingestDestination('https://x'),
        'recording.ingest.destination',
        'must be an absolute filesystem path; a destination name goes in `recording.ingest.destination_ref`, not here',
    ],
    'destination s3 (a v0.3.0 form, refused by the node since v0.4.0)' => [
        fn () => StreamSpec::make()->ingestDestination('s3://tsp-archive/ingest/'),
        'recording.ingest.destination',
        'a destination name goes in `recording.ingest.destination_ref`, not here',
    ],
    'destination relative' => [
        fn () => StreamSpec::make()->ingestDestination('relative/path'),
        'recording.ingest.destination',
        'must be an absolute filesystem path',
    ],
    'destination_ref empty' => [
        fn () => StreamSpec::make()->ingestDestinationRef(''),
        'recording.ingest.destination_ref',
        'must name a destination in storage.destinations, or null; null means the node default',
    ],
    'destination_ref with whitespace' => [
        fn () => StreamSpec::make()->ingestDestinationRef('csn vod'),
        'recording.ingest.destination_ref',
        'must name a destination in storage.destinations',
    ],
    'destination_ref beside a destination' => [
        fn () => StreamSpec::make()->ingestDestination('/mnt/archive')->ingestDestinationRef('csn-vod'),
        'recording.ingest.destination_ref',
        'cannot be set together with recording.ingest.destination: send the other as null to switch between a directory and a named destination',
    ],
    'destination beside a destination_ref' => [
        fn () => StreamSpec::make()->ingestDestinationRef('csn-vod')->ingestDestination('/mnt/archive'),
        'recording.ingest.destination_ref',
        'cannot be set together with recording.ingest.destination',
    ],
    'fallback end_seconds 0' => [
        fn () => StreamSpec::make()->fallbackEndSeconds(0),
        'fallback.end_seconds',
        "null means the node's fallback.end_seconds",
    ],
]);

it('serialises the four captions fields as one nested block', function (): void {
    $spec = StreamSpec::make()
        ->captionsEnabled(true)
        ->captionsAdvertise(false)
        ->captionsProvider('deepgram')
        ->captionsLanguages(['en-US']);

    expect($spec->toArray())->toBe([
        'captions' => ['enabled' => true, 'advertise' => false, 'provider' => 'deepgram', 'languages' => ['en-US']],
    ])
        ->and(json_encode($spec->toArray()))
        ->toBe('{"captions":{"enabled":true,"advertise":false,"provider":"deepgram","languages":["en-US"]}}');
});

it('sends each captions field on its own, leaving its siblings unmentioned', function (callable $build, string $path, mixed $value): void {
    /** @var StreamSpec $spec */
    $spec = $build();

    expect($spec->mentions($path))->toBeTrue()
        ->and($spec->toArray())->toBe(['captions' => [substr($path, strlen('captions.')) => $value]]);

    foreach (['captions.enabled', 'captions.advertise', 'captions.provider', 'captions.languages'] as $other) {
        if ($other !== $path) {
            expect($spec->mentions($other))->toBeFalse();
        }
    }
})->with([
    'enabled' => [fn () => StreamSpec::make()->captionsEnabled(false), 'captions.enabled', false],
    'advertise' => [fn () => StreamSpec::make()->captionsAdvertise(true), 'captions.advertise', true],
    'provider' => [fn () => StreamSpec::make()->captionsProvider('deepgram'), 'captions.provider', 'deepgram'],
    'languages' => [fn () => StreamSpec::make()->captionsLanguages(['en']), 'captions.languages', ['en']],
]);

it('sends a null provider as JSON null, which is the node\'s configured provider', function (): void {
    expect(json_encode(StreamSpec::make()->captionsProvider(null)->toArray()))
        ->toBe('{"captions":{"provider":null}}');
});

it('accepts an empty languages list, which is the node default, as a JSON array', function (): void {
    expect(json_encode(StreamSpec::make()->captionsLanguages([])->toArray()))
        ->toBe('{"captions":{"languages":[]}}');
});

it('re-indexes a languages map so it serialises as a JSON array', function (): void {
    expect(json_encode(StreamSpec::make()->captionsLanguages(['first' => 'en'])->toArray()))
        ->toBe('{"captions":{"languages":["en"]}}');
});

it('refuses more than one caption language with the node\'s sentence', function (): void {
    $build = fn () => StreamSpec::make()->captionsLanguages(['en', 'es']);

    // Verbatim from internal/api/streamvalidate.go validateCaptions at tmesis v0.3.0.
    expect($build)->toThrow(
        InvalidConfigurationException::class,
        'v1 captions one language per stream: send exactly one tag (multi-language is later; the layout is ready for it)',
    )->and($build)->toThrow(InvalidConfigurationException::class, 'captions.languages');
});

it('mirrors none of the node-fact caption refusals', function (): void {
    // A provider other than the node's, a provider of none, a passthrough node and a
    // ladder with no audio_only rung are all 422 at the node, and only the node knows
    // them (the grid-422 precedent). The builder sends them.
    expect(StreamSpec::make()->captionsProvider('whisper')->captionsEnabled(true)->toArray())
        ->toBe(['captions' => ['provider' => 'whisper', 'enabled' => true]]);
});

it('keeps the captions block beside the recording and preview blocks', function (): void {
    expect(StreamSpec::make()->previewEnabled(true)->captionsEnabled(true)->ingestRecordingEnabled(false)->toArray())
        ->toBe([
            'preview' => ['enabled' => true],
            'captions' => ['enabled' => true],
            'recording' => ['ingest' => ['enabled' => false]],
        ]);
});

it('renders an empty label map as a JSON object on the wire and nowhere else', function (): void {
    // labels([]) clears the set. `[]` is a JSON array, which the document's
    // `oneOf [object, null]` refuses and the node answers 422; `{}` is the clear.
    $spec = StreamSpec::make()->labels([])->ladder('abr-1080');

    expect(json_encode($spec->toRequestBody()))->toBe('{"labels":{},"ladder":"abr-1080"}')
        ->and($spec->toArray())->toBe(['labels' => [], 'ladder' => 'abr-1080'])
        ->and($spec->mentions('labels'))->toBeTrue();
});

it('leaves a non-empty label map and an unmentioned one alone in the request body', function (): void {
    expect(StreamSpec::make()->labels(['show' => '2041'])->toRequestBody())->toBe(['labels' => ['show' => '2041']])
        ->and(StreamSpec::make()->ladder('abr-1080')->toRequestBody())->toBe(['ladder' => 'abr-1080'])
        ->and(StreamSpec::make()->toRequestBody())->toBe([]);
});

it('names a destination through recording.ingest.destination_ref, and clears it with null', function (): void {
    expect(StreamSpec::make()->ingestDestinationRef('csn-vod')->toArray())
        ->toBe(['recording' => ['ingest' => ['destination_ref' => 'csn-vod']]])
        ->and(StreamSpec::make()->ingestDestinationRef(null)->toArray())
        ->toBe(['recording' => ['ingest' => ['destination_ref' => null]]])
        ->and(StreamSpec::make()->ingestDestinationRef('csn-vod')->mentions('recording.ingest.destination_ref'))->toBeTrue();
});

it('switches between a directory and a named destination by sending the other as null', function (): void {
    $toName = StreamSpec::make()->ingestDestination(null)->ingestDestinationRef('csn-vod');
    $toDirectory = StreamSpec::make()->ingestDestinationRef(null)->ingestDestination('/mnt/archive');

    expect($toName->toArray())->toBe(['recording' => ['ingest' => ['destination' => null, 'destination_ref' => 'csn-vod']]])
        ->and($toDirectory->toArray())->toBe(['recording' => ['ingest' => ['destination_ref' => null, 'destination' => '/mnt/archive']]]);
});

it('serialises fallback.end_seconds nested, and null as the node default', function (): void {
    expect(StreamSpec::make()->fallbackEndSeconds(600)->toArray())->toBe(['fallback' => ['end_seconds' => 600]])
        ->and(StreamSpec::make()->fallbackEndSeconds(1)->toArray())->toBe(['fallback' => ['end_seconds' => 1]])
        ->and(json_encode(StreamSpec::make()->fallbackEndSeconds(null)->toRequestBody()))->toBe('{"fallback":{"end_seconds":null}}');
});

it('never offers the reserved fallback fields', function (): void {
    foreach (['fallbackHoldSeconds', 'fallbackChain', 'fallbackSlateAsset'] as $method) {
        expect(method_exists(StreamSpec::class, $method))->toBeFalse();
    }
});
