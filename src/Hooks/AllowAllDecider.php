<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Hooks;

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;

/**
 * The default decider: allow everything the node already proved locally.
 *
 * That is deliberately the node's own behaviour with no hook configured, so turning
 * the endpoint on changes nothing until a consumer binds its own decider. A default
 * that denied would make an unconfigured receiver look like a working veto, which is
 * the failure mode `docs/WEBHOOKS.md` 12.4 spends a section on.
 *
 * A key that local DISPROOF rejects never reaches here (12.2), so this is not a hole:
 * a bad signature is refused by the node with no call at all.
 */
final class AllowAllDecider implements PublishHookDecider
{
    public function decide(HookRequest $request): HookDecision
    {
        return HookDecision::allow();
    }
}
