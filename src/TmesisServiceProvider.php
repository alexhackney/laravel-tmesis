<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\ResolvedNodes;
use AlexHackney\Tmesis\Client\TmesisClient;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Commands\DoctorCommand;
use AlexHackney\Tmesis\Commands\EnrollNodeCommand;
use AlexHackney\Tmesis\Commands\FleetCheckStaleCommand;
use AlexHackney\Tmesis\Commands\FleetJoinTokenCommand;
use AlexHackney\Tmesis\Commands\FleetPruneCommand;
use AlexHackney\Tmesis\Commands\HealthCommand;
use AlexHackney\Tmesis\Commands\MintKeyCommand;
use AlexHackney\Tmesis\Commands\RetryWebhooksCommand;
use AlexHackney\Tmesis\Commands\StreamsCommand;
use AlexHackney\Tmesis\Commands\WebhooksPruneCommand;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Fleet\DatabaseFleet;
use AlexHackney\Tmesis\Fleet\FleetConfig;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Hooks\AllowAllDecider;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;
use AlexHackney\Tmesis\Keys\StreamKeyMinter;
use AlexHackney\Tmesis\Support\Config;
use AlexHackney\Tmesis\Transfers\RefreshTransferAuthorization;
use AlexHackney\Tmesis\Webhooks\CacheDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DatabaseDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\TableDeliveryDeduper;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

class TmesisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tmesis.php', 'tmesis');

        // The registry is a singleton that holds the config REPOSITORY, not a
        // snapshot of tmesis.*: config()->set() on a node after this has resolved
        // must be visible to the next call. It holds the container too, for the node
        // resolver (read at use, like the hook decider) and for the resolver's memo,
        // which is SCOPED so it dies with the request or the queued job.
        $this->app->singleton(NodeRegistry::class, fn ($app) => new NodeRegistry($app->make(Repository::class), $app));
        $this->app->scoped(ResolvedNodes::class);

        $this->app->singleton(TmesisClientInterface::class, fn ($app) => new TmesisClient($app->make(NodeRegistry::class)));
        $this->app->alias(TmesisClientInterface::class, 'tmesis');
        $this->app->alias(TmesisClientInterface::class, TmesisClient::class);

        $this->app->singleton(StreamKeyMinter::class, fn ($app) => new StreamKeyMinter($app->make(NodeRegistry::class)));

        // Bound, not resolved: the controller reads tmesis.hooks.decider at decision
        // time so a consumer can swap the class in a test without rebuilding the
        // container, and a class name that cannot be resolved lands on the on_error
        // posture rather than on a 500.
        $this->app->bind(PublishHookDecider::class, function ($app): PublishHookDecider {
            $configured = config('tmesis.hooks.decider', AllowAllDecider::class);

            /** @var PublishHookDecider */
            return $app->make(is_string($configured) ? $configured : AllowAllDecider::class);
        });

        $this->app->singleton(DeliveryDeduper::class, function ($app): DeliveryDeduper {
            // With the fleet on, an id stays known for tombstone_days whichever
            // channel claimed it (tmesis D7, D13; FleetConfig::dedupeRetentionHours).
            $retention = FleetConfig::dedupeRetentionHours();
            $store = config('tmesis.webhooks.dedupe.cache_store');
            $store = is_string($store) && $store !== '' ? $store : null;

            return match (Config::dedupeDriver()) {
                'cache' => new CacheDeliveryDeduper($app->make(CacheFactory::class), $retention, $store),
                'table' => $this->tableDeduper($app, $retention, $store),
                default => new DatabaseDeliveryDeduper($app->make(DatabaseManager::class)->connection(), $retention),
            };
        });

        // The fleet's tables live on the default connection, beside the delivery
        // table: the heartbeat records a node's report and claims its events in one
        // request, and one connection keeps that simple.
        $this->app->singleton(FleetStore::class, fn ($app) => new FleetStore($app->make(DatabaseManager::class)->connection()));
        $this->app->singleton(FleetControlPlane::class, DatabaseFleet::class);
    }

    /**
     * The `table` driver, from `webhooks.dedupe.table` and the cache store's driver.
     */
    private function tableDeduper(Application $app, int $retention, ?string $store): TableDeliveryDeduper
    {
        $connection = config('tmesis.webhooks.dedupe.table.connection');
        $name = config('tmesis.webhooks.dedupe.table.name');
        $source = config('tmesis.webhooks.dedupe.table.source');
        $columns = config('tmesis.webhooks.dedupe.table.columns');
        $driver = config('cache.stores.'.($store ?? config('cache.default')).'.driver');

        return new TableDeliveryDeduper(
            $app->make(DatabaseManager::class)->connection(is_string($connection) && $connection !== '' ? $connection : null),
            $app->make(CacheFactory::class),
            $retention,
            $store,
            is_string($name) ? $name : '',
            is_string($source) ? $source : '',
            is_array($columns) ? $columns : [],
            is_string($driver) ? $driver : null,
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/tmesis.php' => config_path('tmesis.php'),
        ], 'tmesis-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'tmesis-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                HealthCommand::class,
                StreamsCommand::class,
                DoctorCommand::class,
                MintKeyCommand::class,
                RetryWebhooksCommand::class,
                WebhooksPruneCommand::class,
                EnrollNodeCommand::class,
                FleetJoinTokenCommand::class,
                FleetCheckStaleCommand::class,
                FleetPruneCommand::class,
            ]);
        }

        $this->loadWebhookRoutes();
        $this->loadHookRoutes();
        $this->loadKeytermsHookRoutes();
        $this->loadEnrollmentRoutes();
        $this->loadFleetRoutes();
        $this->listenForTransferAuthorization();
    }

    /**
     * The fleet control plane's three routes, on their own switch and off by default.
     *
     * Enabling it on a configuration that cannot keep the contract's promises (a
     * non-durable dedupe store, no desired-state provider, a floor under the
     * contract's number) is refused HERE, at boot, so it fails the deploy rather than
     * the first heartbeat at 2 a.m.
     */
    private function loadFleetRoutes(): void
    {
        if (! FleetConfig::enabled()) {
            return;
        }

        FleetConfig::assertUsable($this->app);

        $this->loadRoutesFrom(__DIR__.'/../routes/fleet.php');
    }

    /**
     * The signing seam, only when there is a signer to answer with.
     *
     * Checked at boot, after every provider's register(): a signer bound there counts
     * as configured. With none, nothing listens and a signed object waits, which is
     * the node's own behaviour with no control plane answering.
     */
    private function listenForTransferAuthorization(): void
    {
        if (! RefreshTransferAuthorization::signerConfigured($this->app)) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(
            TransferAuthorizationRequired::class,
            RefreshTransferAuthorization::class,
        );
    }

    /**
     * The receiver route exists only when the consumer turns it on. Installing the
     * package must never open a route.
     */
    private function loadWebhookRoutes(): void
    {
        if (! config('tmesis.webhooks.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
    }

    /**
     * The publish veto, on its own switch.
     *
     * Separate from the receiver's because they are separate decisions: a control
     * plane can want the node's notifications long before it wants to stand in the
     * publish path, and the hook is the one endpoint whose latency a show can feel.
     */
    private function loadHookRoutes(): void
    {
        if (! config('tmesis.hooks.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/hooks.php');
    }

    /**
     * The keyterms hook (tmesis v0.8.0), on its own switch: a control plane can
     * serve a show's vocabulary without standing in the publish path, or the
     * reverse.
     *
     * Registered ALWAYS, unlike the other routes: the node reads a 404 as "no
     * keyterms" and clears every session's list, so a node pointed at a switched-off
     * route must get the controller's signed-then-503 `keyterms_disabled`, which
     * keeps its list. The switch is read per request in the controller.
     */
    private function loadKeytermsHookRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/keyterms.php');
    }

    /**
     * Node enrolment, on its own switch and off by default. The routes authenticate
     * with a bootstrap token (install) and the token plus the enrolment's own
     * webhook signature (register), not with a configured node's secret, so they
     * open only when a consumer is actually enrolling nodes.
     */
    private function loadEnrollmentRoutes(): void
    {
        if (! config('tmesis.enrollment.enabled', false)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/enrollment.php');
    }
}
