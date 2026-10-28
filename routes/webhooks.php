<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Webhooks\VerifyTmesisSignature;
use AlexHackney\Tmesis\Webhooks\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * The receiver. Loaded by TmesisServiceProvider only when tmesis.webhooks.enabled is
 * true, so installing the package cannot open a route.
 *
 * The signature middleware is the route's own authentication and is applied to the
 * route rather than to the group, so it runs after whatever group middleware the
 * consumer configured and before anything else can touch the request body.
 *
 * A consumer using the `web` group must exempt this path from CSRF, and must add the
 * prefix to any tenant-resolution bypass list: the node is not a tenant.
 */
Route::prefix((string) config('tmesis.webhooks.prefix', 'webhooks'))
    ->middleware((array) config('tmesis.webhooks.middleware', []))
    ->group(function (): void {
        Route::post('/tmesis', WebhookController::class)
            ->middleware(VerifyTmesisSignature::class.':webhook')
            ->name('tmesis.webhooks.receive');
    });
