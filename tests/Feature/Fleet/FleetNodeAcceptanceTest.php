<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;

pest()->group('fleet-node');

/*
 * The pre-cut acceptance (plan-v0.7.0-fleet.md section 4, tmesis D16): a REAL node
 * binary joins the reference receiver running this package, heartbeats, applies a
 * revision, converges a revocation digest the admin API moved, performs each command,
 * is refused leave while it owes something, is released, and leaves.
 *
 * Gated on TMESIS_BIN (a built `tmesis`: `make -f build/Makefile build` in the tmesis
 * checkout) and skipped cleanly without it. The publish steps need ffmpeg on PATH
 * (`tmesis test-publish`); without it the kick and seal are asserted acked as MOOT and
 * the early-leave refusal has nothing to refuse over, and the run says which it did.
 *
 * The receiver is `vendor/bin/testbench serve` with TMESIS_RECEIVER_FLEET=1 on a SQLite
 * FILE this test shares, so the in-process application reads node status and queues
 * commands through Tmesis::fleet() against the same store the served app writes. Real
 * processes have no event to wait on, so readiness is polled against a deadline
 * (waitFor), and every timeout prints what it was waiting for and the node's log tail.
 */

/**
 * @return array{bin: string, ffmpeg: ?string}
 */
function fleetNodeTools(): array
{
    $bin = getenv('TMESIS_BIN');

    if (! is_string($bin) || $bin === '' || ! is_executable($bin)) {
        test()->markTestSkipped('Set TMESIS_BIN to a built tmesis binary to run the fleet-node acceptance (plan section 4).');
    }

    $which = new Process(['which', 'ffmpeg']);
    $which->run();
    $ffmpeg = trim($which->getOutput());

    return ['bin' => $bin, 'ffmpeg' => $which->isSuccessful() && $ffmpeg !== '' ? $ffmpeg : null];
}

/** A loopback port nothing listens on (bound and released). */
function fleetFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** A loopback UDP port nothing listens on, for the SRT listener. */
function fleetFreeUdpPort(): int
{
    $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/**
 * Poll $probe until it returns something truthy, or fail after $seconds naming $what.
 *
 * @template T
 *
 * @param  callable(): T  $probe
 * @return T
 */
function waitFor(string $what, callable $probe, int $seconds, ?callable $context = null): mixed
{
    $deadline = microtime(true) + $seconds;

    do {
        $value = $probe();

        if ($value) {
            return $value;
        }

        usleep(250_000);
    } while (microtime(true) < $deadline);

    test()->fail("Timed out after {$seconds}s waiting for: {$what}".($context === null ? '' : "\n".$context()));
}

it('joins, heartbeats, applies, converges, obeys each command, and leaves (steps 1 to 10)', function (): void {
    $tools = fleetNodeTools();
    Http::allowStrayRequests();
    $this->withFleet();

    $dir = sys_get_temp_dir().'/tmesis-fleet-node-'.bin2hex(random_bytes(4));
    mkdir($dir.'/data', 0o755, true);
    $db = $dir.'/receiver.sqlite';
    touch($db);
    $desired = $dir.'/desired.json';
    file_put_contents($desired, json_encode(['streams' => [], 'revocations' => [], 'open_posture' => false]));

    $name = 'fleet-node-01';
    $keySecret = 'fleet-node-key-secret';
    $webhookSecret = 'fleet-node-webhook-secret';
    $adminToken = 'fleet-node-admin';
    [$receiverPort, $adminPort, $originPort, $rtmpPort, $srtPort] = [fleetFreePort(), fleetFreePort(), fleetFreePort(), fleetFreePort(), fleetFreeUdpPort()];
    $receiver = "http://127.0.0.1:{$receiverPort}";
    $admin = "http://127.0.0.1:{$adminPort}";

    // The in-process app shares the receiver's SQLite file on a connection of its OWN,
    // made the default for the run. The harness's `testing` connection (in memory,
    // where withFleet() migrated and where its teardown rollback runs) is left alone,
    // so teardown never touches the shared file, which is gone by then.
    config()->set('database.connections.fleetnode', ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('database.default', 'fleetnode');
    app()->forgetInstance(FleetStore::class);
    app()->forgetInstance(DeliveryDeduper::class);
    app()->forgetInstance(FleetControlPlane::class);
    $this->artisan('migrate', ['--database' => 'fleetnode', '--path' => realpath(__DIR__.'/../../../database/migrations'), '--realpath' => true, '--force' => true])->assertSuccessful();
    config()->set('tmesis.nodes', [$name => ['url' => $admin, 'token' => $adminToken, 'webhook_secret' => $webhookSecret]]);
    config()->set('tmesis.default', $name);
    config()->set('tmesis.keys.secret', $keySecret);
    config()->set('app.url', $receiver);
    URL::forceRootUrl($receiver);

    // The receiver's log accumulates across runs; this run reads only what it adds.
    $receiverLog = dirname(__DIR__, 3).'/vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log';
    $logOffset = is_file($receiverLog) ? (int) filesize($receiverLog) : 0;

    $processes = [];
    $node = null;
    // The node's own output, read from its buffers (Symfony drains a child's pipes
    // only when something asks), last 40 lines.
    $tail = function () use (&$node): string {
        if (! $node instanceof Process) {
            return '(the node is not running)';
        }

        return implode("\n", array_slice(explode("\n", $node->getOutput().$node->getErrorOutput()), -40));
    };

    try {
        // Step 1: the reference receiver, fleet on, on the shared store.
        $serve = new Process(['vendor/bin/testbench', 'serve', '--host=127.0.0.1', "--port={$receiverPort}"], dirname(__DIR__, 3), [
            'TMESIS_RECEIVER_FLEET' => '1',
            'TMESIS_RECEIVER_FLEET_DESIRED' => $desired,
            'TMESIS_RECEIVER_NODE' => $name,
            'TMESIS_RECEIVER_WEBHOOK_SECRET' => $webhookSecret,
            'TMESIS_RECEIVER_URL' => $admin,
            'TMESIS_RECEIVER_TOKEN' => $adminToken,
            'TMESIS_RECEIVER_KEY_SECRET' => $keySecret,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $db,
            'APP_URL' => $receiver,
        ]);
        $serve->setTimeout(null);
        $serve->start();
        $processes[] = $serve;
        waitFor('the receiver to listen', fn () => @fsockopen('127.0.0.1', $receiverPort) !== false, 30, fn () => $serve->getErrorOutput());

        // Step 2: the node's config, and `tmesis join` with the token on stdin.
        $config = $dir.'/config.yml';
        file_put_contents($config, implode("\n", [
            'node:',
            "  name: {$name}",
            '  role: standalone',
            "  data_dir: {$dir}/data",
            'ingest:',
            "  rtmp: { listen: \"127.0.0.1:{$rtmpPort}\" }",
            "  srt: { listen: \"127.0.0.1:{$srtPort}\" }",
            '  keys:',
            "    verify_secrets: [ { kid: k1, secret: \"{$keySecret}\" } ]",
            'encode:',
            '  ffmpeg: "'.($tools['ffmpeg'] ?? $dir.'/no-ffmpeg').'"',
            // With ffmpeg, one small rung: a publish then leaves an hls_sealed
            // generation, which step 7 is refused over and step 8 releases. Without
            // it, passthrough (nothing is packaged, so nothing is owed).
            ...($tools['ffmpeg'] === null ? [] : [
                '  default_ladder: tiny',
                '  ladders:',
                '    tiny:',
                '      segment_seconds: 2',
                '      rungs:',
                '        - { name: 240p, width: 426, height: 240, fps: "30", v_kbps: 300, preset: ultrafast }',
                '      audio: { codec: aac, rate: 48000, a_kbps: 64, normalize: false }',
            ]),
            'delivery:',
            '  mode: origin',
            "  origin: { listen_http: \"127.0.0.1:{$originPort}\" }",
            'recording:',
            '  ingest: { enabled: false }',
            'preview: { enabled: false }',
            'api:',
            "  listen: \"127.0.0.1:{$adminPort}\"",
            "  admin_token: \"{$adminToken}\"",
            'webhooks:',
            "  url: \"{$receiver}/webhooks/tmesis\"",
            "  secret: \"{$webhookSecret}\"",
            'control_plane:',
            '  heartbeat_seconds: 1',
            '',
        ]));
        $joinToken = Tmesis::fleet()->joinToken($name, 'standalone');
        $join = new Process([$tools['bin'], '--config', $config, 'join', route('tmesis.fleet.enroll'), '--token', '-']);
        $join->setInput($joinToken->reveal()."\n");
        $join->run();
        expect($join->isSuccessful())->toBeTrue('tmesis join: '.$join->getOutput().$join->getErrorOutput())
            ->and(is_file($dir.'/fleet.json'))->toBeTrue();
        $fleetToken = (string) json_decode((string) file_get_contents($dir.'/fleet.json'), true)['fleet_token'];

        // Step 3: serve; the first heartbeat, admission open, revision 1 applied.
        $node = new Process([$tools['bin'], '--config', $config, 'serve']);
        $node->setTimeout(null);
        $node->start();
        $processes[] = $node;
        $nodeId = DB::table(FleetStore::NODES)->where('name', $name)->value('id');
        waitFor('the first heartbeat, revision 1 applied, admission open', function () use ($nodeId) {
            $n = Tmesis::fleet()->node($nodeId);

            return $n?->lastHeartbeatAt !== null && $n->admission === 'open' && $n->appliedRevision === 1;
        }, 60, $tail);

        // Step 4: a new document (a stream with labels and a relay, a revocation) is
        // revision 2, applied, converged.
        file_put_contents($desired, json_encode([
            'streams' => [[
                'id' => 'fleet-show',
                'min_key_epoch' => 0,
                'labels' => ['show_id' => '2041'],
                'recording' => ['enabled' => false, 'retention_hours' => 72, 'destination' => null, 'destination_ref' => null],
                'relays' => [['id' => 'r1', 'url' => 'srt://127.0.0.1:9', 'enabled' => false]],
            ]],
            'revocations' => ['revoked-show'],
            'open_posture' => false,
        ]));
        waitFor('revision 2 applied and converged', fn () => Tmesis::fleet()->node($nodeId)?->converged() && Tmesis::fleet()->node($nodeId)->appliedRevision === 2, 30, $tail);

        // Step 5: the admin API (the second feeder) replaces the list; the heartbeat's
        // digest drifts, and the SAME revision is resent until it converges back.
        Http::withToken($adminToken)->post($admin.'/api/v1/keys/revocations', ['streams' => ['pushed-show']])->throw();
        waitFor('the revocation digest to converge back at revision 2', function () use ($nodeId) {
            $n = Tmesis::fleet()->node($nodeId);

            return $n?->converged() && $n->intendedRevision === 2;
        }, 30, $tail);

        // Step 6: kick and seal (on a live publish when ffmpeg is here), then drain.
        $published = false;

        if ($tools['ffmpeg'] !== null) {
            $key = (string) Tmesis::mint('fleet-show', KeyEpoch::fromInt(1));
            $publish = new Process([$tools['bin'], 'test-publish', '--url', "srt://127.0.0.1:{$srtPort}", '--key', $key, '--duration', '120s']);
            $publish->setTimeout(null);
            $publish->start();
            $processes[] = $publish;
            waitFor('fleet-show to go live', fn () => Tmesis::fleet()->node($nodeId)?->stream('fleet-show')?->attached(), 60, $tail);
            $published = true;
        }

        $kick = Tmesis::fleet()->kick($nodeId, 'fleet-show');
        waitFor('the kick acked', fn () => DB::table(FleetStore::COMMANDS)->where('id', $kick)->value('acked_at') !== null, 30, $tail);
        $seal = Tmesis::fleet()->seal($nodeId, 'fleet-show', 0);
        waitFor('the seal acked', fn () => DB::table(FleetStore::COMMANDS)->where('id', $seal)->value('acked_at') !== null, 30, $tail);
        $drain = Tmesis::fleet()->drain($nodeId);
        waitFor('the drain acked and admission draining', fn () => DB::table(FleetStore::COMMANDS)->where('id', $drain)->value('acked_at') !== null
            && Tmesis::fleet()->node($nodeId)?->isDraining(), 30, $tail);
        fwrite(STDERR, $published ? "fleet-node: kick and seal PERFORMED on a live publish\n" : "fleet-node: no ffmpeg, kick and seal acked as MOOT\n");

        $leave = fn (): Process => tap(new Process([$tools['bin'], '--config', $config, 'leave']), fn (Process $p) => $p->run());

        // Step 7: while the publish still owes its generation, leave is refused, with
        // the obligation listed, and fleet.json stays. Never --force here.
        if ($published) {
            $generations = fn () => collect(Tmesis::node($name)->artifacts()->allArtifacts('fleet-show'));
            waitFor('the sealed generation to finalize', fn () => $generations()->contains(fn ($a) => $a->isFinalized()), 60,
                fn () => 'generations: '.json_encode($generations()->map(fn ($a) => [$a->id(), $a->kind(), $a->state()])->all())."\n".$tail());
            $early = $leave();
            expect($early->isSuccessful())->toBeFalse('leave succeeded while the box owed a generation')
                ->and($early->getErrorOutput().$early->getOutput())->toContain('artifact')
                ->and(is_file($dir.'/fleet.json'))->toBeTrue();

            // Step 8: release every generation the run created, through the package.
            foreach (Tmesis::node($name)->artifacts()->allArtifacts('fleet-show') as $generation) {
                if (! $generation->isFinalized()) {
                    continue;
                }
                $plan = Tmesis::node($name)->artifacts()->getTransferPlan('fleet-show', $generation->id());
                Tmesis::node($name)->artifacts()->releaseArtifact('fleet-show', $generation->id(), ReleaseRequest::forPlan($plan, 'fleet-node acceptance'));
            }
        }

        // Step 9: leave, retried only while what is still owed is in flight (webhooks
        // and fleet events the node is still delivering).
        $final = waitFor('leave to succeed once the queues drain', function () use ($leave) {
            $p = $leave();

            if ($p->isSuccessful()) {
                return $p;
            }

            $out = $p->getErrorOutput().$p->getOutput();
            expect(preg_match('/^\s+(session|recording|artifact|transfer|job)\s/m', $out))->toBe(0, "leave refused over more than in-flight delivery:\n{$out}");

            return null;
        }, 60, $tail);

        expect($final->getOutput())->toContain('Left the fleet')
            ->and(is_file($dir.'/fleet.json'))->toBeFalse()
            ->and(Tmesis::fleet()->node($nodeId)?->enrolled())->toBeFalse();

        $replay = Http::withToken($fleetToken)->post(route('tmesis.fleet.heartbeat'), []);
        expect($replay->status())->toBe(401);

        // Step 10: every event dispatched once (the delivery store's unique key) and
        // no token in the receiver's log.
        $ids = DB::table('tmesis_webhook_deliveries')->pluck('delivery')->all();
        expect($ids)->not->toBeEmpty()
            ->and(count($ids))->toBe(count(array_unique($ids)));

        $log = is_file($receiverLog) ? (string) file_get_contents($receiverLog, false, null, $logOffset) : '';
        expect(str_contains($log, $fleetToken))->toBeFalse('the fleet token is in the receiver log')
            ->and(str_contains($log, $joinToken->reveal()))->toBeFalse('the join token is in the receiver log');
    } finally {
        // Cleanup never throws: the first real assertion failure is what the run
        // reports. The children are stopped FIRST (stop() waits for the exit, so the
        // receiver lets go of the SQLite file and its WAL), then the default goes back
        // to the harness's in-memory connection, then the directory goes.
        try {
            foreach (array_reverse($processes) as $process) {
                $process->stop(10, SIGTERM);
            }
            config()->set('database.default', 'testing');
            DB::purge('fleetnode');
            app()->forgetInstance(FleetStore::class);
            app()->forgetInstance(DeliveryDeduper::class);
            (new Filesystem)->deleteDirectory($dir);
        } catch (Throwable $cleanup) {
            fwrite(STDERR, 'fleet-node cleanup: '.$cleanup::class.': '.$cleanup->getMessage()."\n");
        }
    }
})->skip(fn () => ! is_string(getenv('TMESIS_BIN')) || getenv('TMESIS_BIN') === '', 'Set TMESIS_BIN to a built tmesis binary to run the fleet-node acceptance (plan section 4).');
