<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Jobs\JobError;
use AlexHackney\Tmesis\Data\Webhooks\JobLandedObject;

/**
 * A job ended without completing (tmesis `v0.6.0`, WEBHOOKS.md 8.21): it `failed`, or
 * it was `cancelled` by a DELETE. The v1 event set has no `job.cancelled`, so a cancel
 * arrives here with `state` `cancelled` and `error.code` `cancelled`: branch on
 * isCancelled() and do not alert on it.
 *
 * Either way the node superseded every plan the job filed with an empty revision and
 * discarded its held generations. landed() lists the objects that had ALREADY
 * transferred: the node never deletes remotely, so removing them is the caller's.
 */
class JobFailed extends JobEvent
{
    public const EVENT = 'job.failed';

    public const STATE_FAILED = 'failed';

    public const STATE_CANCELLED = 'cancelled';

    public function isCancelled(): bool
    {
        return $this->state() === self::STATE_CANCELLED;
    }

    public function attempts(): int
    {
        return $this->int('attempts');
    }

    public function evictions(): int
    {
        return $this->int('evictions');
    }

    /**
     * The stage it ended in (`JobStatus::STAGES`); null for a job that never ran.
     */
    public function stage(): ?string
    {
        return $this->nullableString('stage');
    }

    public function error(): JobError
    {
        return JobError::fromArray($this->object('error'));
    }

    /**
     * Objects already transferred when the job ended; empty when none.
     *
     * @return list<JobLandedObject>
     */
    public function landed(): array
    {
        $value = $this->payload['landed'] ?? null;
        $out = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = JobLandedObject::fromArray($entry);
            }
        }

        return $out;
    }
}
