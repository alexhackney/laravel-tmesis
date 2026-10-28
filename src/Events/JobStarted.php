<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A claim of the job started running (tmesis `v0.6.0`, WEBHOOKS.md 8.18). A resumed
 * job starts past its completed stages.
 */
class JobStarted extends JobEvent
{
    public const EVENT = 'job.started';

    /**
     * This claim's attempt count.
     */
    public function attempt(): int
    {
        return $this->int('attempt');
    }

    /**
     * Wait since this claim's `queued_at`, which a requeue resets.
     */
    public function queuedSeconds(): float
    {
        return $this->float('queued_seconds');
    }

    /**
     * The video encoder the node selected at boot (`libx264`, `h264_nvenc`, ...).
     */
    public function backend(): string
    {
        return $this->string('backend');
    }
}
