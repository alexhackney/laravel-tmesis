<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\EnrollRequest;
use AlexHackney\Tmesis\Events\Fleet\FleetAdmissionChanged;
use AlexHackney\Tmesis\Events\Fleet\FleetCommandAcked;
use AlexHackney\Tmesis\Events\Fleet\FleetCommandExpired;
use AlexHackney\Tmesis\Events\Fleet\FleetDesiredStateFailed;
use AlexHackney\Tmesis\Events\Fleet\FleetHeartbeatReceived;
use AlexHackney\Tmesis\Events\Fleet\FleetJobsPauseChanged;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeRestarted;
use AlexHackney\Tmesis\Events\Fleet\FleetRevisionApplied;
use AlexHackney\Tmesis\Events\Fleet\FleetRevisionRejected;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredJobsPause;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Fleet\Enroller;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Fleet\HeartbeatHandler;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Tests\Doubles\ArrayDesiredStateProvider;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * F3 and F5: POST {prefix}/fleet/heartbeat, driven the way the node's heartbeat loop
 * drives it. The durability line (every event id recorded before the answer acks it),
 * the resend rule (applied revision OR revocation digest), commands, and one event
 * identity across both channels.
 */

const BEAT_NODE = 'site-7';

/**
 * @return array<string, mixed>
 */
function beat(array $overrides = []): array
{
    return array_merge([
        'node' => BEAT_NODE,
        'role' => 'edge',
        'version' => '0.7.0',
        'started_at' => '2026-09-28T10:00:00.000Z',
        'observed_at' => '2026-09-28T12:00:00.000Z',
        'desired_revision_applied' => 0,
        'admission' => 'open',
        'revocations_digest' => RevocationsDigest::EMPTY,
        'desired_revision_rejected' => null,
        'stats' => ['uptime_seconds' => 7200, 'sessions_active' => 1, 'disk_free_bytes' => 412316860416, 'disk_floor_breached' => false, 'outbox_depth' => 0, 'recordings_unregistered' => 0],
        'streams' => [['stream' => 'show-2041-main', 'state' => 'live', 'session' => '01J8ZC8XKWQ4M7T0K3PZ2B9RVN', 'epoch' => 3, 'protocol' => 'srt', 'dual_accept' => false]],
        'events' => [],
        'acked_command_ids' => [],
        'jobs_pause' => null,
    ], $overrides);
}

function postBeat(object $test, ?string $token, array $body, ?string $raw = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }

    return $test->call('POST', route('tmesis.fleet.heartbeat'), [], [], [], $server, $raw ?? json_encode($body, JSON_UNESCAPED_SLASHES));
}

/**
 * A stream.published body as this node would carry it, as the heartbeat's payload
 * STRING and its id.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function fleetEvent(object $test, string $delivery): array
{
    $payload = (fn () => $this->exampleAsTestNode('stream.published'))->call($test);
    $payload['node'] = BEAT_NODE;
    $payload['delivery'] = $delivery;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    return [['id' => $delivery, 'event' => 'stream.published', 'occurred_at' => $payload['occurred_at'], 'payload' => $body], $body];
}

beforeEach(function (): void {
    $this->withFleet();
    ArrayDesiredStateProvider::$documents[BEAT_NODE] = DesiredDocument::make()
        ->stream(DesiredStream::make('show-2041-main', DesiredRecording::make(true, 72, null, 'tsp-archive'), 6, ['site' => 'csn']));

    $token = Tmesis::fleet()->joinToken(BEAT_NODE);
    $outcome = app(Enroller::class)->enroll($token->reveal(), EnrollRequest::fromRaw(json_encode([
        'node' => BEAT_NODE, 'role' => 'edge', 'version' => '0.7.0',
        'listen' => ['rtmp' => ':1935', 'srt' => ':6001'], 'capabilities' => ['fleet.commands.kick' => true],
    ])));

    $this->nodeId = $outcome->nodeId;
    $this->token = $outcome->fleetToken();
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m): void {
        $this->logged[] = $m->message.' '.json_encode($m->context);
    });
});

it('refuses an unknown token with an empty 401, recording nothing', function (?string $token): void {
    postBeat($this, $token, beat())->assertStatus(401)->assertContent('{}');

    expect(Tmesis::fleet()->node($this->nodeId)->lastHeartbeatAt)->toBeNull();
})->with(['no token' => [null], 'a wrong token' => ['tmf_nope']]);

it('refuses a body that is not the contract shape with 422 naming the path, recording nothing', function (array $body, string $path): void {
    postBeat($this, $this->token, $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJson(fn ($json) => $json->where('error.message', fn ($m) => str_starts_with($m, $path))->etc());

    expect(Tmesis::fleet()->node($this->nodeId)->lastHeartbeatAt)->toBeNull();
})->with([
    'no stats' => [array_diff_key(beat(), ['stats' => 1]), '$.stats'],
    'no desired_revision_rejected' => [array_diff_key(beat(), ['desired_revision_rejected' => 1]), '$.desired_revision_rejected'],
    'a bad admission' => [beat(['admission' => 'closed']), '$.admission'],
    'a negative revision' => [beat(['desired_revision_applied' => -1]), '$.desired_revision_applied'],
    'an uppercase digest' => [beat(['revocations_digest' => strtoupper(RevocationsDigest::EMPTY)]), '$.revocations_digest'],
    'an event with no payload' => [beat(['events' => [['id' => 'x', 'event' => 'e', 'occurred_at' => '']]]), '$.events[0].payload'],
]);

it('refuses a body over the limit with 413 before reading it', function (): void {
    $raw = json_encode(beat(['node' => str_repeat('x', 4194304)]));

    postBeat($this, $this->token, [], $raw)->assertStatus(413);

    expect(Tmesis::fleet()->node($this->nodeId)->lastHeartbeatAt)->toBeNull();
});

it('records the first beat and answers the full desired state at revision 1, on the schema', function (): void {
    Event::fake([FleetHeartbeatReceived::class, FleetAdmissionChanged::class]);

    $response = postBeat($this, $this->token, beat())->assertOk();

    SchemaValidator::forSchema('fleet.heartbeat.response')->assertValid(json_decode($response->getContent(), false), context: 'The heartbeat answer');

    expect($response->json('desired_state.revision'))->toBe(1)
        ->and($response->json('ack_event_ids'))->toBe([])
        ->and($response->json('commands'))->toBe([]);

    $node = Tmesis::fleet()->node($this->nodeId);
    expect($node->lastHeartbeatAt)->not->toBeNull()
        ->and($node->admission)->toBe('open')
        ->and($node->stats->diskFreeBytes)->toBe(412316860416)
        ->and($node->stream('show-2041-main')->session)->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($node->converged())->toBeFalse();

    Event::assertDispatched(FleetHeartbeatReceived::class);
    Event::assertDispatched(FleetAdmissionChanged::class, fn ($e) => $e->previous === null && $e->node->admission === 'open');
});

it('answers null once the node has applied the revision with the intended list, and converges', function (): void {
    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertOk()->assertJsonPath('desired_state', null);

    expect(Tmesis::fleet()->node($this->nodeId)->converged())->toBeTrue();
});

it('resends the full state at the SAME revision when the node\'s revocation list drifted (WP5)', function (): void {
    postBeat($this, $this->token, beat(['desired_revision_applied' => 1, 'revocations_digest' => RevocationsDigest::of(['show-2039-rehearsal'])]))
        ->assertOk()
        ->assertJsonPath('desired_state.revision', 1)
        ->assertJsonPath('desired_state.revocations', []);

    expect(Tmesis::fleet()->node($this->nodeId)->converged())->toBeFalse();
});

it('moves to revision 2 only when the provider\'s content changes', function (): void {
    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertJsonPath('desired_state', null);

    ArrayDesiredStateProvider::$documents[BEAT_NODE]->revoke('show-2039-rehearsal');

    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))
        ->assertJsonPath('desired_state.revision', 2)
        ->assertJsonPath('desired_state.revocations', ['show-2039-rehearsal']);

    postBeat($this, $this->token, beat(['desired_revision_applied' => 2, 'revocations_digest' => RevocationsDigest::of(['show-2039-rehearsal'])]))
        ->assertJsonPath('desired_state', null);
});

it('does not bump any revision when APP_KEY rotates with the old key in previous_keys', function (): void {
    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertJsonPath('desired_state', null);

    $old = config('app.key');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));
    config()->set('app.previous_keys', [$old]);

    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertJsonPath('desired_state', null);
    config()->set('app.previous_keys', []);
    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertJsonPath('desired_state', null);

    expect(Tmesis::fleet()->node($this->nodeId)->intendedRevision)->toBe(1);
});

it('bumps each node ONCE, to identical content, when APP_KEY rotates without previous_keys (documented)', function (): void {
    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertJsonPath('desired_state', null);
    $before = postBeat($this, $this->token, beat())->json('desired_state');

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));

    $after = postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertJsonPath('desired_state.revision', 2)->json('desired_state');
    postBeat($this, $this->token, beat(['desired_revision_applied' => 2]))->assertJsonPath('desired_state', null);

    unset($before['revision'], $after['revision']);
    expect($after)->toBe($before);
});

it('still records and acks when the provider throws, answering desired_state null', function (): void {
    ArrayDesiredStateProvider::$throws = true;
    Event::fake([FleetDesiredStateFailed::class]);
    [$event] = fleetEvent($this, '01J8ZCQ2N8R4VKX7QM3WB5TDH9');

    postBeat($this, $this->token, beat(['events' => [$event]]))
        ->assertOk()
        ->assertJsonPath('desired_state', null)
        ->assertJsonPath('ack_event_ids', ['01J8ZCQ2N8R4VKX7QM3WB5TDH9']);

    Event::assertDispatched(FleetDesiredStateFailed::class);
});

it('dispatches a fleet event as the SAME Laravel event the webhook dispatches, once, marked fleet', function (): void {
    $seen = [];
    Event::listen(StreamPublished::class, function (StreamPublished $e) use (&$seen): void {
        $seen[] = [$e->deliveryId(), $e->node(), $e->delivery()?->channel];
    });
    [$event] = fleetEvent($this, '01J8ZCQ2N8R4VKX7QM3WB5TDH9');

    postBeat($this, $this->token, beat(['events' => [$event]]))->assertJsonPath('ack_event_ids', ['01J8ZCQ2N8R4VKX7QM3WB5TDH9']);
    // The node did not see the ack (say) and re-sends: acked again, not dispatched again.
    postBeat($this, $this->token, beat(['events' => [$event]]))->assertJsonPath('ack_event_ids', ['01J8ZCQ2N8R4VKX7QM3WB5TDH9']);

    expect($seen)->toBe([['01J8ZCQ2N8R4VKX7QM3WB5TDH9', BEAT_NODE, ReceivedDelivery::CHANNEL_FLEET]]);
});

it('dispatches once whichever channel carries the event first (D7)', function (bool $webhookFirst): void {
    config()->set('tmesis.nodes.'.BEAT_NODE, ['url' => null, 'token' => 't', 'webhook_secret' => self::WEBHOOK_SECRET]);
    $count = 0;
    Event::listen(StreamPublished::class, function () use (&$count): void {
        $count++;
    });
    [$event, $body] = fleetEvent($this, '01J8ZCQ3T6K4NQX8VM2TRB7PD5');
    $webhook = fn () => $this->signedPostRaw('stream.published', $body, ['HTTP_X_TMESIS_NODE' => BEAT_NODE])->assertNoContent();
    $fleet = fn () => postBeat($this, $this->token, beat(['events' => [$event]]))->assertJsonPath('ack_event_ids', ['01J8ZCQ3T6K4NQX8VM2TRB7PD5']);

    $webhookFirst ? $webhook() : $fleet();
    $webhookFirst ? $fleet() : $webhook();

    expect($count)->toBe(1);
})->with(['webhook first' => [true], 'fleet first' => [false]]);

it('acks and drops a payload that is not a JSON object', function (): void {
    postBeat($this, $this->token, beat(['events' => [['id' => '01BAD', 'event' => 'stream.published', 'occurred_at' => '', 'payload' => '[1,2]']]]))
        ->assertJsonPath('ack_event_ids', ['01BAD']);

    expect(DB::table('tmesis_webhook_deliveries')->count())->toBe(0);
});

it('acks nothing and records nothing durable when an event cannot be claimed (503)', function (): void {
    $failing = Mockery::mock(app(DeliveryDeduper::class))->makePartial();
    $failing->shouldReceive('claim')->andThrow(new RuntimeException('the database is down (test)'));
    app()->instance(DeliveryDeduper::class, $failing);
    app()->forgetInstance(HeartbeatHandler::class);
    [$event] = fleetEvent($this, '01J8ZCQ2N8R4VKX7QM3WB5TDH9');

    postBeat($this, $this->token, beat(['events' => [$event]]))->assertStatus(503)->assertJsonMissingPath('ack_event_ids');
});

it('re-sends a delivered command until acked, and never expires it', function (): void {
    Event::fake([FleetCommandAcked::class]);
    $store = app(FleetStore::class);
    $store->queueCommand('01CMDKICK00000000000000000', $this->nodeId, 'kick', 'show-2041-main', [], now()->toImmutable(), now()->toImmutable()->addMinutes(15));

    postBeat($this, $this->token, beat())->assertJsonPath('commands', [['id' => '01CMDKICK00000000000000000', 'kind' => 'kick', 'stream' => 'show-2041-main', 'args' => []]]);

    $this->travel(2)->hours();
    postBeat($this, $this->token, beat())->assertJsonPath('commands.0.id', '01CMDKICK00000000000000000');

    postBeat($this, $this->token, beat(['acked_command_ids' => ['01CMDKICK00000000000000000']]))->assertJsonPath('commands', []);

    Event::assertDispatched(FleetCommandAcked::class, fn ($e) => $e->commandId === '01CMDKICK00000000000000000' && ! $e->late);
});

it('sends args as an object, grace_seconds as a string', function (): void {
    app(FleetStore::class)->queueCommand('01CMDSEAL00000000000000000', $this->nodeId, 'seal', 's', ['grace_seconds' => '30'], now()->toImmutable(), now()->toImmutable()->addMinutes(15));
    app(FleetStore::class)->queueCommand('01CMDDRAIN0000000000000000', $this->nodeId, 'drain', null, [], now()->toImmutable()->addSecond(), now()->toImmutable()->addMinutes(15));

    $raw = postBeat($this, $this->token, beat())->getContent();

    expect($raw)->toContain('"args":{"grace_seconds":"30"}')
        ->and($raw)->toContain('"stream":null,"args":{}');

    SchemaValidator::forSchema('fleet.heartbeat.response')->assertValid(json_decode($raw, false), context: 'An answer with commands');
});

it('expires a command never delivered within its TTL, and never sends it', function (): void {
    Event::fake([FleetCommandExpired::class]);
    app(FleetStore::class)->queueCommand('01CMDLATE00000000000000000', $this->nodeId, 'kick', 's', [], now()->toImmutable(), now()->toImmutable()->addMinutes(15));

    $this->travel(16)->minutes();

    postBeat($this, $this->token, beat())->assertJsonPath('commands', []);

    Event::assertDispatched(FleetCommandExpired::class, fn ($e) => $e->commandId === '01CMDLATE00000000000000000');
});

it('observes a restart, an applied revision and a rejected revision once each', function (): void {
    Event::fake([FleetNodeRestarted::class, FleetRevisionApplied::class, FleetRevisionRejected::class]);

    postBeat($this, $this->token, beat());
    postBeat($this, $this->token, beat(['started_at' => '2026-09-28T11:30:00.000Z', 'desired_revision_applied' => 1]));
    $rejected = ['revision' => 2, 'reason' => 'streams[0].recording: destination and destination_ref are both set'];
    postBeat($this, $this->token, beat(['started_at' => '2026-09-28T11:30:00.000Z', 'desired_revision_applied' => 1, 'desired_revision_rejected' => $rejected]));
    postBeat($this, $this->token, beat(['started_at' => '2026-09-28T11:30:00.000Z', 'desired_revision_applied' => 1, 'desired_revision_rejected' => $rejected]));

    Event::assertDispatchedTimes(FleetNodeRestarted::class, 1);
    Event::assertDispatchedTimes(FleetRevisionApplied::class, 1);
    Event::assertDispatchedTimes(FleetRevisionRejected::class, 1);

    expect(Tmesis::fleet()->node($this->nodeId)->rejected->reason)->toStartWith('streams[0].recording');
});

it('never logs the fleet token or an event payload', function (): void {
    [$event] = fleetEvent($this, '01J8ZCQ2N8R4VKX7QM3WB5TDH9');
    postBeat($this, $this->token, beat(['events' => [$event, ['id' => '01BAD', 'event' => 'x', 'occurred_at' => '', 'payload' => '"CANARY-PAYLOAD"']]]));
    postBeat($this, $this->token, beat(['admission' => 'nope']));

    $log = implode("\n", $this->logged);

    expect($log)->not->toContain($this->token)
        ->and($log)->not->toContain('CANARY-PAYLOAD');
});

it('keeps a fleet event whose listener failed, and replays it with tmesis:webhooks:retry, still marked fleet', function (): void {
    $fail = true;
    $channels = [];
    Event::listen(StreamPublished::class, function (StreamPublished $e) use (&$fail, &$channels): void {
        $channels[] = $e->delivery()?->channel;

        if ($fail) {
            throw new RuntimeException('listener down (test)');
        }
    });
    [$event] = fleetEvent($this, '01J8ZCQ2N8R4VKX7QM3WB5TDH9');

    postBeat($this, $this->token, beat(['events' => [$event]]))->assertJsonPath('ack_event_ids', ['01J8ZCQ2N8R4VKX7QM3WB5TDH9']);

    expect(DB::table('tmesis_webhook_deliveries')->where('delivery', '01J8ZCQ2N8R4VKX7QM3WB5TDH9')->value('processed_at'))->toBeNull();

    $fail = false;
    DB::table('tmesis_webhook_deliveries')->update(['leased_until' => null]);
    $this->travel(2)->minutes();
    $this->artisan('tmesis:webhooks:retry')->assertSuccessful();

    expect($channels)->toBe([ReceivedDelivery::CHANNEL_FLEET, ReceivedDelivery::CHANNEL_FLEET])
        ->and(DB::table('tmesis_webhook_deliveries')->where('delivery', '01J8ZCQ2N8R4VKX7QM3WB5TDH9')->value('processed_at'))->not->toBeNull();
});

it('records the jobs pause the heartbeat reports on the node, and observes it moving (v0.9.0)', function (): void {
    Event::fake([FleetJobsPauseChanged::class]);
    // An until well past the wall clock: jobsPaused() applies it (finding A, 0.9.0 review).
    $pause = ['until' => '2099-08-28T19:00:00Z', 'reason' => 'show: fight night 12', 'source' => 'api'];

    postBeat($this, $this->token, beat())->assertOk();

    expect(Tmesis::fleet()->node($this->nodeId)->jobsPause)->toBeNull()
        ->and(Tmesis::fleet()->node($this->nodeId)->jobsPaused())->toBeFalse();
    Event::assertNotDispatched(FleetJobsPauseChanged::class);

    postBeat($this, $this->token, beat(['jobs_pause' => $pause]))->assertOk();

    $node = Tmesis::fleet()->node($this->nodeId);
    expect($node->jobsPaused())->toBeTrue()
        ->and($node->jobsPause->toArray())->toBe($pause)
        // The pause rides beside the stats; the stats still read as stats.
        ->and($node->stats->diskFreeBytes)->toBe(412316860416);
    Event::assertDispatchedTimes(FleetJobsPauseChanged::class, 1);
    Event::assertDispatched(FleetJobsPauseChanged::class, fn ($e) => $e->previous === null && $e->node->jobsPause?->reason === 'show: fight night 12');

    // The same pause again is no change.
    postBeat($this, $this->token, beat(['jobs_pause' => $pause]))->assertOk();
    Event::assertDispatchedTimes(FleetJobsPauseChanged::class, 1);

    // The first revision replaced the API pause with the fleet's: a change of source.
    postBeat($this, $this->token, beat(['jobs_pause' => ['source' => 'fleet'] + $pause]))->assertOk();
    Event::assertDispatchedTimes(FleetJobsPauseChanged::class, 2);

    postBeat($this, $this->token, beat(['jobs_pause' => null]))->assertOk();

    expect(Tmesis::fleet()->node($this->nodeId)->jobsPaused())->toBeFalse();
    Event::assertDispatchedTimes(FleetJobsPauseChanged::class, 3);
    Event::assertDispatched(FleetJobsPauseChanged::class, fn ($e) => $e->previous?->source === 'fleet' && $e->node->jobsPause === null);
});

it('records a pre-0.9.0 heartbeat without jobs_pause as no pause, so the fleet stays up mid-upgrade (v0.9.0)', function (): void {
    postBeat($this, $this->token, beat(['jobs_pause' => ['until' => null, 'reason' => 'r', 'source' => 'api']]))->assertOk();
    postBeat($this, $this->token, array_diff_key(beat(), ['jobs_pause' => 1]))->assertOk();

    $node = Tmesis::fleet()->node($this->nodeId);
    expect($node->lastHeartbeatAt)->not->toBeNull()
        ->and($node->jobsPaused())->toBeFalse();
});

it('refuses a malformed jobs_pause with 422 naming the path, recording nothing (v0.9.0)', function (mixed $pause, string $path): void {
    postBeat($this, $this->token, beat(['jobs_pause' => $pause]))
        ->assertStatus(422)
        ->assertJson(fn ($json) => $json->where('error.message', fn ($m) => str_starts_with($m, $path))->etc());

    expect(Tmesis::fleet()->node($this->nodeId)->lastHeartbeatAt)->toBeNull();
})->with([
    'a string' => ['paused', '$.jobs_pause'],
    'a bad source' => [['until' => null, 'reason' => 'r', 'source' => 'operator'], '$.jobs_pause.source'],
    'no reason' => [['until' => null, 'source' => 'fleet'], '$.jobs_pause.reason'],
]);

it('sends the told jobs pause in the desired state, and moves the revision only when it changes (v0.9.0)', function (): void {
    postBeat($this, $this->token, beat())->assertJsonPath('desired_state.revision', 1)->assertJsonPath('desired_state.jobs_pause', null);
    postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))->assertJsonPath('desired_state', null);

    ArrayDesiredStateProvider::$documents[BEAT_NODE]->jobsPause(DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z'));

    $response = postBeat($this, $this->token, beat(['desired_revision_applied' => 1]))
        ->assertJsonPath('desired_state.revision', 2)
        ->assertJsonPath('desired_state.jobs_pause', ['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12']);

    SchemaValidator::forSchema('fleet.heartbeat.response')->assertValid(json_decode($response->getContent(), false), context: 'An answer with a jobs pause');

    // The same pause on the next document: no new revision.
    postBeat($this, $this->token, beat(['desired_revision_applied' => 2]))->assertJsonPath('desired_state', null);

    // The document built without it is the resume: a new revision, jobs_pause null.
    ArrayDesiredStateProvider::$documents[BEAT_NODE]->jobsPause(null);

    postBeat($this, $this->token, beat(['desired_revision_applied' => 2]))
        ->assertJsonPath('desired_state.revision', 3)
        ->assertJsonPath('desired_state.jobs_pause', null);
});
