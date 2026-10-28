<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Data\Streams\PendingChange;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Events\PublishHookDecided;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Testing\FakeTmesis;
use AlexHackney\Tmesis\Tests\Doubles\RecordingDecider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('creates a stream a later read can see', function (): void {
    $fake = Tmesis::fake();

    $record = Tmesis::node()->streams()->createStream(
        'show-2041-main',
        StreamSpec::make()->ladder('broadcast-720p')->labels(['show_id' => '2041']),
    );

    expect($record->id())->toBe('show-2041-main')
        ->and($record->state())->toBe('provisioned')
        ->and($record->ladder())->toBe('broadcast-720p')
        // A write that went nowhere would pass tests the node would fail.
        ->and(Tmesis::node()->getStream('show-2041-main')->labels())->toBe(['show_id' => '2041'])
        ->and(Tmesis::node()->listStreams()->count())->toBe(1);

    $fake->assertCalled('createStream', fn (array $args): bool => $args['id'] === 'show-2041-main'
        && $args['spec']['ladder'] === 'broadcast-720p');
});

it('reports the second create as one that provisioned nothing', function (): void {
    Tmesis::fake();

    [, $first] = Tmesis::node()->streams()->createStreamWithOutcome('show-2041-main');
    [, $second] = Tmesis::node()->streams()->createStreamWithOutcome('show-2041-main');

    expect($first)->toBeTrue()->and($second)->toBeFalse();
});

it('answers a patch with applied and pending the way the node splits them', function (): void {
    $fake = Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->segmentSeconds(6));

    $patch = Tmesis::node()->streams()->updateStream(
        'show-2041-main',
        StreamSpec::make()->segmentSeconds(4)->labels(['site' => 'csn']),
    );

    expect($patch->applied())->toBe(['labels' => 'live'])
        ->and($patch->pending())->toHaveKey('segment_seconds')
        ->and($patch->pending()['segment_seconds']->when())->toBe(PendingChange::WHEN_NEXT_SESSION)
        ->and($patch->pending()['segment_seconds']->current())->toBe(6)
        ->and($patch->version())->toBe(2)
        // Pending means pending: the read still carries the running value until the
        // next session, the way the node's does.
        ->and(Tmesis::node()->getStream('show-2041-main')->segmentSeconds())->toBe(6);

    $fake->assertCalled('updateStream');
});

it('seals a stream, and a second seal is not a fresh one', function (): void {
    Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main');

    $first = Tmesis::node()->streams()->deleteStream('show-2041-main', graceSeconds: 30);

    expect($first->started)->toBeTrue()
        // The 202 the node answers while the grace period runs, not the end state.
        ->and($first->state())->toBe('draining')
        ->and($first->graceSeconds())->toBe(30)
        ->and(Tmesis::node()->getStream('show-2041-main')->state())->toBe('sealed')
        ->and(Tmesis::node()->streams()->deleteStream('show-2041-main')->started)->toBeFalse();
});

it('answers the node default grace when the caller named none', function (): void {
    Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main');

    expect(Tmesis::node()->streams()->deleteStream('show-2041-main')->graceSeconds())->toBe(10);
});

it('kicks a session and leaves the stream provisioned', function (): void {
    $fake = Tmesis::fake()->withStream([
        'id' => 'show-2041-main',
        'state' => 'live',
        'session' => '01J8ZC8XKWQ4M7T0K3PZ2B9RVN',
        'epoch' => 3,
    ]);

    $action = Tmesis::node()->streams()->kickStream('show-2041-main');

    expect($action->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($action->endedASession())->toBeTrue()
        ->and(Tmesis::node()->getStream('show-2041-main')->session())->toBeNull();

    // A second kick is a success with nothing to end, which is what makes a
    // control-plane retry safe.
    expect(Tmesis::node()->streams()->kickStream('show-2041-main')->endedASession())->toBeFalse();

    $fake->assertCalledCount('kickStream', 2);
});

it('replaces the revocation list wholesale on the fake too', function (): void {
    $fake = Tmesis::fake();

    expect(Tmesis::node()->streams()->pushKeyRevocation(['a', 'b', 'c'])->count())->toBe(3)
        ->and($fake->revocations())->toBe(['a', 'b', 'c'])
        ->and(Tmesis::node()->streams()->pushKeyRevocation(['d'])->count())->toBe(1)
        ->and($fake->revocations())->toBe(['d'])
        ->and(Tmesis::node()->streams()->pushKeyRevocation([])->count())->toBe(0)
        ->and($fake->revocations())->toBe([]);
});

it('adds, patches, stops and removes a destination', function (): void {
    $fake = Tmesis::fake();

    $created = Tmesis::node()->relays()->createRelay(
        'show-2041-main',
        RelayInput::make('srt://backup-den-02.internal:8890?passphrase=hunter2', id: 'backup'),
    );

    expect($created->id())->toBe('backup')
        ->and($created->url())->toBe('srt://backup-den-02.internal:8890')
        ->and($created->state())->toBe('connecting')
        // No feed given: the node's default, and the fake resolves auto to ingest
        // because it models no ladders (D3).
        ->and($created->feed())->toBe('auto')
        ->and($created->feedResolved())->toBe('ingest');

    // A new feed on a running destination replaces it: connecting again, resolved to
    // the rung as if it existed (whether it does is the node's 422, never the fake's).
    $refed = Tmesis::node()->relays()->updateRelay('show-2041-main', 'backup', RelayPatch::make(feed: 'rung:720p'));

    expect($refed->feed())->toBe('rung:720p')
        ->and($refed->feedResolved())->toBe('rung:720p')
        ->and($refed->state())->toBe('connecting');

    $parked = Tmesis::node()->relays()->updateRelay('show-2041-main', 'backup', RelayPatch::make(enabled: false));

    expect($parked->enabled())->toBeFalse()
        ->and($parked->state())->toBe('stopped')
        // A parked destination is sent nothing, which the node spells as empty.
        ->and($parked->feedResolved())->toBe('')
        ->and($parked->feed())->toBe('rung:720p')
        ->and(Tmesis::node()->relays()->getRelay('show-2041-main', 'backup')->enabled())->toBeFalse();

    expect(Tmesis::node()->relays()->stopRelays('show-2041-main')->stopped())->toBe(0);

    expect(Tmesis::node()->relays()->deleteRelay('show-2041-main', 'backup')->stopped())->toBe(1)
        ->and(Tmesis::node()->relays()->listRelays('show-2041-main')->relays())->toBe([]);

    $fake->assertCalled('deleteRelay');
});

it('never mints an id a caller already chose', function (): void {
    Tmesis::fake();

    // The API path: an explicit id of the minted form, then a mint.
    Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://a.example.com:8890', id: 'relay-1'));
    $minted = Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://b.example.com:8890'));

    expect($minted->id())->toBe('relay-2');

    // The spec path, in both orders: a mint before an explicit id that would have
    // been its value, and one after.
    Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->relays([
        RelayInput::make('srt://c.example.com:8890'),
        RelayInput::make('srt://d.example.com:8890', id: 'relay-3'),
        RelayInput::make('srt://e.example.com:8890'),
    ]));

    $ids = array_map(
        static fn ($relay): string => $relay->id(),
        Tmesis::node()->relays()->listRelays('show-2041-main')->relays(),
    );

    // Explicit ids are kept as given, every id is unique, and nothing minted took a
    // number a caller had chosen.
    expect($ids)->toBe(['relay-1', 'relay-2', 'relay-4', 'relay-3', 'relay-5'])
        ->and(array_unique($ids))->toHaveCount(5);
});

it('provisions the relays a spec names and replaces them on a patch', function (): void {
    Tmesis::fake();

    // An API-pushed destination is a separate source and must survive every
    // replacement below (API.md 9.9c).
    Tmesis::node()->relays()->createRelay(
        'show-2041-main',
        RelayInput::make('srt://pushed.example.com:8890?passphrase=hunter2', id: 'pushed'),
    );

    Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->relays([
        RelayInput::make('srt://a.example.com:8890?passphrase=hunter2', id: 'a'),
        RelayInput::make('srt://b.example.com:8890', enabled: false),
    ]));

    $listed = fn (): array => array_map(
        static fn ($relay): array => [$relay->id(), $relay->url(), $relay->source(), $relay->state()],
        Tmesis::node()->relays()->listRelays('show-2041-main')->relays(),
    );

    // Redacted on the way in, sourced as `stream`, and an id minted from the same
    // allocator the API path uses: the first mint, since explicit ids take no number.
    expect($listed())->toBe([
        ['pushed', 'srt://pushed.example.com:8890', 'api', 'connecting'],
        ['a', 'srt://a.example.com:8890', 'stream', 'connecting'],
        ['relay-1', 'srt://b.example.com:8890', 'stream', 'idle'],
    ]);

    // A patch that mentions relays is a FULL replacement of the stream set.
    $patch = Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->relays([
        RelayInput::make('srt://c.example.com:8890', id: 'c'),
    ]));

    expect($patch->applied())->toBe(['relays' => 'live'])
        ->and($listed())->toBe([
            ['pushed', 'srt://pushed.example.com:8890', 'api', 'connecting'],
            ['c', 'srt://c.example.com:8890', 'stream', 'connecting'],
        ]);

    // A patch that does not mention relays leaves them alone.
    Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->labels(['show_id' => '2041']));

    expect(count($listed()))->toBe(2);

    // An empty array removes every stream-sourced destination and nothing else.
    Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->relays([]));

    expect($listed())->toBe([
        ['pushed', 'srt://pushed.example.com:8890', 'api', 'connecting'],
    ]);
});

it('keeps the resolved feed on a patch that leaves the feed and the state alone', function (): void {
    $fake = Tmesis::fake();

    // A connected and a degraded destination, as a node reports them mid-show.
    $fake->withRelays('show-2041-main', [
        ['id' => 'live', 'url' => 'srt://a.example.com:8890', 'enabled' => true, 'source' => 'api',
            'state' => 'connected', 'bytes_out' => 10, 'dropped_bytes' => 0, 'reconnects' => 0,
            'feed' => 'auto', 'feed_resolved' => 'ingest'],
        ['id' => 'shaky', 'url' => 'srt://b.example.com:8890', 'enabled' => true, 'source' => 'api',
            'state' => 'degraded', 'bytes_out' => 10, 'dropped_bytes' => 5, 'reconnects' => 2,
            'feed' => 'rung:720p', 'feed_resolved' => 'rung:720p'],
    ]);

    // A URL-only patch: the state stays, and so does what the destination is sent.
    $live = Tmesis::node()->relays()->updateRelay('show-2041-main', 'live', RelayPatch::make(url: 'srt://a2.example.com:8890'));
    $shaky = Tmesis::node()->relays()->updateRelay('show-2041-main', 'shaky', RelayPatch::make(url: 'srt://b2.example.com:8890'));

    expect($live->state())->toBe('connected')
        ->and($live->feedResolved())->toBe('ingest')
        ->and($shaky->state())->toBe('degraded')
        ->and($shaky->feedResolved())->toBe('rung:720p');

    // Parking one is the change that empties it; starting it again resolves it.
    $parked = Tmesis::node()->relays()->updateRelay('show-2041-main', 'shaky', RelayPatch::make(enabled: false));
    $started = Tmesis::node()->relays()->updateRelay('show-2041-main', 'shaky', RelayPatch::make(enabled: true));

    expect($parked->feedResolved())->toBe('')
        ->and($started->feedResolved())->toBe('rung:720p');
});

it('never mints a destination id it has already used', function (): void {
    Tmesis::fake();

    $first = Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://a.example.com:8890'));
    Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://b.example.com:8890'));

    Tmesis::node()->relays()->deleteRelay('show-2041-main', $first->id());

    // A count based id would hand this one `relay-2`, which the second create
    // already owns.
    $third = Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://c.example.com:8890'));

    expect($third->id())->toBe('relay-3')
        ->and(array_map(
            static fn ($relay): string => $relay->id(),
            Tmesis::node()->relays()->listRelays('show-2041-main')->relays(),
        ))->toBe(['relay-2', 'relay-3']);
});

it('refuses through the fake exactly what the node refuses', function (): void {
    $fake = Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main');

    expect(fn () => Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => Tmesis::node()->relays()->createRelay(
            'show-2041-main',
            RelayInput::make('srt://a.example.com:8890', id: 'stop'),
        ))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => Tmesis::node()->relays()->updateRelay('show-2041-main', 'r1', RelayPatch::make()))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn () => StreamSpec::make()->relays([RelayInput::make('srt://a.example.com:8890', id: 'stop')]))
        ->toThrow(InvalidConfigurationException::class);

    // And none of the four reached the call log: the guard runs before the record,
    // so a refused write is not a write a consumer can assert on.
    expect(array_column($fake->calls(), 'operation'))->toBe(['createStream']);
});

it('counts only the destinations that were running as stopped', function (): void {
    Tmesis::fake();

    Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://a.example.com:8890', id: 'a'));
    Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://b.example.com:8890', id: 'b', enabled: false));

    expect(Tmesis::node()->relays()->stopRelays('show-2041-main')->stopped())->toBe(1);
});

it('raises not found for a write against a stream it has never heard of', function (): void {
    Tmesis::fake();

    expect(fn () => Tmesis::node()->streams()->kickStream('nobody-here'))->toThrow(NotFoundException::class)
        ->and(fn () => Tmesis::node()->relays()->deleteRelay('nobody-here', 'r1'))->toThrow(NotFoundException::class);
});

it('throws whatever a test asked it to throw, on a write', function (): void {
    Tmesis::fake()->throwOn('createStream', new ConflictException('a stream with this id already exists'));

    expect(fn () => Tmesis::node()->streams()->createStream('show-2041-main'))->toThrow(ConflictException::class);
});

it('never talks to a node', function (): void {
    Http::preventStrayRequests();
    Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->ladder('broadcast-720p'));
    Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('srt://a.example.com:8890'));

    Http::assertNothingSent();
});

it('round-trips a publish hook through the fake', function (): void {
    $this->withHooksEnabled();

    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::reset();
    RecordingDecider::$answer = HookDecision::deny('this show was cancelled');

    Event::fake([PublishHookDecided::class]);

    $decision = FakeTmesis::hook(['stream' => 'show-2041-main', 'key_type' => 'bare_unproven'])
        ->decision($this->app);

    expect($decision)->toBe(['allow' => false, 'reason' => 'this show was cancelled'])
        ->and(RecordingDecider::$last?->stream())->toBe('show-2041-main')
        ->and(RecordingDecider::$last?->keyType())->toBe('bare_unproven');

    // deliverTo() ran the kernel's terminate(), so the record was dispatched.
    Event::assertDispatchedTimes(PublishHookDecided::class, 1);
});

it('signs a hook with the hook secret and can be told to get it wrong', function (): void {
    $this->withHooksEnabled();

    expect(FakeTmesis::hook()->deliverTo($this->app)->getStatusCode())->toBe(200)
        ->and(FakeTmesis::hook()->withSecret('the-wrong-secret')->deliverTo($this->app)->getStatusCode())->toBe(401);
});

it('refuses a hook sent as another event', function (): void {
    $this->withHooksEnabled();

    expect(FakeTmesis::hook()->asEvent('publish.takeover')->deliverTo($this->app)->getStatusCode())->toBe(400);
});

it('splits a mixed preview patch by dotted path, the way the node does', function (): void {
    Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main');

    $patch = Tmesis::node()->streams()->updateStream(
        'show-2041-main',
        StreamSpec::make()->previewEnabled(false)->previewMaxMonitors(10),
    );

    // preview.max_monitors is live and preview.enabled is next_session, on the SAME
    // patch: a fake keyed on the top-level field could not say both.
    expect($patch->applied())->toBe(['preview.max_monitors' => 'live'])
        ->and($patch->pending())->toHaveKey('preview.enabled')
        ->and($patch->pending()['preview.enabled']->when())->toBe(PendingChange::WHEN_NEXT_SESSION)
        ->and($patch->pending()['preview.enabled']->current())->toBeTrue()
        ->and($patch->pending()['preview.enabled']->requested())->toBeFalse();

    // Pending means pending: the read shows the new cap and the running tap.
    $preview = Tmesis::node()->getStream('show-2041-main')->preview();

    expect($preview->maxMonitors())->toBe(10)
        ->and($preview->enabled())->toBeTrue();
});

it('clears the ingest destination to null through the fake', function (): void {
    Tmesis::fake();

    Tmesis::node()->streams()->createStream(
        'show-2041-main',
        StreamSpec::make()->ingestDestination('/mnt/archive/ingest')->ingestRetentionHours(24),
    );

    $before = Tmesis::node()->getStream('show-2041-main')->recording()->ingest();

    // A spec that set one leaf of the block merged into the defaults rather than
    // replacing them: enabled is still the standalone default.
    expect($before->destination())->toBe('/mnt/archive/ingest')
        ->and($before->retentionHours())->toBe(24)
        ->and($before->enabled())->toBeTrue();

    $patch = Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->ingestDestination(null));

    expect($patch->applied())->toBe(['recording.ingest.destination' => 'live'])
        ->and(Tmesis::node()->getStream('show-2041-main')->recording()->ingest()->destination())->toBeNull()
        ->and(Tmesis::node()->getStream('show-2041-main')->recording()->ingest()->retentionHours())->toBe(24);
});

it('puts a captions patch in pending at next_segment, now that the node implements it', function (): void {
    Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main');

    $patch = Tmesis::node()->streams()->updateStream(
        'show-2041-main',
        StreamSpec::make()->captionsEnabled(true)->captionsLanguages(['es'])->previewMaxMonitors(10),
    );

    // Until tmesis v0.3.0 every next_segment field also carried a milestone and
    // answered 501; the four captions.* paths are the first that are reachable.
    expect($patch->applied())->toBe(['preview.max_monitors' => 'live'])
        ->and(array_keys($patch->pending()))->toBe(['captions.enabled', 'captions.languages'])
        ->and($patch->pending()['captions.enabled']->when())->toBe(PendingChange::WHEN_NEXT_SEGMENT)
        ->and($patch->pending()['captions.enabled']->current())->toBeFalse()
        ->and($patch->pending()['captions.enabled']->requested())->toBeTrue()
        ->and($patch->pending()['captions.languages']->current())->toBe(['en'])
        ->and($patch->pending()['captions.languages']->requested())->toBe(['es']);

    // Pending means pending: the read still carries the running block.
    $captions = Tmesis::node()->getStream('show-2041-main')->captions();

    expect($captions->enabled())->toBeFalse()
        ->and($captions->languages())->toBe(['en']);
});
