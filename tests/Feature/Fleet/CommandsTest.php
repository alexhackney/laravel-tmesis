<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\EnrollRequest;
use AlexHackney\Tmesis\Events\Fleet\FleetCommandExpired;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeRecovered;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeStale;
use AlexHackney\Tmesis\Exceptions\FleetNodeNotEnrolledException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\DatabaseFleet;
use AlexHackney\Tmesis\Fleet\Enroller;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Illuminate\Support\Facades\Event;

/*
 * F7 (the command API) and the rest of F6 (stale nodes, pruning), end to end through
 * the heartbeat route.
 */

function commandsBeat(object $test, string $token, array $acked = []): array
{
    $body = [
        'node' => 'site-7', 'role' => 'edge', 'version' => '0.7.0',
        'started_at' => '2026-09-28T10:00:00.000Z', 'observed_at' => '2026-09-28T12:00:00.000Z',
        'desired_revision_applied' => 1, 'admission' => 'open', 'revocations_digest' => RevocationsDigest::EMPTY,
        'desired_revision_rejected' => null,
        'stats' => ['uptime_seconds' => 1, 'sessions_active' => 0, 'disk_free_bytes' => 1, 'disk_floor_breached' => false, 'outbox_depth' => 0, 'recordings_unregistered' => 0],
        'streams' => [], 'events' => [], 'acked_command_ids' => $acked,
        'jobs_pause' => null,
    ];

    $response = $test->call('POST', route('tmesis.fleet.heartbeat'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], json_encode($body));
    $response->assertOk();

    SchemaValidator::forSchema('fleet.heartbeat.response')->assertValid(json_decode($response->getContent(), false), context: 'An answer with commands');

    return $response->json();
}

beforeEach(function (): void {
    $this->withFleet();
    $outcome = app(Enroller::class)->enroll(Tmesis::fleet()->joinToken('site-7')->reveal(), EnrollRequest::fromRaw(json_encode([
        'node' => 'site-7', 'role' => 'edge', 'version' => '0.7.0', 'listen' => ['rtmp' => '', 'srt' => ''], 'capabilities' => new stdClass,
    ])));
    $this->nodeId = $outcome->nodeId;
    $this->token = $outcome->fleetToken();
});

it('queues drain, kick and seal, sends them in order on the schema, and stops once each is acked', function (): void {
    $drain = Tmesis::fleet()->drain($this->nodeId);
    $kick = Tmesis::fleet()->kick($this->nodeId, 'show-2041-main');
    $seal = Tmesis::fleet()->seal($this->nodeId, 'show-2041-main', 30);

    $answer = commandsBeat($this, $this->token);

    expect(array_column($answer['commands'], 'id'))->toBe([$drain, $kick, $seal])
        ->and($answer['commands'][0])->toBe(['id' => $drain, 'kind' => 'drain', 'stream' => null, 'args' => []])
        ->and($answer['commands'][1])->toBe(['id' => $kick, 'kind' => 'kick', 'stream' => 'show-2041-main', 'args' => []])
        ->and($answer['commands'][2])->toBe(['id' => $seal, 'kind' => 'seal', 'stream' => 'show-2041-main', 'args' => ['grace_seconds' => '30']]);

    expect(array_column(commandsBeat($this, $this->token, [$drain, $kick])['commands'], 'id'))->toBe([$seal])
        ->and(commandsBeat($this, $this->token, [$seal])['commands'])->toBe([]);
});

it('queues update with the BARE version and reload_env with no args, on the schema (tmesis v0.8.0)', function (): void {
    $update = Tmesis::fleet()->update($this->nodeId, 'v0.8.1');
    $rc = Tmesis::fleet()->update($this->nodeId, '0.8.1-rc1');
    $reload = Tmesis::fleet()->reloadEnv($this->nodeId);

    $answer = commandsBeat($this, $this->token);

    // One leading v before a digit is stripped, as the node does (TMESIS-60), so the
    // version compares against the heartbeat's bare one. The ack carries no outcome:
    // the next heartbeat's version is the result.
    expect($answer['commands'])->toBe([
        ['id' => $update, 'kind' => 'update', 'stream' => null, 'args' => ['version' => '0.8.1']],
        ['id' => $rc, 'kind' => 'update', 'stream' => null, 'args' => ['version' => '0.8.1-rc1']],
        ['id' => $reload, 'kind' => 'reload_env', 'stream' => null, 'args' => []],
    ]);
});

it('refuses an update version the node would never take, with the node\'s sentence', function (string $version): void {
    expect(fn () => Tmesis::fleet()->update($this->nodeId, $version))
        ->toThrow(InvalidConfigurationException::class, 'is not a plausible version string');
})->with(['', '.', '../0.8.1', '0.8.1/evil', '0.8 .1', "0.8.1\n", "v0.8.1\n"]);

it('strips only one leading v, and only before a digit', function (): void {
    expect(DatabaseFleet::bareVersion('v0.8.1'))->toBe('0.8.1')
        ->and(DatabaseFleet::bareVersion('0.8.1'))->toBe('0.8.1')
        ->and(DatabaseFleet::bareVersion('vv0.8.1'))->toBe('vv0.8.1')
        ->and(DatabaseFleet::bareVersion('version'))->toBe('version');
});

it('refuses a command for a node that is not enrolled, a bad stream id, and a grace outside 0 to 300', function (): void {
    expect(fn () => Tmesis::fleet()->drain('01NOSUCHNODE00000000000000'))->toThrow(FleetNodeNotEnrolledException::class)
        ->and(fn () => Tmesis::fleet()->kick($this->nodeId, 'Not A Stream'))->toThrow(InvalidConfigurationException::class, 'stream')
        ->and(fn () => Tmesis::fleet()->seal($this->nodeId, 's', 301))->toThrow(InvalidConfigurationException::class, 'grace_seconds')
        ->and(fn () => Tmesis::fleet()->seal($this->nodeId, 's', -1))->toThrow(InvalidConfigurationException::class, 'grace_seconds');

    // The node's range, not its drain budget: it caps the effective grace itself.
    expect(Tmesis::fleet()->seal($this->nodeId, 's', 45))->toBeString()
        ->and(Tmesis::fleet()->seal($this->nodeId, 's', 300))->toBeString();

    $this->call('DELETE', route('tmesis.fleet.leave'), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])->assertNoContent();

    expect(fn () => Tmesis::fleet()->kick($this->nodeId, 's'))->toThrow(FleetNodeNotEnrolledException::class);
});

it('marks a silent node stale once, expires its undelivered commands, and recovers on its next beat', function (): void {
    Event::fake([FleetNodeStale::class, FleetCommandExpired::class, FleetNodeRecovered::class]);
    commandsBeat($this, $this->token);
    $kick = Tmesis::fleet()->kick($this->nodeId, 's');

    $this->travel(20)->minutes();
    $this->artisan('tmesis:fleet:check-stale')->expectsOutputToContain('1 node(s) newly stale, 1 command(s) expired undelivered')->assertSuccessful();
    $this->artisan('tmesis:fleet:check-stale')->expectsOutputToContain('0 node(s) newly stale, 0 command(s)')->assertSuccessful();

    Event::assertDispatchedTimes(FleetNodeStale::class, 1);
    Event::assertDispatched(FleetCommandExpired::class, fn ($e) => $e->commandId === $kick);
    expect(Tmesis::fleet()->node($this->nodeId)->isStale())->toBeTrue();

    expect(commandsBeat($this, $this->token)['commands'])->toBe([])
        ->and(Tmesis::fleet()->node($this->nodeId)->isStale())->toBeFalse();
    Event::assertDispatched(FleetNodeRecovered::class);
});

it('prunes settled commands and spent join tokens past the tombstone window, and keeps the node', function (): void {
    $kick = Tmesis::fleet()->kick($this->nodeId, 's');
    commandsBeat($this, $this->token, []);
    commandsBeat($this, $this->token, [$kick]);
    Tmesis::fleet()->kick($this->nodeId, 'open-one');

    $this->travel(15)->days();
    $this->artisan('tmesis:fleet:prune')->assertSuccessful();

    expect(DB::table('tmesis_fleet_commands')->pluck('stream')->all())->toBe(['open-one'])
        ->and(DB::table('tmesis_fleet_join_tokens')->count())->toBe(0)
        ->and(Tmesis::fleet()->node($this->nodeId))->not->toBeNull();
});

it('never sends more than 100 commands in one answer, oldest first, and stamps only what it sent', function (): void {
    $ids = [];

    for ($i = 0; $i < 105; $i++) {
        $ids[] = Tmesis::fleet()->kick($this->nodeId, "s{$i}");
        $this->travel(1)->seconds();
    }

    $first = array_column(commandsBeat($this, $this->token)['commands'], 'id');

    expect($first)->toBe(array_slice($ids, 0, 100))
        ->and(DB::table('tmesis_fleet_commands')->whereNotNull('first_sent_at')->count())->toBe(100);

    $second = array_column(commandsBeat($this, $this->token, $first)['commands'], 'id');

    expect($second)->toBe(array_slice($ids, 100));
});
