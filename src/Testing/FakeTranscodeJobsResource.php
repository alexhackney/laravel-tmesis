<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Jobs\JobAccepted;
use AlexHackney\Tmesis\Data\Jobs\JobCancelled;
use AlexHackney\Tmesis\Data\Jobs\JobListPage;
use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobPauseResponse;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\TranscodeJobs;

/**
 * Transcode job reads and writes, answered from FakeTmesis's job ledger.
 *
 * A recorded submission carries `Spec::toRedactedArray()`: a presigned source URL is
 * recorded without its query string. `allTranscodeJobs()` is the real resource's,
 * following the fake's cursors. The jobs pause (tmesis `v0.9.0`) is FakeTmesis's one
 * box-wide pause; FakeTmesis::fleetOwnsJobsPause() makes its writes `409 fleet_owned`.
 */
class FakeTranscodeJobsResource extends TranscodeJobs
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function createTranscodeJob(Spec $job): JobAccepted
    {
        $body = $job->toRedactedArray();

        $this->fake->record('createTranscodeJob', $this->node, ['job' => $body]);

        return $this->fake->jobLedger()->submit($job, $this->node);
    }

    public function listTranscodeJobs(?string $state = null, ?int $limit = null, ?string $cursor = null): JobListPage
    {
        self::guardState($state);
        Artifacts::guardLimit($limit);

        $this->fake->record('listTranscodeJobs', $this->node, ['state' => $state, 'limit' => $limit, 'cursor' => $cursor]);

        return $this->fake->jobLedger()->list($state, $limit, $cursor);
    }

    public function getTranscodeJob(string $id): JobStatus
    {
        $this->fake->record('getTranscodeJob', $this->node, ['id' => $id]);

        return $this->fake->jobLedger()->get($id);
    }

    public function cancelTranscodeJob(string $id): JobCancelled
    {
        $this->fake->record('cancelTranscodeJob', $this->node, ['id' => $id]);

        return $this->fake->jobLedger()->cancel($id);
    }

    public function setTranscodePause(JobPauseRequest $pause): JobPauseResponse
    {
        $this->fake->record('setTranscodePause', $this->node, ['pause' => $pause->toArray()]);

        return $this->fake->setJobsPause($pause);
    }

    public function getTranscodePause(): JobPauseResponse
    {
        $this->fake->record('getTranscodePause', $this->node);

        return $this->fake->jobsPause();
    }

    public function clearTranscodePause(): void
    {
        $this->fake->record('clearTranscodePause', $this->node);

        $this->fake->clearJobsPause();
    }
}
