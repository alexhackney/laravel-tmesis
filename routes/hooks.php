<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Hooks\HookController;
use AlexHackney\Tmesis\Webhooks\VerifyTmesisSignature;
use Illuminate\Support\Facades\Route;

/*
 * The synchronous publish veto. Loaded by TmesisServiceProvider only when
 * tmesis.hooks.enabled is true, so installing the package cannot open a route.
 *
 * It sits under the same prefix and the same group middleware as the receiver: the
 * node signs both with one signer, and a consumer that exempted one path from CSRF
 * and tenant resolution should not have to find a second one somewhere else.
 *
 * The purpose argument is what makes the secret differ. The node's
 * ingest.verify_hook.secret MAY be set separately from its webhooks.secret; when it
 * is blank here the resolver falls back to the webhook secret.
 */
Route::prefix((string) config('tmesis.webhooks.prefix', 'webhooks'))
    ->middleware((array) config('tmesis.webhooks.middleware', []))
    ->group(function (): void {
        Route::post('/hooks/publish', HookController::class)
            ->middleware(VerifyTmesisSignature::class.':hook')
            ->name('tmesis.hooks.publish');
    });
