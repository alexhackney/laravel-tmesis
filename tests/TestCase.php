<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests;

use AlexHackney\Tmesis\Tests\Doubles\ArrayDesiredStateProvider;
use AlexHackney\Tmesis\TmesisServiceProvider;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The node name in test/vectors/webhooks.json, so the vector suite and the
     * feature tests share one configured node.
     */
    public const NODE = 'edge-venue-07';

    /** The vector secret. The vector readme says none of them are real. */
    public const WEBHOOK_SECRET = 'whsec_tmesis_docs_example';

    /** The docs/API.md 8.1 worked example secret. */
    public const KEY_SECRET = 'sk_tmesis_docs_example';

    /**
     * A hook secret that is NOT the webhook secret.
     *
     * The node's ingest.verify_hook.secret may be set separately, and a suite where
     * the two are equal cannot tell "the hook route verifies with the hook secret"
     * from "the hook route verifies with whatever secret is around".
     */
    public const HOOK_SECRET = 'vhsec_tmesis_docs_example';

    /**
     * A keyterms hook secret that is neither of the two above (tmesis v0.8.0), for
     * the same reason HOOK_SECRET differs from the webhook secret.
     */
    public const KEYTERMS_SECRET = 'ktsec_tmesis_docs_example';

    /**
     * Read by defineEnvironment(), so a test can turn the receiver off and reload
     * the application to prove the route is not registered.
     */
    protected bool $webhooksEnabled = true;

    /**
     * The publish veto, off by default the way the config ships it.
     */
    protected bool $hooksEnabled = false;

    /**
     * The keyterms hook route, off by default the way the config ships it.
     */
    protected bool $keytermsHookEnabled = false;

    /**
     * The transfer signer class the config names, none by default the way it ships.
     */
    protected ?string $transferSigner = null;

    /**
     * The fleet control plane, off by default the way the config ships it. withFleet()
     * turns it on with the durable driver and the test provider.
     */
    protected bool $fleetEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TmesisServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // A fixed key, so the encrypted-column tests never depend on testbench's
        // skeleton .env, which a fresh CI install does not have.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('tmesis.default', self::NODE);
        $app['config']->set('tmesis.nodes', [
            self::NODE => [
                'url' => 'http://127.0.0.1:7000',
                'token' => 'test-token',
                'webhook_secret' => self::WEBHOOK_SECRET,
                'hook_secret' => self::HOOK_SECRET,
                'keyterms_hook_secret' => self::KEYTERMS_SECRET,
            ],
        ]);
        $app['config']->set('tmesis.keys.secret', self::KEY_SECRET);
        $app['config']->set('tmesis.keys.previous_secrets', []);
        $app['config']->set('tmesis.webhooks.enabled', $this->webhooksEnabled);
        $app['config']->set('tmesis.hooks.enabled', $this->hooksEnabled);
        $app['config']->set('tmesis.hooks.keyterms.enabled', $this->keytermsHookEnabled);
        $app['config']->set('tmesis.transfers.signer', $this->transferSigner);

        // The cache driver on the array store is the documented opt-out: the vectors
        // suite and the fake need no migration to run.
        $app['config']->set('tmesis.webhooks.dedupe.driver', 'cache');
        $app['config']->set('tmesis.webhooks.dedupe.cache_store', 'array');
        $app['config']->set('tmesis.webhooks.dedupe.retention_hours', 48);

        if ($this->fleetEnabled) {
            // The fleet refuses a non-durable dedupe store at boot, so the durable
            // driver comes with it; withFleet() runs the migrations.
            $app['config']->set('tmesis.webhooks.dedupe.driver', 'database');
            $app['config']->set('tmesis.fleet.enabled', true);
            $app['config']->set('tmesis.fleet.provider', ArrayDesiredStateProvider::class);
        }
    }

    /**
     * Rebuild the application with the fleet on: the three routes, the database
     * dedupe driver, the package migrations (delivery and fleet tables), and the
     * array-backed desired-state provider, reset.
     */
    protected function withFleet(): void
    {
        $this->fleetEnabled = true;

        $this->reloadApplication();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        ArrayDesiredStateProvider::reset();
    }

    /**
     * Rebuild the application with the receiver route turned off.
     */
    protected function withWebhooksDisabled(): void
    {
        $this->webhooksEnabled = false;

        $this->reloadApplication();
    }

    /**
     * Rebuild the application with the publish veto turned on.
     */
    protected function withHooksEnabled(): void
    {
        $this->hooksEnabled = true;

        $this->reloadApplication();
    }

    /**
     * Rebuild the application with the keyterms hook route turned on.
     */
    protected function withKeytermsHookEnabled(): void
    {
        $this->keytermsHookEnabled = true;

        $this->reloadApplication();
    }

    /**
     * Rebuild the application with a transfer signer configured, so the provider
     * registers the refresh listener at boot.
     */
    protected function withTransferSigner(string $class): void
    {
        $this->transferSigner = $class;

        $this->reloadApplication();
    }

    /**
     * Switch to the durable driver and load the package migration.
     *
     * Testbench's default connection is in-memory sqlite.
     */
    protected function usesDatabaseDeduper(): void
    {
        config()->set('tmesis.webhooks.dedupe.driver', 'database');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->forgetInstance(DeliveryDeduper::class);
    }

    /**
     * Switch to the table driver over a consumer-shaped `webhooks` table, deduping on
     * the array cache store (atomic within the one test process).
     */
    protected function usesTableDeduper(): void
    {
        config()->set('tmesis.webhooks.dedupe.driver', 'table');
        config()->set('tmesis.webhooks.dedupe.cache_store', 'array');

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->app->forgetInstance(DeliveryDeduper::class);
    }

    protected function fixture(string $relative): string
    {
        $path = __DIR__.'/Fixtures/'.$relative;

        if (! is_file($path)) {
            throw new \RuntimeException("Missing fixture [{$path}].");
        }

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fixtureJson(string $relative): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->fixture($relative), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A generated example (`docs/contract/examples/{name}.json`, the bundled copy) as
     * THIS test node would deliver it: `node` is set to self::NODE, in memory only.
     *
     * The examples name `origin-atl-01`, and the harness signs as self::NODE, so a
     * delivery built from the raw example would carry one node in `X-Tmesis-Node` and
     * another in the body, which the node never sends. The receiver keys the secret
     * on the header alone and never compares the two; the test that pins that reads
     * the raw example on purpose.
     *
     * @return array<string, mixed>
     */
    protected function exampleAsTestNode(string $name): array
    {
        $payload = $this->fixtureJson("contract/docs/contract/examples/{$name}.json");
        $payload['node'] = self::NODE;

        return $payload;
    }

    /**
     * POST raw bytes to the receiver, signed the way the node signs.
     *
     * Never postJson(): that re-encodes the body, and the signature covers the exact
     * bytes received.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $overrides  server headers, HTTP_ prefixed
     */
    protected function signedPost(string $event, array $payload, array $overrides = [], ?string $secret = null): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->signedPostRaw($event, $body, $overrides, $secret);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    protected function signedPostRaw(string $event, string $body, array $overrides = [], ?string $secret = null): TestResponse
    {
        $timestamp = $overrides['HTTP_X_TMESIS_TIMESTAMP'] ?? (string) now()->getTimestamp();
        $node = $overrides['HTTP_X_TMESIS_NODE'] ?? self::NODE;

        $decoded = json_decode($body, true);
        $delivery = is_array($decoded) && is_string($decoded['delivery'] ?? null)
            ? $decoded['delivery']
            : '01J8ZC9GQ2M4YE7XW1RN0P6VTB';

        $server = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'tmesis/test',
            'HTTP_X_TMESIS_EVENT' => $event,
            'HTTP_X_TMESIS_DELIVERY' => $delivery,
            'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
            'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign($secret ?? self::WEBHOOK_SECRET, $timestamp, $body),
            'HTTP_X_TMESIS_NODE' => $node,
            'HTTP_X_TMESIS_ATTEMPT' => '1',
        ], $overrides);

        return $this->call('POST', route('tmesis.webhooks.receive'), [], [], [], $server, $body);
    }

    /**
     * POST raw bytes to the publish veto, signed with the HOOK secret.
     *
     * The same signer as the receiver, a different secret and a different route. Raw
     * bytes again: the signature covers what arrives.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $overrides  server headers, HTTP_ prefixed
     */
    protected function signedHookPost(array $payload, array $overrides = [], ?string $secret = null): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->signedHookPostRaw($body, $overrides, $secret);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    protected function signedHookPostRaw(string $body, array $overrides = [], ?string $secret = null): TestResponse
    {
        $timestamp = $overrides['HTTP_X_TMESIS_TIMESTAMP'] ?? (string) now()->getTimestamp();

        $decoded = json_decode($body, true);
        $delivery = is_array($decoded) && is_string($decoded['delivery'] ?? null)
            ? $decoded['delivery']
            : '01J8ZCC1H6V9DQ2NR5TXB4KM7W';

        $server = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'tmesis/test',
            'HTTP_X_TMESIS_EVENT' => 'publish.verify',
            'HTTP_X_TMESIS_DELIVERY' => $delivery,
            'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
            'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign($secret ?? self::HOOK_SECRET, $timestamp, $body),
            'HTTP_X_TMESIS_NODE' => self::NODE,
            'HTTP_X_TMESIS_ATTEMPT' => '1',
        ], $overrides);

        return $this->call('POST', route('tmesis.hooks.publish'), [], [], [], $server, $body);
    }
}
