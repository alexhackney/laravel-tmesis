<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\Fleet\FleetDesiredStateFailed;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeEnrolled;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeLeft;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeReenrolled;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Tests\Doubles\ArrayDesiredStateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * F2: POST {prefix}/fleet/enroll, and F8's DELETE, driven the way `tmesis join` and
 * `tmesis leave` drive them. The D12 repeat is the heart of it: the same node id, a
 * fresh token, the previous token dead.
 */

/**
 * The vector's enroll request, as `tmesis join` sends it.
 *
 * @return array<string, mixed>
 */
function enrollBody(array $overrides = []): array
{
    return array_merge([
        'node' => 'edge-venue-07',
        'role' => 'edge',
        'version' => '0.7.0',
        'listen' => ['rtmp' => '0.0.0.0:1935', 'srt' => '0.0.0.0:8890'],
        'capabilities' => ['fleet.commands.kick' => true, 'fleet.relays' => true, 'packager' => false],
    ], $overrides);
}

function postEnroll(object $test, ?string $token, array $body = [], ?string $raw = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    }

    return $test->call('POST', route('tmesis.fleet.enroll'), [], [], [], $server, $raw ?? json_encode($body === [] ? enrollBody() : $body));
}

beforeEach(function (): void {
    $this->withFleet();
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m): void {
        $this->logged[] = $m->message.' '.json_encode($m->context);
    });
});

it('enrolls on first use: a node id, a fleet token, the heartbeat URL and revision 1', function (): void {
    ArrayDesiredStateProvider::$documents['site-7'] = DesiredDocument::make()
        ->stream(DesiredStream::make('show-2041-main', DesiredRecording::make(true, 72, null, 'tsp-archive'), 6, ['site' => 'csn']));
    Event::fake([FleetNodeEnrolled::class]);

    $token = Tmesis::fleet()->joinToken('site-7', 'edge', ['region' => 'dfw']);
    $response = postEnroll($this, $token->reveal());

    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $body = $response->json();

    expect($body['node_name'])->toBe('site-7')
        ->and($body['node_id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($body['fleet_token'])->toStartWith('tmf_')
        ->and($body['heartbeat_url'])->toBe(route('tmesis.fleet.heartbeat'))
        ->and($body['desired_state']['revision'])->toBe(1)
        ->and($body['desired_state']['streams'][0]['recording']['destination_ref'])->toBe('tsp-archive');

    SchemaValidator::forSchema('fleet.enroll.response')->assertValid(json_decode($response->getContent(), false), context: 'The enroll answer');

    $node = Tmesis::fleet()->node($body['node_id']);
    expect($node->name)->toBe('site-7')
        ->and($node->role)->toBe('edge')
        ->and($node->labels)->toBe(['region' => 'dfw'])
        ->and($node->hasCapability('fleet.relays'))->toBeTrue()
        ->and($node->intendedRevision)->toBe(1)
        ->and(app(FleetStore::class)->nodeRowByToken(hash('sha256', $body['fleet_token']))?->id)->toBe($body['node_id']);

    Event::assertDispatched(FleetNodeEnrolled::class, fn ($e) => $e->node->id === $body['node_id']);
});

it('stores no plain token and logs none', function (): void {
    $token = Tmesis::fleet()->joinToken('site-7');
    $body = postEnroll($this, $token->reveal())->json();

    $dump = json_encode(DB::table('tmesis_fleet_join_tokens')->get()).json_encode(DB::table('tmesis_fleet_nodes')->get());

    expect($dump)->not->toContain($token->reveal())
        ->and($dump)->not->toContain($body['fleet_token'])
        ->and(implode("\n", $this->logged))->not->toContain($token->reveal())
        ->and(implode("\n", $this->logged))->not->toContain($body['fleet_token'])
        ->and(print_r($token, true))->not->toContain($token->reveal());
});

it('answers a repeat inside the window with the same node id and a fresh token, revoking the last (D12)', function (): void {
    Event::fake([FleetNodeReenrolled::class]);
    $token = Tmesis::fleet()->joinToken('site-7');

    $first = postEnroll($this, $token->reveal())->json();
    $this->travel(14)->minutes();
    $second = postEnroll($this, $token->reveal())->assertOk()->json();

    $store = app(FleetStore::class);

    expect($second['node_id'])->toBe($first['node_id'])
        ->and($second['fleet_token'])->not->toBe($first['fleet_token'])
        ->and($store->nodeRowByToken(hash('sha256', $first['fleet_token'])))->toBeNull()
        ->and($store->nodeRowByToken(hash('sha256', $second['fleet_token']))?->id)->toBe($first['node_id'])
        ->and(Tmesis::fleet()->nodes())->toHaveCount(1)
        ->and($second['desired_state']['revision'])->toBe($first['desired_state']['revision']);

    Event::assertDispatched(FleetNodeReenrolled::class);
});

it('refuses a repeat after the window, an unknown token, an expired unused token, and no token, all alike', function (): void {
    $used = Tmesis::fleet()->joinToken('site-7');
    postEnroll($this, $used->reveal())->assertOk();
    $unused = Tmesis::fleet()->joinToken('site-8');

    $this->travel(61)->minutes();

    foreach ([$used->reveal(), $unused->reveal(), 'tmj_not-a-token', null] as $token) {
        postEnroll($this, $token)->assertStatus(401)->assertJsonPath('error.code', 'unknown_token');
    }
});

it('refuses a role other than the one the token was minted for, spending nothing', function (): void {
    $token = Tmesis::fleet()->joinToken('site-7', 'origin');

    postEnroll($this, $token->reveal(), enrollBody(['role' => 'edge']))
        ->assertStatus(422)->assertJsonPath('error.code', 'role_mismatch');

    expect(DB::table('tmesis_fleet_join_tokens')->value('first_used_at'))->toBeNull();

    postEnroll($this, $token->reveal(), enrollBody(['role' => 'origin']))->assertOk();
});

it('refuses a malformed body naming the path, spending nothing', function (string $raw, string $path): void {
    $token = Tmesis::fleet()->joinToken('site-7');

    postEnroll($this, $token->reveal(), raw: $raw)
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
        ->assertJson(fn ($json) => $json->where('error.message', fn ($m) => str_starts_with($m, $path))->etc());

    expect(DB::table('tmesis_fleet_join_tokens')->value('first_used_at'))->toBeNull();
})->with([
    'not JSON' => ['{', '$'],
    'a list' => ['[]', '$'],
    'no listen' => [json_encode(array_diff_key(enrollBody(), ['listen' => 1])), '$.listen'],
    'an unknown role' => [json_encode(enrollBody(['role' => 'relay'])), '$.role'],
    'a string capability' => [json_encode(enrollBody(['capabilities' => ['x' => 'yes']])), '$.capabilities.x'],
    'capabilities as a list' => [json_encode(enrollBody(['capabilities' => []])), '$.capabilities'],
]);

it('refuses to hand out a heartbeat URL the node would refuse, before spending the token', function (): void {
    config()->set('app.url', 'http://tmesis.example.com');
    URL::forceRootUrl('http://tmesis.example.com');
    $token = Tmesis::fleet()->joinToken('site-7');

    postEnroll($this, $token->reveal())->assertStatus(503)->assertJsonPath('error.code', 'heartbeat_url_insecure');

    expect(DB::table('tmesis_fleet_join_tokens')->value('first_used_at'))->toBeNull();
});

it('enrolls with desired_state null when the provider throws', function (): void {
    ArrayDesiredStateProvider::$throws = true;
    Event::fake([FleetDesiredStateFailed::class]);
    $token = Tmesis::fleet()->joinToken('site-7');

    $body = postEnroll($this, $token->reveal())->assertOk()->json();

    expect($body['desired_state'])->toBeNull()
        ->and(Tmesis::fleet()->node($body['node_id']))->not->toBeNull()
        ->and(implode("\n", $this->logged))->not->toContain('the provider is down');

    Event::assertDispatched(FleetDesiredStateFailed::class, fn ($e) => $e->exception === RuntimeException::class);
});

it('supersedes an enrolled node when a new join token enrolls the same name', function (): void {
    Event::fake([FleetNodeLeft::class]);
    $old = postEnroll($this, Tmesis::fleet()->joinToken('site-7')->reveal())->json();
    $new = postEnroll($this, Tmesis::fleet()->joinToken('site-7')->reveal())->json();

    expect($new['node_id'])->not->toBe($old['node_id'])
        ->and(Tmesis::fleet()->nodeNamed('site-7')->id)->toBe($new['node_id'])
        ->and(app(FleetStore::class)->nodeRowByToken(hash('sha256', $old['fleet_token'])))->toBeNull();

    Event::assertDispatched(FleetNodeLeft::class, fn ($e) => $e->node->id === $old['node_id'] && $e->reason === FleetNodeLeft::REASON_SUPERSEDED);
});

it('deregisters on DELETE with the fleet token, and answers 404 after (F8)', function (): void {
    Event::fake([FleetNodeLeft::class]);
    $body = postEnroll($this, Tmesis::fleet()->joinToken('site-7')->reveal())->json();
    $delete = fn (?string $t) => $this->call('DELETE', route('tmesis.fleet.leave'), [], [], [], $t === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$t]);

    $delete($body['fleet_token'])->assertNoContent();
    $delete($body['fleet_token'])->assertNotFound();
    $delete(null)->assertNotFound();
    $delete('tmf_unknown')->assertNotFound();

    expect(Tmesis::fleet()->nodes())->toBe([])
        ->and(Tmesis::fleet()->node($body['node_id'])->enrolled())->toBeFalse();

    Event::assertDispatchedTimes(FleetNodeLeft::class, 1);
});

it('prints a join token once from the command, with the exact join line', function (): void {
    $this->artisan('tmesis:fleet:join-token', ['name' => 'site-9', '--role' => 'edge', '--label' => ['region=dfw']])
        ->expectsOutputToContain('tmesis join '.route('tmesis.fleet.enroll').' --token tmj_')
        ->expectsOutputToContain('systemctl stop tmesis-update.path tmesis')
        ->assertSuccessful();

    $row = DB::table('tmesis_fleet_join_tokens')->first();
    expect($row->name)->toBe('site-9')
        ->and($row->role)->toBe('edge')
        ->and(json_decode($row->labels, true))->toBe(['region' => 'dfw'])
        ->and(strlen($row->token_sha256))->toBe(64)
        ->and(CarbonImmutable::parse($row->expires_at)->diffInMinutes(now(), true))->toBeGreaterThan(59);
});

it('prints the join, then the benches while stopped, then the start, in the v0.9.0 order', function (): void {
    // tmesis v0.9.0, handover 6.1 and DEPLOY.md 6.5: the benches need the service
    // stopped, and the node reads the stored capacity at its next start.
    Artisan::call('tmesis:fleet:join-token', ['name' => 'site-9']);
    $output = Artisan::output();

    $order = array_map(fn (string $needle): int|false => strpos($output, $needle), [
        'systemctl stop tmesis-update.path tmesis',
        'tmesis join '.route('tmesis.fleet.enroll'),
        // DEPLOY.md 6.5: an upgraded box's bench dir may be root-owned from a v0.8.x job bench.
        'chown -R tmesis:tmesis /var/lib/tmesis/bench',
        'tmesis bench --profile live --store --if-live',
        'DEPLOY.md 6.5 systemd-run line',
        'tmesis bench --profile job',
        'systemctl start tmesis tmesis-update.path',
    ]);

    expect($order)->not->toContain(false);

    $sorted = $order;
    sort($sorted);

    expect($order)->toBe($sorted);
});
