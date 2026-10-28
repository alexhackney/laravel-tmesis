<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\Config;

it('requires a value and names the key', function (): void {
    expect(fn () => Config::requireValue('tmesis.thing', ''))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.thing')
        ->and(fn () => Config::requireValue('tmesis.thing', null))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.thing')
        ->and(Config::requireValue('tmesis.thing', 'value'))->toBe('value');
});

it('reads a required config key', function (): void {
    config()->set('tmesis.probe', 'set');

    expect(Config::required('tmesis.probe'))->toBe('set');

    config()->set('tmesis.probe', '');

    expect(fn () => Config::required('tmesis.probe'))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.probe');
});

it('refuses a retention window under 48 hours', function (): void {
    // The node retries a delivery for 24 hours: a shorter window would let a late
    // retry through as new.
    config()->set('tmesis.webhooks.dedupe.retention_hours', 47);

    expect(fn () => Config::retentionHours())
        ->toThrow(InvalidConfigurationException::class, 'retention_hours');

    config()->set('tmesis.webhooks.dedupe.retention_hours', 48);

    expect(Config::retentionHours())->toBe(48);
});

it('accepts only the three dedupe drivers', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'redis');

    expect(fn () => Config::dedupeDriver())
        ->toThrow(InvalidConfigurationException::class, 'driver');

    config()->set('tmesis.webhooks.dedupe.driver', 'database');
    expect(Config::dedupeDriver())->toBe('database');

    config()->set('tmesis.webhooks.dedupe.driver', 'cache');
    expect(Config::dedupeDriver())->toBe('cache');

    config()->set('tmesis.webhooks.dedupe.driver', 'table');
    expect(Config::dedupeDriver())->toBe('table');
});
