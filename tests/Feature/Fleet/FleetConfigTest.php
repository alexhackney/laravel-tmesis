<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\DesiredStateProvider;
use AlexHackney\Tmesis\Fleet\FleetConfig;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Tests\Doubles\ArrayDesiredStateProvider;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
 * F1: the fleet's configuration, and what turning it on refuses.
 *
 * Every floor is a number the contract fixes (tmesis D12's 15-minute rerun, the 4 MiB
 * body, D13's 14 days), so a value under it is refused, never clamped.
 */

it('ships off, and registers no fleet route while off', function (): void {
    expect(FleetConfig::enabled())->toBeFalse()
        ->and(Route::has('tmesis.fleet.enroll'))->toBeFalse()
        ->and(Route::has('tmesis.fleet.heartbeat'))->toBeFalse()
        ->and(Route::has('tmesis.fleet.leave'))->toBeFalse();
});

it('registers the three routes under the receiver prefix when on', function (): void {
    $this->withFleet();

    expect(Route::has('tmesis.fleet.enroll'))->toBeTrue()
        ->and(route('tmesis.fleet.enroll', absolute: false))->toBe('/webhooks/fleet/enroll')
        ->and(route('tmesis.fleet.heartbeat', absolute: false))->toBe('/webhooks/fleet/heartbeat')
        ->and(route('tmesis.fleet.leave', absolute: false))->toBe('/webhooks/fleet/enroll');
});

it('ships the contract defaults', function (): void {
    expect(FleetConfig::staleAfterSeconds())->toBe(60)
        ->and(FleetConfig::joinTokenTtlMinutes())->toBe(60)
        ->and(FleetConfig::retryWindowMinutes())->toBe(15)
        ->and(FleetConfig::commandTtlSeconds())->toBe(900)
        ->and(FleetConfig::maxBodyBytes())->toBe(4194304)
        ->and(FleetConfig::tombstoneDays())->toBe(14);
});

it('refuses a value under a contract floor, naming the key', function (string $key, mixed $value, Closure $read): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'retry window under 15' => ['tmesis.fleet.retry_window_minutes', 14, fn () => FleetConfig::retryWindowMinutes()],
    'body under 4 MiB' => ['tmesis.fleet.max_body_bytes', 4194303, fn () => FleetConfig::maxBodyBytes()],
    'tombstones under 14 days' => ['tmesis.fleet.tombstone_days', 13, fn () => FleetConfig::tombstoneDays()],
    'stale after 0' => ['tmesis.fleet.stale_after_seconds', 0, fn () => FleetConfig::staleAfterSeconds()],
    'a fractional TTL' => ['tmesis.fleet.command_ttl_seconds', 1.5, fn () => FleetConfig::commandTtlSeconds()],
    'a word' => ['tmesis.fleet.join_token_ttl_minutes', 'soon', fn () => FleetConfig::joinTokenTtlMinutes()],
]);

it('keeps every delivery id for the tombstone window while the fleet is on', function (): void {
    expect(FleetConfig::dedupeRetentionHours())->toBe(48);

    config()->set('tmesis.fleet.enabled', true);

    expect(FleetConfig::dedupeRetentionHours())->toBe(14 * 24);

    config()->set('tmesis.webhooks.dedupe.retention_hours', 24 * 30);

    expect(FleetConfig::dedupeRetentionHours())->toBe(24 * 30);
});

it('builds the database deduper with the fleet retention', function (): void {
    $this->withFleet();

    $deduper = app(DeliveryDeduper::class);

    expect($deduper->describe())->toContain('retention 336h');
});

it('refuses to enable the fleet on a non-durable dedupe store', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'cache');
    config()->set('tmesis.fleet.provider', ArrayDesiredStateProvider::class);

    expect(fn () => FleetConfig::assertUsable(app()))
        ->toThrow(InvalidConfigurationException::class, 'not durable');
});

it('refuses to enable the fleet without a desired-state provider', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'database');
    config()->set('tmesis.fleet.provider', null);

    expect(fn () => FleetConfig::assertUsable(app()))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.fleet.provider');
});

it('accepts a provider bound in the container instead of named in config', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'database');
    config()->set('tmesis.fleet.provider', null);
    app()->bind(DesiredStateProvider::class, ArrayDesiredStateProvider::class);

    FleetConfig::assertUsable(app());

    expect(FleetConfig::provider(app()))->toBeInstanceOf(ArrayDesiredStateProvider::class);
});

it('refuses a configured provider class that is not a provider', function (): void {
    config()->set('tmesis.fleet.provider', stdClass::class);

    expect(fn () => FleetConfig::provider(app()))
        ->toThrow(InvalidConfigurationException::class, 'must implement');
});

it('reports the fleet in tmesis:doctor: routes, a durable store kept 14 days, and stale nodes', function (): void {
    $this->withFleet();
    Tmesis::fleet();
    app(FleetStore::class)->createNode('01J8ZCP4M2V8QNX4RW7TKB3PD9', 'site-7', 'edge', '0.7.0', [], [], [], str_repeat('a', 64), now()->toImmutable()->subHour());
    app(FleetStore::class)->markStale(now()->toImmutable(), now()->toImmutable());

    Artisan::call('tmesis:doctor');
    $output = Artisan::output();

    expect($output)->toContain('POST '.route('tmesis.fleet.heartbeat'))
        ->and($output)->toContain('ids kept 336h whichever channel carried them')
        ->and($output)->toContain('1 enrolled, stale: site-7');
});

it('refuses the fleet on the table driver without a delivery column, naming the key', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'table');
    config()->set('tmesis.webhooks.dedupe.cache_store', 'database');
    config()->set('tmesis.webhooks.dedupe.table.columns.delivery', null);
    config()->set('tmesis.fleet.provider', ArrayDesiredStateProvider::class);

    expect(fn () => FleetConfig::assertUsable(app()))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.webhooks.dedupe.table.columns.delivery');

    // Doppler renders an absent value as "", which is no column either.
    config()->set('tmesis.webhooks.dedupe.table.columns.delivery', '');

    expect(fn () => FleetConfig::assertUsable(app()))
        ->toThrow(InvalidConfigurationException::class, 'two channels');
});

it('enables the fleet on the table driver once the delivery column is set', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'table');
    config()->set('tmesis.webhooks.dedupe.cache_store', 'database');
    config()->set('tmesis.webhooks.dedupe.table.columns.delivery', 'tmesis_delivery');
    config()->set('tmesis.fleet.provider', ArrayDesiredStateProvider::class);

    FleetConfig::assertUsable(app());

    expect(FleetConfig::provider(app()))->toBeInstanceOf(ArrayDesiredStateProvider::class);
});
