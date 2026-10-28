<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Fleet\Http\DispatchAfterHeartbeat;
use AlexHackney\Tmesis\Fleet\Http\EnrollController;
use AlexHackney\Tmesis\Fleet\Http\HeartbeatController;
use Illuminate\Support\Facades\Route;

/*
 * The fleet control plane (tmesis v0.7.0, docs/API.md 15). Loaded by
 * TmesisServiceProvider only when tmesis.fleet.enabled is true.
 *
 * Under the receiver's prefix and group middleware, so a consumer that made the webhook
 * paths tenantless and CSRF-exempt has these covered too. Each route authenticates
 * itself with a bearer token: the one-time join token on POST fleet/enroll, the fleet
 * token on the other two. There is no HMAC on this channel; the bearer over TLS is the
 * authentication (the node refuses plain http off loopback, D8).
 *
 * The node POSTs the enroll URL EXACTLY as the operator gave it, and the heartbeat URL
 * exactly as the enroll answer gave it (absolute, never appended to). Both are the
 * absolute URLs of these named routes.
 */
Route::prefix((string) config('tmesis.webhooks.prefix', 'webhooks'))
    ->middleware((array) config('tmesis.webhooks.middleware', []))
    ->group(function (): void {
        Route::post('/fleet/enroll', [EnrollController::class, 'enroll'])
            ->name('tmesis.fleet.enroll');

        Route::delete('/fleet/enroll', [EnrollController::class, 'leave'])
            ->name('tmesis.fleet.leave');

        Route::post('/fleet/heartbeat', [HeartbeatController::class, 'heartbeat'])
            ->middleware(DispatchAfterHeartbeat::class)
            ->name('tmesis.fleet.heartbeat');
    });
