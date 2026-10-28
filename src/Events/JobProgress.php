<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Jobs\JobOutputReport;

/**
 * Progress of a running job (tmesis `v0.6.0`, WEBHOOKS.md 8.19).
 *
 * outputs() holds ONLY the outputs that have already landed (`completed`), so an early
 * audio output reaches the caller before the ladder finishes. `percent()` is within
 * the stage and is not monotonic across a retry.
 */
class JobProgress extends JobEvent
{
    public const EVENT = 'job.progress';

    /**
     * 0 to 100 within the stage.
     */
    public function percent(): float
    {
        return $this->float('percent');
    }

    /**
     * One of `JobStatus::STAGES`.
     */
    public function stage(): string
    {
        return $this->string('stage');
    }

    /**
     * Multiples of realtime for the current stage.
     */
    public function speed(): float
    {
        return $this->float('speed');
    }

    public function etaSeconds(): ?float
    {
        $value = $this->payload['eta_seconds'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The outputs that have already landed; empty until one has.
     *
     * @return list<JobOutputReport>
     */
    public function outputs(): array
    {
        return $this->outputReports();
    }
}
