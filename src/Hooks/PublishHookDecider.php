<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Hooks;

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;

/**
 * Decides one `publish.verify` hook.
 *
 * The node is holding a publish handshake open waiting for this, under a hard cap
 * (`ingest.verify_hook.timeout_ms`, 500 ms by default) that is a CAP and not a
 * target. So an implementation reads what it already has: a cached entitlement, a
 * column on a row it can fetch by stream id, a feature flag. It does not call another
 * service, and it does not queue anything.
 *
 * Branch on `$request->keyType()`. `signed` means the node already proved the key
 * cryptographically and is asking whether the show should run at all;
 * `bare_unproven` means nothing local vouches for the publisher, so this decision is
 * the only entitlement check it gets.
 *
 * On a hook timeout or an unreachable hook (tmesis `v0.6.0`, #141 option B): a signed
 * valid key, a bare key in `dual_accept.map`, and since `v0.6.0` a bare unproven key
 * on an OPEN node all take `on_timeout` as configured (the last with a WARN and
 * `tmesis_hook_outage_allowed_total{stream}`; through `v0.5.0` it was a forced deny).
 * A bare unproven key on a CLOSED node, a revoked key, and one below `min_key_epoch`
 * never reach the hook. So with `on_timeout: allow`, an outage is a window where
 * this check did not run.
 *
 * Throwing is allowed and is not fatal: the controller answers the configured
 * `tmesis.hooks.on_error` posture with a 200 and logs the class that threw.
 */
interface PublishHookDecider
{
    public function decide(HookRequest $request): HookDecision;
}
