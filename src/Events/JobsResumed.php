<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * A jobs pause ended and the node claims transcode jobs again (tmesis `v0.9.0`,
 * handover 3.5; WEBHOOKS.md 8.31). Node-scoped: no stream, no labels.
 *
 * Fires once per ended pause, paired with its `jobs.paused` by pausedAt(), which
 * equals that event's JobsPaused::setAt(). A pause that expired while the node was
 * down sends nothing.
 */
class JobsResumed extends TmesisWebhookReceived
{
    public const EVENT = 'jobs.resumed';

    /**
     * `until` passed. A clear that lands after `until` but before the node's timer
     * still reads `expired`, and the clear sends nothing more.
     */
    public const CAUSE_EXPIRED = 'expired';

    /**
     * `DELETE /api/v1/transcode/pause`, or a desired-state revision without
     * `jobs_pause`.
     */
    public const CAUSE_CLEARED = 'cleared';

    /**
     * The fleet pause ended with its enrollment: a leave, or a boot under another
     * enrollment.
     */
    public const CAUSE_RELEASED = 'released';

    public const SOURCE_FLEET = 'fleet';

    public const SOURCE_API = 'api';

    /**
     * CLOSED set: the CAUSE_* constants.
     */
    public function cause(): string
    {
        return $this->string('cause');
    }

    /**
     * Who set the pause that ended, CLOSED set: the SOURCE_* constants.
     */
    public function source(): string
    {
        return $this->string('source');
    }

    public function pausedAtRaw(): string
    {
        return $this->required('paused_at');
    }

    /**
     * When the ended pause started taking effect: its `jobs.paused` set_at.
     */
    public function pausedAt(): DateTimeImmutable
    {
        return $this->timestamp('paused_at');
    }
}
