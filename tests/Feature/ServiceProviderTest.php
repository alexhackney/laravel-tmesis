<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\TmesisClient;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Keys\StreamKeyMinter;
use AlexHackney\Tmesis\TmesisServiceProvider;
use AlexHackney\Tmesis\Webhooks\CacheDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DatabaseDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\VerifyTmesisSignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

it('registers the bindings', function (): void {
    expect(app(TmesisClientInterface::class))->toBeInstanceOf(TmesisClient::class)
        ->and(app('tmesis'))->toBe(app(TmesisClientInterface::class))
        ->and(app(NodeRegistry::class))->toBe(app(NodeRegistry::class))
        ->and(app(StreamKeyMinter::class))->toBeInstanceOf(StreamKeyMinter::class)
        ->and(app(DeliveryDeduper::class))->toBeInstanceOf(CacheDeliveryDeduper::class);
});

it('merges the package config', function (): void {
    expect(config('tmesis.http.timeout'))->toBe(10)
        ->and(config('tmesis.webhooks.prefix'))->toBe('webhooks')
        ->and(config('tmesis.webhooks.dedupe.retention_hours'))->toBe(48);
});

it('picks the deduper from the configured driver', function (): void {
    $this->usesDatabaseDeduper();

    expect(app(DeliveryDeduper::class))->toBeInstanceOf(DatabaseDeliveryDeduper::class);
});

it('registers the receiver route with only the signature middleware', function (): void {
    expect(Route::has('tmesis.webhooks.receive'))->toBeTrue();

    $route = Route::getRoutes()->getByName('tmesis.webhooks.receive');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('webhooks/tmesis')
        ->and($route->methods())->toContain('POST')
        // webhooks.middleware defaults to []: the signature middleware IS the auth,
        // and a consumer's api group would put the node under throttle:api.
        ->and($route->gatherMiddleware())->toBe([VerifyTmesisSignature::class.':webhook']);
});

it('does not register the route when webhooks are disabled', function (): void {
    $this->withWebhooksDisabled();

    expect(config('tmesis.webhooks.enabled'))->toBeFalse()
        ->and(Route::has('tmesis.webhooks.receive'))->toBeFalse();
});

it('publishes the config and the migration under their tags', function (): void {
    $config = ServiceProvider::pathsToPublish(
        TmesisServiceProvider::class,
        'tmesis-config'
    );

    $migrations = ServiceProvider::pathsToPublish(
        TmesisServiceProvider::class,
        'tmesis-migrations'
    );

    expect($config)->not->toBeEmpty()
        ->and($migrations)->not->toBeEmpty();
});
