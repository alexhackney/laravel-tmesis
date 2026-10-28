<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;

/**
 * Slower than any sane budget, so the warning can be asserted without asserting a
 * wall clock threshold as though it were a promise.
 */
class SlowDecider implements PublishHookDecider
{
    public const SLEEP_MICROSECONDS = 20_000;

    public function decide(HookRequest $request): HookDecision
    {
        usleep(self::SLEEP_MICROSECONDS);

        return HookDecision::allow();
    }
}
