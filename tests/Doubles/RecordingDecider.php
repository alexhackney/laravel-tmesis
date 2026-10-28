<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;

/**
 * Records what it was asked, so a test can prove the decider ran, or did not.
 *
 * "Did not" is the interesting half: a bad signature and a wrong event name must both
 * be refused before any decision code sees the body.
 */
class RecordingDecider implements PublishHookDecider
{
    public static int $calls = 0;

    public static ?HookRequest $last = null;

    public static ?HookDecision $answer = null;

    public static function reset(): void
    {
        self::$calls = 0;
        self::$last = null;
        self::$answer = null;
    }

    public function decide(HookRequest $request): HookDecision
    {
        self::$calls++;
        self::$last = $request;

        return self::$answer ?? HookDecision::allow();
    }
}
