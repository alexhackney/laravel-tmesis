<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\ConnectionException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\SessionEpoch;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads health from the contract path with a bearer token', function (): void {
    Http::fake(['127.0.0.1:7000/api/v1/health' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $health = Tmesis::node()->getHealth();

    expect($health->status())->toBe('ok')
        ->and($health->node())->toBe('origin-atl-01')
        ->and($health->journal()?->outboxDepth())->toBe(0)
        ->and($health->videoEngine()?->accelerator())->toBe('nvenc')
        ->and($health->degradedReasons())->toBe([]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/health'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('Accept', 'application/json'));
});

it('sends checks as a string on the doctor route', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getDoctor.json'))]);

    $doctor = Tmesis::node()->getDoctor(false);

    expect($doctor->node())->toBe('origin-atl-01')
        ->and($doctor->checks())->toHaveCount(10)
        ->and($doctor->checks()[9]->status())->toBe('warn')
        ->and($doctor->hardware()->cpu()->logicalCores())->toBe(32)
        ->and($doctor->config()['source'])->toBe('/etc/tmesis/config.yml');

    // The node parses checks=false, not PHP's checks=0.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'checks=false'));
});

it('reads a stream and types its two epochs differently', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStream.json'))]);

    $stream = Tmesis::node()->getStream('show-2041-main');

    expect($stream->id())->toBe('show-2041-main')
        ->and($stream->state())->toBe('live')
        // The SESSION epoch and the KEY epoch floor, and they cannot be swapped.
        ->and($stream->epoch()?->value)->toBe(3)
        ->and($stream->minKeyEpoch()?->value)->toBe(7)
        ->and($stream->epoch())->toBeInstanceOf(SessionEpoch::class)
        ->and($stream->minKeyEpoch())->toBeInstanceOf(KeyEpoch::class)
        ->and($stream->labels())->toBe(['show_id' => '2041', 'site' => 'csn'])
        ->and($stream->sealedAt())->toBeNull()
        ->and($stream->playback()->master())->toContain('playlist_dvr.m3u8');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main');
});

it('lists streams and never sends include', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listStreams.json'))]);

    $list = Tmesis::node()->listStreams('live');

    expect($list->count())->toBe(2)
        ->and($list->streams())->toHaveCount(2);

    // include is a 422 for every value at this tag.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'state=live')
        && ! str_contains($request->url(), 'include'));
});

it('refuses a state the node does not have', function (): void {
    expect(fn () => Tmesis::node()->listStreams('cooking'))
        ->toThrow(InvalidConfigurationException::class);

    Http::assertNothingSent();
});

it('sends the held state to the list filter', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listStreams.json'))]);

    // input_lost is the sixth value the node's list filter accepts (it joined at rc5;
    // Streams::STATES lagged it until v0.2 step 1 and refused it before any request).
    Tmesis::node()->listStreams('input_lost');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'state=input_lost')
        && ! str_contains($request->url(), 'include'));
});

it('renders stream event query parameters as RFC3339 Z', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStreamEvents.json'))]);

    $events = Tmesis::node()->streams()->getStreamEvents(
        'show-2041-main',
        new DateTimeImmutable('2026-08-28T19:00:00.000Z'),
        new DateTimeImmutable('2026-08-28T20:00:00.000Z'),
        50,
    );

    expect($events->count())->toBe(2)
        ->and($events->events()[0]->kind())->toBe('publish.accepted')
        ->and($events->events()[1]->details())->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'since=2026-08-28T19%3A00%3A00.000Z')
        && str_contains($request->url(), 'limit=50'));
});

it('redacts a relay url even if the node sent one unredacted', function (): void {
    Http::fake(['*' => Http::response(['relays' => [[
        'id' => '01J8ZCC1H6V9DQ2NR5TXB4KM7W',
        'url' => 'srt://origin-atl-01.internal:8890?passphrase=hunter2&streamid=secret',
        'enabled' => true,
        'source' => 'static',
        'state' => 'connected',
        'bytes_out' => 1,
        'dropped_bytes' => 0,
        'reconnects' => 0,
    ]]])]);

    $relays = Tmesis::node()->relays()->listRelays('show-2041-main');

    expect($relays->relays()[0]->url())->toBe('srt://origin-atl-01.internal:8890')
        ->and(json_encode($relays->relays()[0]->toArray()))->not->toContain('hunter2');
});

it('maps a 404 body to NotFoundException with the request id', function (): void {
    Http::fake(['*' => Http::response(errorBody('not_found', 'no such stream'), 404)]);

    try {
        Tmesis::node()->getStream('nope');
        $this->fail('Expected a NotFoundException.');
    } catch (NotFoundException $e) {
        expect($e->code())->toBe('not_found')
            ->and($e->getMessage())->toBe('no such stream')
            ->and($e->requestId())->toBe('req_01J8ZC')
            ->and($e->status())->toBe(404);
    }
});

it('surfaces a 501 milestone, which is how discovery works', function (): void {
    Http::fake(['*' => Http::response(errorBody('not_implemented', 'M3 delivers this', 'M3'), 501)]);

    try {
        Tmesis::node()->getNodeStats();
        $this->fail('Expected a NotImplementedException.');
    } catch (NotImplementedException $e) {
        expect($e->milestone())->toBe('M3');
    }
});

it('maps a 503 on health to UnavailableException', function (): void {
    Http::fake(['*' => Http::response(errorBody('draining', 'node is draining'), 503)]);

    try {
        Tmesis::node()->getHealth();
        $this->fail('Expected an UnavailableException.');
    } catch (UnavailableException $e) {
        expect($e->code())->toBe('draining');
    }
});

it('wraps a transport failure', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 7'));

    expect(fn () => Tmesis::node()->getHealth())
        ->toThrow(ConnectionException::class, 'Could not reach the Tmesis node');
});

it('throws before sending anything when a required value is blank', function (string $key): void {
    Http::fake();

    config()->set('tmesis.nodes.'.TestCase::NODE.'.'.$key, '');

    expect(fn () => Tmesis::node()->getHealth())
        ->toThrow(InvalidConfigurationException::class, 'tmesis.nodes.'.TestCase::NODE.'.'.$key);

    Http::assertNothingSent();
})->with(['token', 'url']);

it('talks to a second configured node', function (): void {
    config()->set('tmesis.nodes.origin-atl-01', [
        'url' => 'http://10.0.0.5:7000',
        'token' => 'other-token',
        'webhook_secret' => 'whsec_other',
    ]);

    Http::fake(['10.0.0.5:7000/*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    Tmesis::node('origin-atl-01')->getHealth();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.0.0.5:7000/api/v1/health'
        && $request->hasHeader('Authorization', 'Bearer other-token'));
});

it('connects to a node that is not in config at all', function (): void {
    Http::fake(['10.0.0.9:7000/*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    // The fleet case: URL and token come from the control plane's own database.
    Tmesis::connect(new NodeConfig('discovered-01', 'http://10.0.0.9:7000/', 'discovered-token'))->getHealth();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.0.0.9:7000/api/v1/health'
        && $request->hasHeader('Authorization', 'Bearer discovered-token'));
});

it('sends a request id when asked', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    Tmesis::node()->withRequestId('req_mine')->node()->getHealth();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Request-Id', 'req_mine'));
});

it('reads the recording and preview blocks on a stream', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStream.json'))]);

    $stream = Tmesis::node()->getStream('show-2041-main');

    expect($stream->recording()->ingest()->enabled())->toBeTrue()
        ->and($stream->recording()->ingest()->retentionHours())->toBe(72)
        ->and($stream->recording()->ingest()->destination())->toBe('/mnt/archive/ingest')
        ->and($stream->preview()->enabled())->toBeTrue()
        ->and($stream->preview()->maxMonitors())->toBe(50)
        // Absent without include=preview, so null on a plain read.
        ->and($stream->previewMonitor())->toBeNull();
});

it('sends include=preview only when asked', function (): void {
    $plain = $this->fixtureJson('responses/getStream.json');
    $withMonitor = $plain + ['preview_monitor' => [
        'whep_path' => '/whep/v1/streams/show-2041-main',
        'whep_url' => 'https://box.example.com:8889/whep/v1/streams/show-2041-main',
    ]];

    Http::fake(fn (Request $request) => str_contains($request->url(), 'include=preview')
        ? Http::response($withMonitor)
        : Http::response($plain));

    expect(Tmesis::node()->getStream('show-2041-main')->previewMonitor())->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main');

    $monitor = Tmesis::node()->getStream('show-2041-main', includePreview: true)->previewMonitor();

    expect($monitor?->whepPath())->toBe('/whep/v1/streams/show-2041-main')
        ->and($monitor?->whepUrl())->toBe('https://box.example.com:8889/whep/v1/streams/show-2041-main');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main?include=preview');
});

it('sends order=desc when asked and nothing when not', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStreamEvents.json'))]);

    Tmesis::node()->streams()->getStreamEvents('show-2041-main');
    Tmesis::node()->streams()->getStreamEvents('show-2041-main', order: 'desc');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/events');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/events?order=desc');
});

it('refuses an order the node does not have', function (): void {
    Http::fake();

    expect(fn () => Tmesis::node()->streams()->getStreamEvents('show-2041-main', order: 'sideways'))
        ->toThrow(InvalidConfigurationException::class, 'order');

    Http::assertNothingSent();
});

it('reads the captions block on a plain read, with no session', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStream.json'))]);

    $captions = Tmesis::node()->getStream('show-2041-main')->captions();

    expect($captions->enabled())->toBeTrue()
        ->and($captions->advertise())->toBeTrue()
        ->and($captions->provider())->toBe('deepgram')
        ->and($captions->languages())->toBe(['en'])
        // Absent without include=captions, so null on a plain read.
        ->and($captions->session())->toBeNull();
});

it('sends include as preview, captions, both joined in a fixed order, or not at all', function (bool $preview, bool $captions, ?string $include): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStream.json'))]);

    Tmesis::node()->getStream('show-2041-main', includePreview: $preview, includeCaptions: $captions);

    Http::assertSent(function (Request $request) use ($include): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'http://127.0.0.1:7000/api/v1/streams/show-2041-main')
            && ($query['include'] ?? null) === $include
            && array_keys($query) === ($include === null ? [] : ['include']);
    });
})->with([
    'neither' => [false, false, null],
    'preview' => [true, false, 'preview'],
    'captions' => [false, true, 'captions'],
    'both' => [true, true, 'preview,captions'],
]);

it('reads the caption session under include=captions', function (): void {
    $body = $this->fixtureJson('responses/getStream.json');
    $body['captions']['session'] = [
        'state' => 'live',
        'provider' => 'deepgram',
        'fallback' => false,
        'provider_request_id' => '8f1c2a7e-5b3d-4e9a-9c1f-2d7b6a4e0c31',
        'started_at' => '2026-09-22T19:12:03.117Z',
        'finals' => 412,
        'dropped_pcm_ms' => 0,
        'reconnects' => 1,
        'reason' => null,
    ];

    Http::fake(['*' => Http::response($body)]);

    $session = Tmesis::node()->getStream('show-2041-main', includeCaptions: true)->captions()->session();

    expect($session?->state())->toBe('live')
        ->and($session?->provider())->toBe('deepgram')
        ->and($session?->fallback())->toBeFalse()
        ->and($session?->providerRequestId())->toBe('8f1c2a7e-5b3d-4e9a-9c1f-2d7b6a4e0c31')
        ->and($session?->finals())->toBe(412)
        ->and($session?->reconnects())->toBe(1)
        ->and($session?->reason())->toBeNull();
});

it('reads the caption snapshot on the stats route', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStreamStats.json'))]);

    $captions = Tmesis::node()->streams()->getStreamStats('show-2041-main')->captions();

    expect($captions?->state())->toBe('live')
        ->and($captions?->provider())->toBe('deepgram')
        ->and($captions?->finals())->toBe(412)
        ->and($captions?->reason())->toBe('');
});

it('reads the JSON transcript with the JSON accept and no format', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStreamTranscript.json'))]);

    $transcript = Tmesis::node()->streams()->getStreamTranscript('show-2041-main');

    expect($transcript->stream())->toBe('show-2041-main')
        ->and($transcript->lang())->toBe('en')
        ->and($transcript->cues())->toHaveCount(2)
        ->and($transcript->cues()[1]->speaker())->toBe(0)
        ->and($transcript->cues()[0]->words()[3]->punctuatedWord())->toBe('Atlanta.')
        ->and($transcript->lastSeq())->toBe(2);

    // json is the node's default, so no format is sent; nothing else was asked for.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/transcript'
        && $request->method() === 'GET'
        && $request->header('Accept') === ['application/json']);
});

it('sends lang and since on the transcript only when given', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getStreamTranscript.json'))]);

    Tmesis::node()->streams()->getStreamTranscript('show-2041-main', lang: 'en', since: 0);
    Tmesis::node()->streams()->getStreamTranscript('show-2041-main', since: 41);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/transcript?lang=en&since=0');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/transcript?since=41');
});

it('reads a transcript rendering as the raw document with its media type', function (string $format, string $accept, string $document): void {
    Http::fake(['*' => Http::response($document, 200, ['Content-Type' => $accept])]);

    $text = Tmesis::node()->streams()->getStreamTranscriptText('show-2041-main', $format, lang: 'en', since: 1);

    expect($text)->toBe($document);

    Http::assertSent(fn (Request $request): bool => $request->url() === "http://127.0.0.1:7000/api/v1/streams/show-2041-main/transcript?format={$format}&lang=en&since=1"
        && $request->header('Accept') === [$accept]);
})->with([
    'vtt' => ['vtt', 'text/vtt', "WEBVTT\n\n2\n00:00:03.400 --> 00:00:05.000\nWelcome to the show.\n"],
    'srt' => ['srt', 'application/x-subrip', "1\n00:00:03,400 --> 00:00:05,000\nWelcome to the show.\n"],
]);

it('maps the transcript 404 and 422 through the JSON error envelope', function (int $status, string $code, string $exception, string $format): void {
    Http::fake(['*' => Http::response(errorBody($code, 'nothing to render'), $status)]);

    $call = $format === 'json'
        ? fn () => Tmesis::node()->streams()->getStreamTranscript('show-2041-main', lang: 'fr')
        : fn () => Tmesis::node()->streams()->getStreamTranscriptText('show-2041-main', $format, lang: 'fr');

    expect($call)->toThrow($exception, 'nothing to render');
})->with([
    '404 json' => [404, 'not_found', NotFoundException::class, 'json'],
    '404 vtt' => [404, 'not_found', NotFoundException::class, 'vtt'],
    '422 srt' => [422, 'validation_failed', ValidationException::class, 'srt'],
]);

it('refuses a transcript text format or a negative since before sending anything', function (Closure $call, string $key): void {
    Http::fake();

    expect(fn () => $call(Tmesis::node()->streams()))->toThrow(InvalidConfigurationException::class, "[{$key}]");

    Http::assertNothingSent();
})->with([
    'json is the typed method' => [fn (Streams $streams) => $streams->getStreamTranscriptText('show-2041-main', 'json'), 'format'],
    'an unknown format' => [fn (Streams $streams) => $streams->getStreamTranscriptText('show-2041-main', 'ttml'), 'format'],
    'a negative since, json' => [fn (Streams $streams) => $streams->getStreamTranscript('show-2041-main', since: -1), 'since'],
    'a negative since, text' => [fn (Streams $streams) => $streams->getStreamTranscriptText('show-2041-main', 'vtt', since: -1), 'since'],
]);
