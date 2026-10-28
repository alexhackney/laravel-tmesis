<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Jobs\JobAccepted;
use AlexHackney\Tmesis\Data\Jobs\JobCancelled;
use AlexHackney\Tmesis\Data\Jobs\JobListItem;
use AlexHackney\Tmesis\Data\Jobs\JobListPage;
use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobPauseResponse;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\LazyCollection;

/**
 * P10 VOD transcode jobs (tmesis `v0.6.0`, `docs/API.md` 9.10 to 9.12). Method names
 * are the openapi operationIds.
 *
 * The PUSH model: submit a source and the targets each output goes to, then hear
 * about it in webhooks (`job.*` for the job, `artifact.*` and `transfer.*` for each
 * output's generation, all to the Spec's `callback_url`). getTranscodeJob() is the
 * read for a control plane that missed a webhook, not a polling loop.
 *
 * **The caller is the single scheduler per output prefix.** The node refuses `409
 * destination_busy` only for a prefix a job or live stream on THIS node writes; it
 * cannot see a writer on another node, so submit a prefix to one node at a time.
 *
 * The outputs are generations: read, re-plan and release them through JobArtifacts.
 * Nothing auto-releases.
 *
 * The told jobs PAUSE (tmesis `v0.9.0`, handover 3.5, `docs/API.md` 9.12a) is on the
 * same `/api/v1/transcode` group: setTranscodePause(), getTranscodePause(),
 * clearTranscodePause(). With no job engine all three answer `501`, as the job routes
 * do.
 */
class TranscodeJobs
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * POST /api/v1/transcode/jobs: submit a job.
     *
     * `202` queued it (`JobAccepted::$created` true); `200` is the idempotent repeat
     * of a known `idempotency_key` (in any state, completed included), which queued
     * nothing. So a timed-out submit is retried with the SAME Spec.
     *
     * `409 role_unsupported` (an edge, or `jobs.enabled` off) and `409
     * destination_busy` (a target overlaps a prefix this node writes), `422`
     * `validation_failed` / `source_not_supported` / `output_not_supported` /
     * `destination_not_supported` with every problem at once, `429 queue_full`, `503
     * draining`, and `501` (NotImplementedException) when this build has no job
     * runner. The 501 is the POST's alone.
     *
     * `503 encoder_unavailable` (EncoderUnavailableException, tmesis `v0.9.0`,
     * handover 3.7) is NOT retryable on this box: the pinned jobs encoder failed at
     * boot and holds until repaired and restarted. Send the job to another node.
     */
    public function createTranscodeJob(Spec $job): JobAccepted
    {
        $result = $this->transport->post('/api/v1/transcode/jobs', $job->toArray());

        return JobAccepted::fromArray($result->body, created: $result->status === 202);
    }

    /**
     * GET /api/v1/transcode/jobs: one page of this node's job SUMMARIES,
     * `(submitted_at, id)` descending, terminal ones included.
     *
     * @param  string|null  $state  one of `JobStatus::STATES`, or null for every state
     */
    public function listTranscodeJobs(?string $state = null, ?int $limit = null, ?string $cursor = null): JobListPage
    {
        self::guardState($state);
        Artifacts::guardLimit($limit);

        return JobListPage::fromArray($this->transport->get('/api/v1/transcode/jobs', [
            'state' => $state,
            'limit' => $limit,
            'cursor' => $cursor,
        ]));
    }

    /**
     * Every job summary (in `$state`), following the cursor lazily.
     *
     * @return LazyCollection<int, JobListItem>
     */
    public function allTranscodeJobs(?string $state = null, ?int $limit = null): LazyCollection
    {
        self::guardState($state);
        Artifacts::guardLimit($limit);

        return ArtifactRoutes::walk(function (?string $cursor) use ($state, $limit): array {
            $page = $this->listTranscodeJobs($state, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * GET /api/v1/transcode/jobs/:id: the job as its row stands, every output with its
     * generation. `404` for no such job on this node.
     */
    public function getTranscodeJob(string $id): JobStatus
    {
        return JobStatus::fromArray($this->transport->get($this->transport->path('/api/v1/transcode/jobs/%s', $id)));
    }

    /**
     * DELETE /api/v1/transcode/jobs/:id: cancel. `202`; `409 job_not_cancellable` for
     * a job already terminal. The webhook is `job.failed` with state `cancelled`, and
     * its `landed` lists what already transferred: the node never deletes remotely.
     */
    public function cancelTranscodeJob(string $id): JobCancelled
    {
        return JobCancelled::fromArray($this->transport->delete($this->transport->path('/api/v1/transcode/jobs/%s', $id))->body);
    }

    /**
     * PUT /api/v1/transcode/pause: claim no new job until `until`, for `reason`.
     *
     * `200` with the stored pause; it REPLACES any pause the box holds in place (an
     * update of `until` or `reason` is not a new pause and sends no second
     * `jobs.paused`), survives a restart, and a past `until` is stored and answers
     * `{"paused": false}`. Running jobs finish and submissions still queue.
     *
     * `409 fleet_owned` (FleetOwnedException, detail `jobs_pause`) on an ENROLLED
     * box: the desired state's `jobs_pause` owns the pause there. `422
     * validation_failed` (details `until` or `reason`), `503 journal_unavailable`.
     */
    public function setTranscodePause(JobPauseRequest $pause): JobPauseResponse
    {
        return JobPauseResponse::fromArray($this->transport->put('/api/v1/transcode/pause', $pause->toArray())->body);
    }

    /**
     * GET /api/v1/transcode/pause: the pause the engine holds, enrolled or not;
     * exactly `{"paused": false}` when none is active.
     */
    public function getTranscodePause(): JobPauseResponse
    {
        return JobPauseResponse::fromArray($this->transport->get('/api/v1/transcode/pause'));
    }

    /**
     * DELETE /api/v1/transcode/pause: `204`, idempotent (clearing nothing is still
     * `204`); queued jobs are claimed again at once. `409 fleet_owned` on an enrolled
     * box, `503 journal_unavailable`.
     */
    public function clearTranscodePause(): void
    {
        $this->transport->delete('/api/v1/transcode/pause');
    }

    /**
     * A state filter outside the six, refused before the request (the node's `400`).
     */
    public static function guardState(?string $state): void
    {
        if ($state !== null && ! in_array($state, JobStatus::STATES, true)) {
            throw InvalidConfigurationException::invalid('state', 'it must be one of '.implode(', ', JobStatus::STATES));
        }
    }
}
