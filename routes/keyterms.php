<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Hooks\KeytermsHookController;
use AlexHackney\Tmesis\Webhooks\VerifyTmesisSignature;
use Illuminate\Support\Facades\Route;

/*
 * The keyterms hook (tmesis v0.8.0). Loaded by TmesisServiceProvider ALWAYS: the node
 * reads a 404 as "no keyterms" and clears every session's list, so a switched-off
 * route still exists and answers a SIGNED request 503 keyterms_disabled (the node
 * keeps its list); an unsigned one is refused by the signature check as usual, so
 * the switched-off route answers nothing to anyone else. tmesis.hooks.keyterms.enabled
 * is read per request.
 *
 * Under the receiver's prefix and group middleware, beside the publish hook. The
 * keyterms purpose verifies with the node's keyterms hook secret (falling back to
 * its webhook secret) over the timestamp and an EMPTY body: the node signs a GET
 * and the query is not covered.
 */
Route::prefix((string) config('tmesis.webhooks.prefix', 'webhooks'))
    ->middleware((array) config('tmesis.webhooks.middleware', []))
    ->group(function (): void {
        Route::get('/hooks/keyterms', KeytermsHookController::class)
            ->middleware(VerifyTmesisSignature::class.':keyterms')
            ->name('tmesis.hooks.keyterms');
    });
