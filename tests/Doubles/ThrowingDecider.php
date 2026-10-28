<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;
use RuntimeException;

/**
 * The deploy that is broken right now: a decider whose dependency is down.
 */
class ThrowingDecider implements PublishHookDecider
{
    public function decide(HookRequest $request): HookDecision
    {
        throw new RuntimeException('the entitlement store is unreachable');
    }
}
