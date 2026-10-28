<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\Config;
use Illuminate\Contracts\Container\Container;

/**
 * The fleet's configuration, read through one place that refuses rather than clamps.
 *
 * Every floor here is a number the contract fixes, not a taste: the node's join
 * recovery message promises a 15-minute repeat window (tmesis D12), its heartbeat
 * builder may send up to 4 MiB (plan section 5), and it keeps an unacked event for 7
 * days, which is why a delivery id must stay known for 14 (D13). A value under a floor
 * is an operator's mistake, and widening it silently would hide the mistake.
 */
final class FleetConfig
{
    /** tmesis D12: the node tells the operator to rerun join within 15 minutes. */
    public const MIN_RETRY_WINDOW_MINUTES = 15;

    /** tmesis plan section 5: request bodies up to 4 MiB. */
    public const MIN_BODY_BYTES = 4194304;

    /** tmesis D13: the node's 7-day horizon, doubled. */
    public const MIN_TOMBSTONE_DAYS = 14;

    public static function enabled(): bool
    {
        return (bool) config('tmesis.fleet.enabled', false);
    }

    public static function staleAfterSeconds(): int
    {
        return self::atLeast('tmesis.fleet.stale_after_seconds', 1, 'a whole number of seconds, at least 1');
    }

    public static function joinTokenTtlMinutes(): int
    {
        return self::atLeast('tmesis.fleet.join_token_ttl_minutes', 1, 'a whole number of minutes, at least 1');
    }

    public static function retryWindowMinutes(): int
    {
        return self::atLeast(
            'tmesis.fleet.retry_window_minutes',
            self::MIN_RETRY_WINDOW_MINUTES,
            'at least 15, because `tmesis join` tells the operator a rerun within 15 minutes recovers'
        );
    }

    public static function commandTtlSeconds(): int
    {
        return self::atLeast('tmesis.fleet.command_ttl_seconds', 1, 'a whole number of seconds, at least 1');
    }

    public static function maxBodyBytes(): int
    {
        return self::atLeast(
            'tmesis.fleet.max_body_bytes',
            self::MIN_BODY_BYTES,
            'at least 4194304 (4 MiB), the heartbeat body size the contract allows'
        );
    }

    public static function tombstoneDays(): int
    {
        return self::atLeast(
            'tmesis.fleet.tombstone_days',
            self::MIN_TOMBSTONE_DAYS,
            'at least 14, because the node re-sends an unacked event for up to 7 days'
        );
    }

    /**
     * How long a delivery id stays known, in hours, for every dedupe driver.
     *
     * With the fleet off this is `webhooks.dedupe.retention_hours` exactly as before.
     * With it on, it is at least `tombstone_days`: one event can arrive on the webhook
     * today and over the heartbeat days later (tmesis D7, D13), so an id recorded by
     * either channel must outlive the other's retries.
     */
    public static function dedupeRetentionHours(): int
    {
        $hours = Config::retentionHours();

        return self::enabled() ? max($hours, self::tombstoneDays() * 24) : $hours;
    }

    /**
     * Refuse to enable the fleet on a configuration that cannot keep its promises.
     *
     * Called from the provider's boot when `fleet.enabled`, after every provider's
     * register(), so a DesiredStateProvider bound there counts. Every numeric floor is
     * read here too, so a bad value fails the deploy rather than the first heartbeat.
     *
     * @throws InvalidConfigurationException
     */
    public static function assertUsable(Container $container): void
    {
        if (Config::dedupeDriver() === 'cache') {
            throw InvalidConfigurationException::invalid(
                'tmesis.webhooks.dedupe.driver',
                'the fleet acknowledges an event only after recording its id durably, and the "cache" driver is not durable: use "database" or "table"'
            );
        }

        if (Config::dedupeDriver() === 'table') {
            $store = config('tmesis.webhooks.dedupe.cache_store');
            $name = blank($store) ? (string) config('cache.default') : (string) $store;
            $driver = config("cache.stores.{$name}.driver");

            if (in_array($driver, ['array', 'null', 'file'], true)) {
                throw InvalidConfigurationException::invalid(
                    'tmesis.webhooks.dedupe.cache_store',
                    "the fleet needs a durable cache store shared across workers for the \"table\" driver, and [{$name}] uses the non-durable \"{$driver}\" driver: use redis, database, memcached or dynamodb"
                );
            }

            // The fleet sends one event over two channels (the heartbeat and the
            // webhook), so a second sight is normal traffic there, and the cache alone
            // forgets it on a cache:clear or an eviction.
            if (blank(config('tmesis.webhooks.dedupe.table.columns.delivery'))) {
                throw InvalidConfigurationException::invalid(
                    'tmesis.webhooks.dedupe.table.columns.delivery',
                    'the fleet delivers every event over two channels, and without a uniquely indexed delivery id column named here the "table" driver dedupes on the cache alone: name one, or use the "database" driver'
                );
            }
        }

        if (! self::providerConfigured($container)) {
            throw InvalidConfigurationException::invalid(
                'tmesis.fleet.provider',
                'the fleet needs a class implementing '.DesiredStateProvider::class.' (set tmesis.fleet.provider, or bind the interface)'
            );
        }

        try {
            self::provider($container);
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw InvalidConfigurationException::invalid('tmesis.fleet.provider', 'the desired-state provider could not be resolved');
        }

        self::staleAfterSeconds();
        self::joinTokenTtlMinutes();
        self::retryWindowMinutes();
        self::commandTtlSeconds();
        self::maxBodyBytes();
        self::tombstoneDays();
        self::dedupeRetentionHours();
    }

    /**
     * Whether a desired-state provider is bound in the container or named in config.
     */
    public static function providerConfigured(Container $container): bool
    {
        return $container->bound(DesiredStateProvider::class) || ! blank(config('tmesis.fleet.provider'));
    }

    /**
     * The desired-state provider: bound in the container, else the class
     * `tmesis.fleet.provider` names. Read at use, never at boot, so a test can bind
     * one after the provider has run (the EnrollmentSeams precedent).
     *
     * @throws InvalidConfigurationException naming `tmesis.fleet.provider`
     */
    public static function provider(Container $container): DesiredStateProvider
    {
        if ($container->bound(DesiredStateProvider::class)) {
            $provider = $container->make(DesiredStateProvider::class);
        } else {
            $class = config('tmesis.fleet.provider');

            if (! is_string($class) || $class === '') {
                throw InvalidConfigurationException::missing('tmesis.fleet.provider');
            }

            if (! class_exists($class)) {
                throw InvalidConfigurationException::invalid('tmesis.fleet.provider', "the class [{$class}] does not exist");
            }

            $provider = $container->make($class);
        }

        if (! $provider instanceof DesiredStateProvider) {
            throw InvalidConfigurationException::invalid('tmesis.fleet.provider', 'it must implement '.DesiredStateProvider::class);
        }

        return $provider;
    }

    private static function atLeast(string $key, int $floor, string $why): int
    {
        $value = config($key);

        if (! is_numeric($value) || (int) $value != $value || (int) $value < $floor) {
            throw InvalidConfigurationException::invalid($key, 'it must be '.$why);
        }

        return (int) $value;
    }
}
