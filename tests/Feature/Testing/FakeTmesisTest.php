<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Exceptions\ConnectionException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Testing\FakeTmesis;
use AlexHackney\Tmesis\Testing\FakeTransportAware;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('delivers a signed synthetic webhook that dispatches', function (): void {
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');

    $response = FakeTmesis::webhook('stream.published', $payload)->deliverTo($this->app);

    expect($response->getStatusCode())->toBe(204);

    // Proves deliverTo() ran the kernel's terminate(): the dispatch lives in the
    // route middleware's terminate(), so without it this would be 204 and silent.
    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('re-signs a second delivery with the same id, which is what a retry looks like', function (): void {
    Event::fake();

    $pending = FakeTmesis::webhook('stream.published', $this->exampleAsTestNode('stream.published'));

    expect($pending->deliverTo($this->app)->getStatusCode())->toBe(204)
        ->and($pending->deliverTo($this->app)->getStatusCode())->toBe(204);

    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('fills the envelope for a bare payload', function (): void {
    $this->usesDatabaseDeduper();
    Event::fake();

    FakeTmesis::webhook('node.online', ['version' => '1.4.0'])
        ->withDeliveryId('01J8ZCV6R2K8NQX4WM7TVB5PD9')
        ->attempt(3)
        ->deliverTo($this->app);

    $row = DB::table('tmesis_webhook_deliveries')->first();

    expect($row->delivery)->toBe('01J8ZCV6R2K8NQX4WM7TVB5PD9')
        ->and($row->event)->toBe('node.online')
        ->and($row->attempt)->toBe(3)
        ->and(json_decode($row->body, true))
        ->toMatchArray(['event' => 'node.online', 'node' => 'edge-venue-07', 'contract_version' => 1]);
});

it('is rejected when signed with the wrong secret', function (): void {
    Event::fake();

    $response = FakeTmesis::webhook('stream.published', $this->exampleAsTestNode('stream.published'))
        ->withSecret('wrong')
        ->deliverTo($this->app);

    expect($response->getStatusCode())->toBe(401);
    Event::assertNotDispatched(StreamPublished::class);
});

it('answers canned reads and records the calls', function (): void {
    $fake = Tmesis::fake()->withStreams([
        $this->fixtureJson('responses/getStream.json'),
    ]);

    $list = Tmesis::node()->listStreams();

    expect($list->count())->toBe(1)
        ->and($list->streams()[0])->toBeInstanceOf(StreamRecord::class)
        ->and($list->streams()[0]->id())->toBe('show-2041-main')
        ->and(Tmesis::node()->getStream('show-2041-main')->state())->toBe('live');

    $fake->assertCalled('listStreams')
        ->assertCalled('getStream', fn (array $args): bool => $args['id'] === 'show-2041-main')
        ->assertCalledCount('getStream', 1);
});

it('filters canned streams by state', function (): void {
    Tmesis::fake()
        ->withStreams([$this->fixtureJson('responses/getStream.json')])
        ->withStream(['id' => 'show-2041-held', 'state' => 'input_lost'] + $this->fixtureJson('responses/getStream.json'));

    expect(Tmesis::node()->listStreams('live')->count())->toBe(1)
        ->and(Tmesis::node()->listStreams('sealed')->count())->toBe(0)
        // The sixth state: the fake filters on the record's own string, so a held
        // record is listed under the value the client's STATES now accepts too.
        ->and(Tmesis::node()->listStreams('input_lost')->count())->toBe(1)
        ->and(Tmesis::node()->listStreams('input_lost')->streams()[0]->id())->toBe('show-2041-held');
});

it('throws NotFoundException for an unknown stream', function (): void {
    Tmesis::fake();

    expect(fn () => Tmesis::node()->getStream('nope'))->toThrow(NotFoundException::class);
});

it('answers a default health before anything is canned', function (): void {
    $fake = Tmesis::fake();

    expect(Tmesis::node()->getHealth()->status())->toBe('ok');

    $fake->assertCalled('getHealth');
});

it('throws what throwOn was given', function (): void {
    Tmesis::fake()->throwOn('getHealth', new ConnectionException('node is down'));

    expect(fn () => Tmesis::node()->getHealth())->toThrow(ConnectionException::class, 'node is down');
});

it('asserts nothing was called', function (): void {
    Tmesis::fake()->assertNothingCalled();
});

it('stores relay data redacted and never records a url', function (): void {
    $fake = Tmesis::fake()->withRelays('show-2041-main', [[
        'id' => 'static-0',
        'url' => 'srt://origin-atl-01.internal:8890?passphrase=hunter2',
        'enabled' => true,
        'source' => 'static',
        'state' => 'connected',
        'bytes_out' => 1,
        'dropped_bytes' => 0,
        'reconnects' => 0,
    ]]);

    $relay = Tmesis::node()->relays()->getRelay('show-2041-main', 'static-0');

    expect($relay->url())->toBe('srt://origin-atl-01.internal:8890')
        ->and(json_encode($fake->calls()))->not->toContain('hunter2')
        ->and(json_encode($fake->calls()))->not->toContain('srt://');
});

it('resolves the application when none is named', function (): void {
    Event::fake();

    FakeTmesis::webhook('stream.published', $this->exampleAsTestNode('stream.published'))
        ->deliverTo();

    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('creates a record that validates against the StreamRecord component', function (): void {
    Tmesis::fake();

    $record = Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->previewMaxMonitors(20));

    // The test that would have caught a fake record missing the rc7 blocks: a
    // consumer validating fake records against the component fails otherwise. The
    // one allowance is PHP's: an empty labels map encodes as [] and the component
    // says object, so it is encoded as {} here, which is what the node sends.
    $raw = $record->toArray();
    $raw['labels'] = $raw['labels'] === [] ? new stdClass : $raw['labels'];

    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode($raw), false),
        '/components/schemas/StreamRecord',
        'The fake\'s created stream',
    );

    // The standalone defaults (D4), with the spec's leaf merged in.
    expect($record->recording()->ingest()->enabled())->toBeTrue()
        ->and($record->recording()->ingest()->retentionHours())->toBe(72)
        ->and($record->recording()->ingest()->destination())->toBeNull()
        ->and($record->preview()->enabled())->toBeTrue()
        ->and($record->preview()->maxMonitors())->toBe(20);
});

it('answers include=preview the way the node does', function (): void {
    $fake = Tmesis::fake()->withStreams([$this->fixtureJson('responses/getStream.json')]);

    // Without the token the block is absent, on the single read and on the list.
    expect(Tmesis::node()->getStream('show-2041-main')->previewMonitor())->toBeNull()
        ->and(Tmesis::node()->listStreams()->streams()[0]->previewMonitor())->toBeNull();

    // With it, the node's shape with no preview.public_url set: a path and a null URL.
    $monitor = Tmesis::node()->getStream('show-2041-main', includePreview: true)->previewMonitor();

    expect($monitor?->whepPath())->toBe('/whep/v1/streams/show-2041-main')
        ->and($monitor?->whepUrl())->toBeNull();

    $fake->assertCalled('getStream', fn (array $args): bool => $args['include'] === 'preview');

    // A canned record that carries the block keeps its own values when asked.
    Tmesis::fake()->withStream($this->fixtureJson('responses/getStream.json') + [
        'preview_monitor' => ['whep_path' => '/whep/v1/streams/show-2041-main', 'whep_url' => 'https://box.example.com:8889/whep/v1/streams/show-2041-main'],
    ]);

    expect(Tmesis::node()->getStream('show-2041-main')->previewMonitor())->toBeNull()
        ->and(Tmesis::node()->getStream('show-2041-main', includePreview: true)->previewMonitor()?->whepUrl())
        ->toBe('https://box.example.com:8889/whep/v1/streams/show-2041-main');
});

it('returns stored events oldest first by default and newest first on desc', function (): void {
    $body = $this->fixtureJson('responses/getStreamEvents.json');
    // Canned newest first, so the default order is the fake's doing and not the
    // fixture's.
    $body['events'] = array_reverse($body['events']);

    Tmesis::fake()->withStreamEvents('show-2041-main', $body);

    $kinds = fn (?string $order): array => array_map(
        static fn ($event): string => $event->kind(),
        Tmesis::node()->streams()->getStreamEvents('show-2041-main', order: $order)->events(),
    );

    expect($kinds(null))->toBe(['publish.accepted', 'packager.started'])
        ->and($kinds('asc'))->toBe(['publish.accepted', 'packager.started'])
        ->and($kinds('desc'))->toBe(['packager.started', 'publish.accepted'])
        ->and(fn () => $kinds('sideways'))->toThrow(InvalidConfigurationException::class);
});

it('orders events by the instant when fractional-second precision varies', function (): void {
    // The node marshals time.Time with RFC3339Nano, which trims trailing zeros:
    // 480ms renders `.48Z` and 482ms `.482Z`. Text order puts `.48Z` AFTER `.482Z`
    // because `Z` sorts above `2`; the instants are the other way round.
    $body = $this->fixtureJson('responses/getStreamEvents.json');
    $body['events'][0]['at'] = '2026-08-28T19:12:01.48Z';
    $body['events'][1]['at'] = '2026-08-28T19:12:01.482Z';

    Tmesis::fake()->withStreamEvents('show-2041-main', $body);

    $kinds = fn (?string $order): array => array_map(
        static fn ($event): string => $event->kind(),
        Tmesis::node()->streams()->getStreamEvents('show-2041-main', order: $order)->events(),
    );

    expect($kinds(null))->toBe(['publish.accepted', 'packager.started'])
        ->and($kinds('desc'))->toBe(['packager.started', 'publish.accepted']);
});

it('gives every record the required captions block, resolved to effective values', function (): void {
    Tmesis::fake();

    // A spec that says only "on" and names no provider or language resolves both, the
    // way the node resolves them through its transcription config.
    $record = Tmesis::node()->streams()->createStream(
        'show-2041-main',
        StreamSpec::make()->captionsEnabled(true)->captionsProvider(null)->captionsLanguages([]),
    );

    $raw = $record->toArray();
    $raw['labels'] = $raw['labels'] === [] ? new stdClass : $raw['labels'];

    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode($raw), false),
        '/components/schemas/StreamRecord',
        'The fake\'s captioned stream',
    );

    expect($record->captions()->enabled())->toBeTrue()
        ->and($record->captions()->advertise())->toBeTrue()
        ->and($record->captions()->provider())->toBe('deepgram')
        ->and($record->captions()->languages())->toBe(['en']);

    // The default box is off, and a record canned in an older shape gains the block.
    $old = $this->fixtureJson('responses/getStream.json');
    unset($old['captions']);

    Tmesis::fake()->withStream($old);

    expect(Tmesis::node()->getStream('show-2041-main')->captions()->enabled())->toBeFalse();
});

it('answers include=captions the way the node does', function (): void {
    // getStream.json has captions on.
    $fake = Tmesis::fake()->withStreams([$this->fixtureJson('responses/getStream.json')]);

    expect(Tmesis::node()->getStream('show-2041-main')->captions()->session())->toBeNull()
        ->and(Tmesis::node()->getStream('show-2041-main', includeCaptions: true)->captions()->session()?->state())->toBe('idle')
        ->and(Tmesis::node()->listStreams()->streams()[0]->captions()->session())->toBeNull();

    Tmesis::node()->getStream('show-2041-main', includePreview: true, includeCaptions: true);

    $fake->assertCalled('getStream', fn (array $args): bool => $args['include'] === 'captions')
        ->assertCalled('getStream', fn (array $args): bool => $args['include'] === 'preview,captions');

    // Captions off: the session is absent even when asked for.
    $off = $this->fixtureJson('responses/getStream.json');
    $off['captions']['enabled'] = false;

    Tmesis::fake()->withStream($off);

    expect(Tmesis::node()->getStream('show-2041-main', includeCaptions: true)->captions()->session())->toBeNull();

    // A canned session keeps its own values when asked, and never shows when not.
    $live = $this->fixtureJson('responses/getStream.json');
    $live['captions']['session'] = [
        'state' => 'live',
        'provider' => 'deepgram',
        'fallback' => false,
        'provider_request_id' => 'req-1',
        'started_at' => '2026-09-22T19:12:03.117Z',
        'finals' => 3,
        'dropped_pcm_ms' => 0,
        'reconnects' => 0,
        'reason' => null,
    ];

    Tmesis::fake()->withStream($live);

    expect(Tmesis::node()->getStream('show-2041-main')->captions()->session())->toBeNull()
        ->and(Tmesis::node()->getStream('show-2041-main', includeCaptions: true)->captions()->session()?->finals())->toBe(3)
        ->and(Tmesis::node()->getStream('show-2041-main', includeCaptions: true)->captions()->session()?->provider())->toBe('deepgram');
});

it('carries the required captions key on canned stats, null until a captioner reports', function (): void {
    $stats = $this->fixtureJson('responses/getStreamStats.json');
    unset($stats['captions']);

    Tmesis::fake()->withStreamStats('show-2041-main', $stats);

    expect(Tmesis::node()->streams()->getStreamStats('show-2041-main')->captions())->toBeNull();

    Tmesis::fake()->withStreamStats('show-2041-main', $this->fixtureJson('responses/getStreamStats.json'));

    expect(Tmesis::node()->streams()->getStreamStats('show-2041-main')->captions()?->finals())->toBe(412);
});

it('answers a seeded transcript, tails it past since, and 404s another language', function (): void {
    $fake = Tmesis::fake()->withStreamTranscript('show-2041-main', $this->fixtureJson('responses/getStreamTranscript.json'));

    $streams = Tmesis::node()->streams();

    expect($streams->getStreamTranscript('show-2041-main')->cues())->toHaveCount(2)
        ->and($streams->getStreamTranscript('show-2041-main', lang: 'en', since: 1)->cues())->toHaveCount(1)
        ->and($streams->getStreamTranscript('show-2041-main', since: 2)->cues())->toBe([])
        ->and($streams->getStreamTranscript('show-2041-main', since: 2)->lastSeq())->toBeNull();

    $fake->assertCalled('getStreamTranscript', fn (array $args): bool => $args['format'] === null && $args['since'] === 1);

    expect(fn () => $streams->getStreamTranscript('show-2041-main', lang: 'fr'))->toThrow(NotFoundException::class)
        ->and(fn () => $streams->getStreamTranscript('show-2042-prelims'))->toThrow(NotFoundException::class);
});

it('answers the provider of the first cue above since, else the stream primary', function (): void {
    $body = $this->fixtureJson('responses/getStreamTranscript.json');
    $body['provider'] = 'deepgram';
    $body['cues'][0]['provider'] = 'deepgram';
    $body['cues'][1]['provider'] = 'self_hosted';

    Tmesis::fake()->withStreamTranscript('show-2041-main', $body);

    $streams = Tmesis::node()->streams();

    expect($streams->getStreamTranscript('show-2041-main')->provider())->toBe('deepgram')
        ->and($streams->getStreamTranscript('show-2041-main', since: 1)->provider())->toBe('self_hosted')
        ->and($streams->getStreamTranscript('show-2041-main', since: 2)->provider())->toBe('deepgram');

    // The fake holds the stream: its effective captions.provider is the primary.
    $stream = $this->fixtureJson('responses/getStream.json');
    $stream['captions']['provider'] = 'self_hosted';

    Tmesis::fake()->withStream($stream)->withStreamTranscript('show-2041-main', $body);

    expect(Tmesis::node()->streams()->getStreamTranscript('show-2041-main', since: 2)->provider())->toBe('self_hosted');
});

it('answers the first cue provider on an unfiltered read, whatever the seeded body says', function (): void {
    $body = $this->fixtureJson('responses/getStreamTranscript.json');
    $body['provider'] = 'self_hosted';
    $body['cues'][0]['provider'] = 'deepgram';

    Tmesis::fake()->withStreamTranscript('show-2041-main', $body);

    expect(Tmesis::node()->streams()->getStreamTranscript('show-2041-main')->provider())->toBe('deepgram');
});

it('answers the explicit primary when no cue is returned, on a fallback-first seed', function (): void {
    $body = $this->fixtureJson('responses/getStreamTranscript.json');
    $body['provider'] = 'self_hosted';
    $body['cues'][0]['provider'] = 'self_hosted';
    $body['cues'][1]['provider'] = 'deepgram';

    Tmesis::fake()->withStreamTranscript('show-2041-main', $body, primary: 'deepgram');

    $streams = Tmesis::node()->streams();

    expect($streams->getStreamTranscript('show-2041-main', since: 2)->provider())->toBe('deepgram')
        ->and($streams->getStreamTranscript('show-2041-main', since: 1)->provider())->toBe('deepgram')
        ->and($streams->getStreamTranscript('show-2041-main')->provider())->toBe('self_hosted');
});

it('answers the held stream provider for an empty transcript without since, and an explicit primary beats it', function (): void {
    $body = $this->fixtureJson('responses/getStreamTranscript.json');
    $body['provider'] = 'deepgram';
    $body['cues'] = [];

    $stream = $this->fixtureJson('responses/getStream.json');
    $stream['captions']['provider'] = 'self_hosted';

    Tmesis::fake()->withStream($stream)->withStreamTranscript('show-2041-main', $body);

    expect(Tmesis::node()->streams()->getStreamTranscript('show-2041-main')->provider())->toBe('self_hosted');

    Tmesis::fake()->withStream($stream)->withStreamTranscript('show-2041-main', $body, primary: 'assemblyai');

    expect(Tmesis::node()->streams()->getStreamTranscript('show-2041-main')->provider())->toBe('assemblyai');
});

it('answers a seeded vtt or srt verbatim, and 404s one that was not seeded', function (): void {
    $vtt = "WEBVTT\n\n1\n00:00:01.500 --> 00:00:03.200\nGood evening from Atlanta.\n";

    $fake = Tmesis::fake()->withStreamTranscriptText('show-2041-main', 'vtt', $vtt, lang: 'en');

    $streams = Tmesis::node()->streams();

    expect($streams->getStreamTranscriptText('show-2041-main', 'vtt'))->toBe($vtt)
        ->and($streams->getStreamTranscriptText('show-2041-main', 'vtt', lang: 'en'))->toBe($vtt);

    $fake->assertCalled('getStreamTranscript', fn (array $args): bool => $args['format'] === 'vtt');

    expect(fn () => $streams->getStreamTranscriptText('show-2041-main', 'vtt', lang: 'fr'))->toThrow(NotFoundException::class)
        ->and(fn () => $streams->getStreamTranscriptText('show-2041-main', 'srt'))->toThrow(NotFoundException::class);
});

it('refuses through the fake the transcript requests the real resource refuses, recording nothing', function (): void {
    $fake = Tmesis::fake();

    $streams = Tmesis::node()->streams();

    expect(fn () => $streams->getStreamTranscriptText('show-2041-main', 'json'))->toThrow(InvalidConfigurationException::class, '[format]')
        ->and(fn () => $streams->getStreamTranscript('show-2041-main', since: -1))->toThrow(InvalidConfigurationException::class, '[since]')
        ->and(fn () => $fake->withStreamTranscriptText('show-2041-main', 'ttml', ''))->toThrow(InvalidConfigurationException::class, '[format]');

    $fake->assertNothingCalled();
});

it('keeps withRequestId() inside the fake, so a write never reaches a configured node', function (): void {
    Http::fake();

    $fake = Tmesis::fake();

    $bound = Tmesis::node()->withRequestId('01J8ZC9GQ2M4YE7XW1RN0P6VTB');
    $bound->streams()->createStream('show-2041-main', StreamSpec::make());

    expect($bound)->toBeInstanceOf(FakeTransportAware::class)
        ->and($bound->requestId())->toBe('01J8ZC9GQ2M4YE7XW1RN0P6VTB')
        ->and(Tmesis::node()->getStream('show-2041-main')->id())->toBe('show-2041-main');

    $fake->assertCalled('createStream');
    Http::assertNothingSent();
});
