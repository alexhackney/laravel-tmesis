<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Jobs\JobOutputReport;
use AlexHackney\Tmesis\Data\Jobs\JobSourceReport;
use AlexHackney\Tmesis\Data\Webhooks\JobProbeSummary;

/**
 * Every REQUIRED output landed (tmesis `v0.6.0`, WEBHOOKS.md 8.20): each one's plan
 * has every transfer object `completed`. An optional output (`audio`, `thumbnails`,
 * `transcript`) that failed is in outputs() with an `error()` and did not fail the job.
 *
 * This is the payload for the app's completion service. It is NOT a release: each
 * output is its own generation, still held on the node, and the node deletes nothing
 * until `jobArtifacts()->releaseJobArtifact()` names it (docs/transcode-jobs.md).
 */
class JobCompleted extends JobEvent
{
    public const EVENT = 'job.completed';

    /**
     * The source's media duration.
     */
    public function durationSeconds(): float
    {
        return $this->float('duration_seconds');
    }

    /**
     * Wall time the encode stage took.
     */
    public function encodeSeconds(): float
    {
        return $this->float('encode_seconds');
    }

    public function attempts(): int
    {
        return $this->int('attempts');
    }

    public function evictions(): int
    {
        return $this->int('evictions');
    }

    public function source(): JobSourceReport
    {
        return $this->sourceReport();
    }

    /**
     * One per requested output in the order submitted (an `mp4` output one per rung),
     * each naming its generation.
     *
     * @return list<JobOutputReport>
     */
    public function outputs(): array
    {
        return $this->outputReports();
    }

    /**
     * What the probe learned of the source; null when the node reports none.
     */
    public function probe(): ?JobProbeSummary
    {
        $value = $this->payload['probe'] ?? null;

        /** @var array<string, mixed>|null $value */
        return is_array($value) ? JobProbeSummary::fromArray($value) : null;
    }
}
