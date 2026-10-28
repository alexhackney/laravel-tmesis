<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use AlexHackney\Tmesis\Events\PublishHookDecided;
use Illuminate\Support\Facades\Log;

/**
 * One log line per publish decision.
 *
 * `elapsed_ms` is the DECIDER's time, not the round trip: the node's 500 ms cap
 * covers the whole request, and this is the part a receiver controls. The key
 * fragment is the only form of a publish key that may be logged.
 */
class LogHookDecision
{
    public function handle(PublishHookDecided $event): void
    {
        Log::info('publish.verify', [
            'stream' => $event->stream(),
            'key_type' => $event->request->keyType(),
            'key_fragment' => $event->keyFragment(),
            'open_posture' => $event->request->openPosture(),
            'allow' => $event->allowed(),
            'elapsed_ms' => round($event->elapsedMs, 2),
        ]);
    }
}
