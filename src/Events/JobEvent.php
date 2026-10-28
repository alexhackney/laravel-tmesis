<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Jobs\JobOutputReport;
use AlexHackney\Tmesis\Data\Jobs\JobSourceReport;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;

/**
 * The shared base of the five `job.*` events (tmesis `v0.6.0`, WEBHOOKS.md 8.17 to
 * 8.21), on the flat envelope.
 *
 * A job event is about a JOB, not a stream: `stream()` is null and job() names it.
 * They are delivered to the job's `callback_url` when it set one (with its
 * generations' `artifact.*` and `transfer.*` events), else to `webhooks.url`, and are
 * at-least-once and unordered like every other event: order by `occurred_at`.
 * `labels()` are the job's, echoed.
 */
abstract class JobEvent extends TmesisWebhookReceived
{
    /**
     * The job id, `job_<ULID>`.
     */
    public function job(): string
    {
        return $this->string('job');
    }

    /**
     * The job's state after the event, one of `JobStatus::STATES`.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * @return list<JobOutputReport>
     */
    protected function outputReports(): array
    {
        return JobStatus::outputReports($this->payload['outputs'] ?? null);
    }

    protected function sourceReport(): JobSourceReport
    {
        return JobSourceReport::fromArray($this->object('source'));
    }
}
