<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use AlexHackney\Tmesis\Events\PublishHookDecided;
use AlexHackney\Tmesis\Events\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Fleet\FileDesiredStateProvider;
use Workbench\App\Keyterms\EnvKeytermsProvider;
use Workbench\App\Listeners\LogDelivery;
use Workbench\App\Listeners\LogHookDecision;

/**
 * The reference receiver: a real application with this package installed, the
 * inbound routes on (webhooks, the publish hook and, since tmesis v0.8.0, the keyterms
 * hook), and contract validation turned up.
 *
 * It exists so the M1 gate's item 4 can be PROVED rather than asserted: a node is
 * pointed at it, its webhooks get 204 and its publish hook gets an allow inside the
 * 500 ms cap, and the transcript goes in the PR. Everything else in this package can
 * be tested with Http::fake(); that row cannot.
 *
 * Configuration is entirely environmental, so nobody has to edit a file to run it:
 *
 * | Variable | Default | What it is |
 * |---|---|---|
 * | `TMESIS_RECEIVER_NODE` | `edge-venue-07` | the node name, which must match the node's own `node.name` and its X-Tmesis-Node header |
 * | `TMESIS_RECEIVER_WEBHOOK_SECRET` | `dev-webhook-secret` | the node's `webhooks.secret` |
 * | `TMESIS_RECEIVER_HOOK_SECRET` | falls back to the webhook secret | the node's `ingest.verify_hook.secret` |
 * | `TMESIS_RECEIVER_KEYTERMS_SECRET` | falls back to the webhook secret | the node's `transcription.keyterms_hook.secret` (tmesis v0.8.0) |
 * | `TMESIS_RECEIVER_KEYTERMS` | none | the comma-separated list EnvKeytermsProvider answers the keyterms hook with, for every stream |
 * | `TMESIS_RECEIVER_TOKEN` | `dev-admin-token` | the node's `api.admin_token`, for outbound calls |
 * | `TMESIS_RECEIVER_URL` | `http://127.0.0.1:7000` | the node's admin API |
 * | `TMESIS_RECEIVER_FLEET` | off | `1` turns the fleet control plane on (tmesis v0.7.0): the three fleet routes, the DATABASE dedupe store (the fleet refuses a non-durable one) on the app's default connection, the package migrations, and FileDesiredStateProvider |
 * | `TMESIS_RECEIVER_FLEET_DESIRED` | none | the JSON file FileDesiredStateProvider reads, re-read on every heartbeat |
 *
 * The defaults are the ones in the tmesis repo's `dev/docker-compose.yml` and
 * `tmesis.example.yml`, so `make -f build/Makefile dev-up` on one side and
 * `vendor/bin/testbench serve` on the other talk to each other with no arguments.
 */
class ReceiverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registered AFTER the package provider (testbench.yaml lists it first), so
        // these win over the shipped defaults and are in place before boot() reads
        // them to decide whether to load a route.
        $node = self::env('TMESIS_RECEIVER_NODE', 'edge-venue-07');
        $webhookSecret = self::env('TMESIS_RECEIVER_WEBHOOK_SECRET', 'dev-webhook-secret');

        config()->set('tmesis.default', $node);
        config()->set('tmesis.nodes', [
            $node => [
                'url' => self::env('TMESIS_RECEIVER_URL', 'http://127.0.0.1:7000'),
                'token' => self::env('TMESIS_RECEIVER_TOKEN', 'dev-admin-token'),
                'webhook_secret' => $webhookSecret,
                // Blank is the usual case and falls back to the webhook secret.
                'hook_secret' => self::env('TMESIS_RECEIVER_HOOK_SECRET', ''),
                'keyterms_hook_secret' => self::env('TMESIS_RECEIVER_KEYTERMS_SECRET', ''),
            ],
        ]);

        config()->set('tmesis.keys.secret', self::env('TMESIS_RECEIVER_KEY_SECRET', 'dev-key-secret'));

        config()->set('tmesis.webhooks.enabled', true);
        config()->set('tmesis.hooks.enabled', true);
        config()->set('tmesis.hooks.keyterms.enabled', true);
        config()->set('tmesis.hooks.keyterms.provider', EnvKeytermsProvider::class);

        // File-backed, so a delivery claimed before a restart is still claimed after
        // one: the node retries, and a forgotten claim is a listener run twice.
        config()->set('tmesis.webhooks.dedupe.driver', 'cache');
        config()->set('tmesis.webhooks.dedupe.cache_store', 'file');
        config()->set('tmesis.webhooks.dedupe.retention_hours', 48);

        // ON here, and off by default everywhere else. A body that stopped matching
        // the pinned schema is the news this receiver exists to produce.
        config()->set('tmesis.contract.validate_incoming', true);

        // The fleet control plane, opt in (the `fleet-node` acceptance run, plan
        // v0.7.0 section 4). The fleet acks an event only after recording its id
        // durably, so the dedupe store becomes the database one on the default
        // connection (DB_CONNECTION / DB_DATABASE from the environment).
        if (self::env('TMESIS_RECEIVER_FLEET', '') === '1') {
            config()->set('tmesis.webhooks.dedupe.driver', 'database');
            config()->set('tmesis.fleet.enabled', true);
            config()->set('tmesis.fleet.provider', FileDesiredStateProvider::class);
        }
    }

    public function boot(): void
    {
        if (config('tmesis.fleet.enabled')) {
            $this->loadMigrationsFrom(dirname(__DIR__, 3).'/database/migrations');
        }

        Event::listen(WebhookEvent::class, LogDelivery::class);
        Event::listen(PublishHookDecided::class, LogHookDecision::class);

        $this->announce();
    }

    /**
     * Print the two resolved absolute URLs at startup.
     *
     * Resolved rather than described, so whoever configures the node COPIES them
     * instead of composing them out of a prefix and a path and getting one wrong.
     */
    private function announce(): void
    {
        if (! $this->app->runningInConsole() || ! in_array('serve', (array) ($_SERVER['argv'] ?? []), true)) {
            return;
        }

        $prefix = trim((string) config('tmesis.webhooks.prefix', 'webhooks'), '/');
        $base = rtrim((string) config('app.url', 'http://127.0.0.1:8093'), '/');
        $node = (string) config('tmesis.default');

        fwrite(STDOUT, PHP_EOL.implode(PHP_EOL, [
            'Tmesis reference receiver',
            '  node name            '.$node,
            '  webhooks.url         '.$base.'/'.$prefix.'/tmesis',
            '  ingest.verify_hook   '.$base.'/'.$prefix.'/hooks/publish',
            '  keyterms_hook.url    '.$base.'/'.$prefix.'/hooks/keyterms',
            '  contract validation  on (bodies are checked against the bundled schemas)',
            config('tmesis.fleet.enabled')
                ? '  fleet                '.$base.'/'.$prefix.'/fleet/enroll (and /fleet/heartbeat)'
                : '  delivery store       cache/file, 48h retention',
            '',
            '  APP_URL is what these are built from. Set it to the address the NODE can',
            '  reach when the node is not on this host.',
            '',
        ]).PHP_EOL);
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }
}
