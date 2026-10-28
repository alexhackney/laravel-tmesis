<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Support;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * Required-at-use config reads, in one place.
 *
 * Every value this package cannot work without goes through here, so the Doppler
 * empty-string case ("an absent key renders as \"\", not null") is handled once
 * rather than at each call site. `blank()` is the test throughout.
 *
 * Nothing here trims. A secret is used as its raw bytes exactly as configured, and a
 * trailing newline is a different secret; `tmesis:doctor` is where that is reported.
 */
final class Config
{
    /**
     * Guard a value that has already been read, naming the key it came from.
     *
     * Used by NodeRegistry so a NodeConfig built by connect() gets the same guard as
     * a configured one.
     */
    public static function requireValue(string $key, mixed $value): string
    {
        if (blank($value) || ! is_scalar($value)) {
            throw InvalidConfigurationException::missing($key);
        }

        return (string) $value;
    }

    /**
     * Read a config key that must have a value.
     */
    public static function required(string $key): string
    {
        return self::requireValue($key, config($key));
    }

    /**
     * How long a delivery id stays known, in hours.
     *
     * The node retries a delivery for 24 hours, so a window under 48 would let a late
     * retry through as new. Refused rather than clamped: silently widening a window
     * an operator set on purpose hides the mistake.
     */
    public static function retentionHours(): int
    {
        $hours = config('tmesis.webhooks.dedupe.retention_hours');

        if (! is_numeric($hours) || (int) $hours < 48) {
            throw InvalidConfigurationException::invalid(
                'tmesis.webhooks.dedupe.retention_hours',
                'it must be at least 48, because the node retries a delivery for 24 hours'
            );
        }

        return (int) $hours;
    }

    /**
     * The dedupe driver: `database` (durable, the default), `table` or `cache`.
     */
    public static function dedupeDriver(): string
    {
        $driver = config('tmesis.webhooks.dedupe.driver');

        if (! in_array($driver, ['database', 'table', 'cache'], true)) {
            throw InvalidConfigurationException::invalid(
                'tmesis.webhooks.dedupe.driver',
                'it must be "database", "table" or "cache"'
            );
        }

        return $driver;
    }
}
