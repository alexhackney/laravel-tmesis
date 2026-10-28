<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * The node stopped claiming new transcode jobs (tmesis `v0.9.0`, handover 3.5;
 * WEBHOOKS.md 8.30). Node-scoped: no stream, no labels, and not a JobEvent (it names
 * no job).
 *
 * Fires once per pause, when one STARTS taking effect while none was active. Never
 * for an update in place of until or reason, never at boot (a restored pause is
 * NodeOnline::jobsPause() and the heartbeat), never for a pause the fleet sends
 * before the job engine started. While it holds, running jobs finish, queued jobs
 * wait, and a submission is still accepted (`202`). The heartbeat, not this event,
 * is the source of truth for whether to offer the box jobs.
 */
class JobsPaused extends TmesisWebhookReceived
{
    public const EVENT = 'jobs.paused';

    /**
     * The control plane's desired state (this enrollment's `jobs_pause`).
     */
    public const SOURCE_FLEET = 'fleet';

    /**
     * `PUT /api/v1/transcode/pause` on the box itself.
     */
    public const SOURCE_API = 'api';

    public function untilRaw(): ?string
    {
        $value = $this->nullableString('until');

        return $value === '' ? null : $value;
    }

    /**
     * When the pause ends by itself; null until it is cleared.
     */
    public function until(): ?DateTimeImmutable
    {
        return $this->nullableTimestamp('until');
    }

    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * CLOSED set: the SOURCE_* constants.
     */
    public function source(): string
    {
        return $this->string('source');
    }

    public function setAtRaw(): string
    {
        return $this->required('set_at');
    }

    /**
     * When the pause was written. The matching `jobs.resumed` carries it back as
     * JobsResumed::pausedAt(), which is how the two pair.
     */
    public function setAt(): DateTimeImmutable
    {
        return $this->timestamp('set_at');
    }
}
