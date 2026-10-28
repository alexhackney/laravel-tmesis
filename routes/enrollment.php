<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\EnrollmentController;
use Illuminate\Support\Facades\Route;

/*
 * Node enrolment: a new box fetches user-data and bundled config, then registers back. Loaded by
 * TmesisServiceProvider only when tmesis.enrollment.enabled is true.
 *
 * Under the receiver's prefix and group middleware, so a consumer that made the
 * webhook paths tenantless and CSRF-exempt has these covered too.
 *
 * install and config take the bootstrap token as `Authorization: Bearer` (never
 * the query string) and do not burn it. register takes the token in the body as the
 * identifier AND the node's webhook signature over the raw body, made with the
 * webhook secret minted for that enrolment, which the box has only once its
 * user-data has written its .env. The controller verifies it, not the receiver's
 * middleware: the secret is looked up by the token, not by X-Tmesis-Node.
 */
Route::prefix((string) config('tmesis.webhooks.prefix', 'webhooks'))
    ->middleware((array) config('tmesis.webhooks.middleware', []))
    ->group(function (): void {
        Route::post('/enroll/register', [EnrollmentController::class, 'register'])
            ->name('tmesis.enroll.register');

        Route::get('/enroll/install', [EnrollmentController::class, 'install'])
            ->name('tmesis.enroll.install');

        Route::get('/enroll/config', [EnrollmentController::class, 'config'])
            ->name('tmesis.enroll.config');
    });
