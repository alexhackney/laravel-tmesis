<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\ErrorResponse;
use AlexHackney\Tmesis\Data\Jobs\JobAccepted;
use AlexHackney\Tmesis\Data\Jobs\JobCancelled;
use AlexHackney\Tmesis\Data\Jobs\JobError;
use AlexHackney\Tmesis\Data\Jobs\JobListPage;
use AlexHackney\Tmesis\Data\Jobs\JobOutputReport;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Exceptions\ErrorMap;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Resources\TranscodeJobs;
use Illuminate\Support\Str;

/**
 * FakeTmesis's in-memory transcode jobs (tmesis `v0.6.0`, `docs/API.md` 9.10 to 9.12).
 *
 * Modelled: submission with the idempotent repeat (the same `idempotency_key` answers
 * `200` with the existing job, whatever its body, as at the node), the state machine a
 * test drives (start, progress, complete, fail, cancel, with `409
 * job_not_cancellable` on a terminal job), the paged list, and each output's
 * GENERATION: completing a job finalizes one generation per output in the artifact
 * ledger, keyed by the job, with the Spec's targets filed as revision 1 and every
 * object landed. So `assertEveryFinalizedGenerationReleased()` holds a job's outputs to
 * the release obligation like any stream's. A failed or cancelled job supersedes its
 * plans with an empty revision, and reports what had landed first.
 *
 * Not modelled: ladders and rungs (an `mp4` output is one entry, D3), scheduling,
 * eviction, the cross-job `destination_busy` fence, and webhooks (build them with
 * FakeTmesis::webhook()).
 *
 * SECRETS: a URL source may be presigned. The Spec object is never kept: only its
 * redacted body (`Spec::toRedactedArray()`, the source rebuilt without userinfo,
 * query or fragment) and, per output, the target fields completion needs, a signed
 * target reduced to its bucket fields. A signed target carries no credential, and
 * nothing here ever holds one.
 *
 * @internal reached through FakeTmesis and FakeTranscodeJobsResource
 */
final class FakeJobLedger
{
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 1000;

    /**
     * @var array<string, array{status: array<string, mixed>, targets: array<string, list<array<string, mixed>>>}>
     */
    private array $jobs = [];

    /** @var array<string, string> idempotency key to job id */
    private array $keys = [];

    /**
     * What had landed when each failed or cancelled job wound down, by job id.
     *
     * @var array<string, list<array{generation: string, target_id: string, object_key: string}>>
     */
    private array $landed = [];

    public function __construct(private readonly FakeArtifactLedger $artifacts) {}

    /**
     * The node's answer to a submission: `202` and a new `queued` job, or `200` and the
     * existing job for a known key.
     */
    public function submit(Spec $spec, string $node): JobAccepted
    {
        $existing = $this->keys[$spec->idempotencyKey()] ?? null;

        if ($existing !== null) {
            return JobAccepted::fromArray(self::accepted($this->jobs[$existing]['status']), created: false);
        }

        $id = 'job_'.Str::ulid();
        $body = $spec->toRedactedArray();

        /** @var array<string, list<array<string, mixed>>> $targets */
        $targets = $body['targets'];

        /** @var array<string, string> $labels */
        $labels = is_array($body['labels'] ?? null) ? $body['labels'] : [];

        $this->jobs[$id] = [
            'targets' => self::keptTargets($targets),
            'status' => [
                'id' => $id,
                'state' => JobStatus::STATE_QUEUED,
                'priority' => is_string($body['priority'] ?? null) ? $body['priority'] : JobStatus::PRIORITY_NORMAL,
                'node' => $node,
                'idempotency_key' => $spec->idempotencyKey(),
                'submitted_at' => FakeArtifactLedger::NOW,
                'started_at' => null,
                'completed_at' => null,
                'attempts' => 0,
                'evictions' => 0,
                'progress' => ['percent' => 0, 'stage' => null, 'speed' => 0, 'eta_seconds' => null],
                'source' => $body['source'],
                'outputs' => array_map(
                    static fn (string $output): array => ['kind' => $output, 'generation' => null, 'state' => JobOutputReport::STATE_PENDING],
                    array_keys($targets),
                ),
                'error' => null,
                'labels' => $labels,
            ],
        ];

        $this->keys[$spec->idempotencyKey()] = $id;

        return JobAccepted::fromArray(self::accepted($this->jobs[$id]['status']), created: true);
    }

    public function get(string $id): JobStatus
    {
        return JobStatus::fromArray($this->row($id)['status']);
    }

    public function list(?string $state, ?int $limit, ?string $cursor): JobListPage
    {
        TranscodeJobs::guardState($state);

        $items = [];

        foreach ($this->jobs as $job) {
            $status = $job['status'];

            if ($state !== null && $status['state'] !== $state) {
                continue;
            }

            $error = is_array($status['error']) ? $status['error'] : null;

            $items[] = [
                'id' => $status['id'],
                'state' => $status['state'],
                'priority' => $status['priority'],
                'stage' => $status['state'] === JobStatus::STATE_RUNNING ? $status['progress']['stage'] : null,
                'percent' => $status['state'] === JobStatus::STATE_RUNNING ? $status['progress']['percent'] : 0,
                'attempts' => $status['attempts'],
                'evictions' => $status['evictions'],
                'submitted_at' => $status['submitted_at'],
                'started_at' => $status['started_at'],
                'completed_at' => $status['completed_at'],
                'error_code' => $error['code'] ?? null,
                'labels' => $status['labels'],
            ];
        }

        // (submitted_at, id) descending; the fake has no clock, so the id orders.
        usort($items, static fn (array $a, array $b): int => [$b['submitted_at'], $b['id']] <=> [$a['submitted_at'], $a['id']]);

        $scope = 'jobs|'.($state ?? '');
        $limit = min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = 0;

        if ($cursor !== null) {
            $decoded = json_decode((string) base64_decode($cursor, true), true);

            if (! is_array($decoded) || ($decoded['s'] ?? null) !== $scope || ! is_int($decoded['o'] ?? null)) {
                throw self::error(400, 'bad_request', 'The cursor was not issued for this filter.');
            }

            $offset = $decoded['o'];
        }

        return JobListPage::fromArray([
            'items' => array_slice($items, $offset, $limit),
            'limit' => $limit,
            'next_cursor' => $offset + $limit < count($items) ? base64_encode((string) json_encode(['s' => $scope, 'o' => $offset + $limit])) : null,
        ]);
    }

    public function start(string $id, string $stage = 'fetch'): void
    {
        $this->transition($id, [JobStatus::STATE_QUEUED, JobStatus::STATE_CLAIMED], [
            'state' => JobStatus::STATE_RUNNING,
            'started_at' => $this->row($id)['status']['started_at'] ?? FakeArtifactLedger::NOW,
            'attempts' => max(1, (int) $this->row($id)['status']['attempts']),
            'progress' => ['percent' => 0, 'stage' => $stage, 'speed' => 1, 'eta_seconds' => null],
        ]);
    }

    public function progress(string $id, string $stage, float $percent): void
    {
        if (! in_array($stage, JobStatus::STAGES, true)) {
            throw InvalidConfigurationException::invalid('stage', 'it must be one of '.implode(', ', JobStatus::STAGES));
        }

        $this->transition($id, [JobStatus::STATE_RUNNING], [
            'progress' => ['percent' => $percent, 'stage' => $stage, 'speed' => 1, 'eta_seconds' => null],
        ]);
    }

    /**
     * Finalize one generation per output, file its targets as revision 1, land every
     * object, and complete the job. `$outputs` merges fields into an output's report
     * by kind (`['audio' => ['state' => 'failed', 'error' => [...]]]`); an output
     * reported failed gets no generation.
     *
     * @param  array<string, array<string, mixed>>  $outputs
     * @return array<string, string> output kind to its generation
     */
    public function complete(string $id, array $outputs = []): array
    {
        $row = $this->row($id);
        $this->guardActive($row['status']);

        $generations = [];
        $reports = [];

        foreach ($row['targets'] as $output => $targets) {
            $override = $outputs[$output] ?? [];

            if (($override['state'] ?? null) === JobOutputReport::STATE_FAILED) {
                $reports[] = ['kind' => $output, 'generation' => null, ...$override];

                continue;
            }

            $generation = (string) Str::ulid();
            $this->finalizeOutput($id, $output, $generation, $targets, $row['status']['labels']);

            foreach (array_keys($this->artifacts->currentTargets($generation)) as $targetId) {
                $this->artifacts->settleTarget(FakeArtifactLedger::jobOwner($id), $generation, $targetId, ObjectStatus::STATE_COMPLETE);
            }

            $generations[$output] = $generation;
            $reports[] = [
                'kind' => $output,
                'generation' => $generation,
                'state' => JobOutputReport::STATE_COMPLETED,
                'url' => self::url($output, $targets[0]),
                ...$override,
            ];
        }

        $this->jobs[$id]['status'] = array_replace($this->jobs[$id]['status'], [
            'state' => JobStatus::STATE_COMPLETED,
            'started_at' => $this->jobs[$id]['status']['started_at'] ?? FakeArtifactLedger::NOW,
            'completed_at' => FakeArtifactLedger::NOW,
            'progress' => ['percent' => 100, 'stage' => null, 'speed' => 0, 'eta_seconds' => null],
            'outputs' => $reports,
        ]);

        return $generations;
    }

    /**
     * The job's terminal failure, wound down as at the node.
     *
     * @return list<array{generation: string, target_id: string, object_key: string}> what had landed
     */
    public function fail(string $id, string $code, string $class, ?string $stage): array
    {
        $this->guardActive($this->row($id)['status']);

        return $this->windDown($id, JobStatus::STATE_FAILED, array_filter([
            'code' => $code,
            'class' => $class,
            'message' => "{$code} in FakeTmesis",
            'retryable' => $class === JobError::CLASS_TRANSIENT,
            'stage' => $stage,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function cancel(string $id): JobCancelled
    {
        $status = $this->row($id)['status'];

        if (in_array($status['state'], JobStatus::TERMINAL_STATES, true)) {
            throw self::error(409, 'job_not_cancellable', "The job {$id} is {$status['state']} already.");
        }

        $this->windDown($id, JobStatus::STATE_CANCELLED, [
            'code' => JobError::CODE_CANCELLED,
            'class' => JobError::CLASS_PERMANENT,
            'message' => 'cancelled by DELETE',
            'retryable' => false,
        ]);

        return JobCancelled::fromArray(['id' => $id, 'state' => JobStatus::STATE_CANCELLED]);
    }

    /**
     * What had landed when the job failed or was cancelled; empty before then.
     *
     * @return list<array{generation: string, target_id: string, object_key: string}>
     */
    public function landed(string $id): array
    {
        $this->row($id);

        return $this->landed[$id] ?? [];
    }

    public function knows(string $id): bool
    {
        return isset($this->jobs[$id]);
    }

    /**
     * The job submitted under `$key`, if any.
     */
    public function byKey(string $key): ?JobStatus
    {
        $id = $this->keys[$key] ?? null;

        return $id === null ? null : $this->get($id);
    }

    /**
     * The generations the job's outputs finalized, by output kind.
     *
     * @return array<string, string>
     */
    public function generations(string $id): array
    {
        $out = [];

        foreach ($this->row($id)['status']['outputs'] as $report) {
            if (is_string($report['generation'] ?? null)) {
                $out[(string) $report['kind']] = $report['generation'];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @param  array<string, string>  $labels
     */
    private function finalizeOutput(string $job, string $output, string $generation, array $targets, array $labels): void
    {
        $kind = Spec::GENERATION_KINDS[$output];

        $this->artifacts->seedJobFinalized($job, $generation, $kind, null, null, $labels, 72);
        $this->artifacts->fileNodePlan(
            FakeArtifactLedger::jobOwner($job),
            $generation,
            array_map(static fn (array $target): PlanTarget => self::planTarget($kind, $target), $targets),
        );
    }

    /**
     * What completion needs of each target, from the rendered body: the id, the
     * destination or the signed bucket's non-secret fields, and the location.
     *
     * @param  array<string, list<array<string, mixed>>>  $targets
     * @return array<string, list<array<string, mixed>>>
     */
    private static function keptTargets(array $targets): array
    {
        $keep = ['id', 'destination_ref', 'signed', 'key', 'prefix', 'master_name'];
        $signed = ['region', 'bucket', 'endpoint', 'addressing', 'part_size_mb', 'upload_concurrency'];

        return array_map(static fn (array $list): array => array_map(static function (array $target) use ($keep, $signed): array {
            $target = array_intersect_key($target, array_flip($keep));

            if (is_array($target['signed'] ?? null)) {
                $target['signed'] = array_intersect_key($target['signed'], array_flip($signed));
            }

            return $target;
        }, $list), $targets);
    }

    /**
     * The target as the node files it for the output's generation (JobTarget::toPlanTarget()).
     *
     * @param  array<string, mixed>  $target
     */
    private static function planTarget(string $kind, array $target): PlanTarget
    {
        $signed = is_array($target['signed'] ?? null) ? $target['signed'] : null;

        $destination = $signed === null
            ? (string) ($target['destination_ref'] ?? '')
            : SignedDestination::make(
                (string) ($signed['region'] ?? ''),
                (string) ($signed['bucket'] ?? ''),
                isset($signed['endpoint']) ? (string) $signed['endpoint'] : null,
                isset($signed['addressing']) ? (string) $signed['addressing'] : null,
                isset($signed['part_size_mb']) ? (int) $signed['part_size_mb'] : null,
                isset($signed['upload_concurrency']) ? (int) $signed['upload_concurrency'] : null,
            );

        return PlanTarget::forKind(
            $kind,
            $destination,
            (string) ($target['prefix'] ?? $target['key'] ?? ''),
            isset($target['master_name']) ? (string) $target['master_name'] : null,
            (string) $target['id'],
        );
    }

    /**
     * @param  array<string, mixed>  $error
     * @return list<array{generation: string, target_id: string, object_key: string}>
     */
    private function windDown(string $id, string $state, array $error): array
    {
        $landed = [];
        $owner = FakeArtifactLedger::jobOwner($id);

        foreach ($this->artifacts->generations() as [$generationOwner, $generation, $generationState]) {
            if ($generationOwner !== $owner || $generationState !== ArtifactStatus::STATE_FINALIZED) {
                continue;
            }

            $landed = [...$landed, ...$this->artifacts->landedObjects($generation)];
            $this->artifacts->fileNodePlan($owner, $generation, []);
        }

        $this->landed[$id] = $landed;

        $this->jobs[$id]['status'] = array_replace($this->jobs[$id]['status'], [
            'state' => $state,
            'completed_at' => FakeArtifactLedger::NOW,
            'progress' => ['percent' => 0, 'stage' => null, 'speed' => 0, 'eta_seconds' => null],
            'error' => $error,
        ]);

        return $landed;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $fields
     */
    private function transition(string $id, array $from, array $fields): void
    {
        $status = $this->row($id)['status'];

        if (! in_array($status['state'], $from, true)) {
            throw InvalidConfigurationException::invalid('job', "{$id} is {$status['state']}, not ".implode(' or ', $from));
        }

        $this->jobs[$id]['status'] = array_replace($status, $fields);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function guardActive(array $status): void
    {
        if (in_array($status['state'], JobStatus::TERMINAL_STATES, true)) {
            throw InvalidConfigurationException::invalid('job', "{$status['id']} is {$status['state']} already");
        }
    }

    /**
     * @return array{status: array<string, mixed>, targets: array<string, list<array<string, mixed>>>}
     */
    private function row(string $id): array
    {
        return $this->jobs[$id] ?? throw self::error(404, 'not_found', "No job {$id} on this node.");
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private static function accepted(array $status): array
    {
        return array_intersect_key($status, array_flip(['id', 'state', 'priority', 'submitted_at', 'idempotency_key']));
    }

    /**
     * The report URL of the first told target, `s3://<bucket>/<key>`, as the node
     * writes it: a configured destination's bucket is its name in the fake.
     */
    /**
     * @param  array<string, mixed>  $target
     */
    private static function url(string $output, array $target): string
    {
        $signed = is_array($target['signed'] ?? null) ? $target['signed'] : [];
        $bucket = (string) ($signed['bucket'] ?? $target['destination_ref'] ?? '');
        $location = isset($target['key']) ? (string) $target['key'] : rtrim((string) ($target['prefix'] ?? ''), '/');

        if ($output === Spec::OUTPUT_HLS_LADDER) {
            $master = isset($target['master_name']) ? (string) $target['master_name'] : ArtifactStatus::MASTER_NAMES[ArtifactStatus::KIND_JOB_HLS];
            $location = $location === '' ? $master : $location.'/'.$master;
        }

        return "s3://{$bucket}/{$location}";
    }

    private static function error(int $status, string $code, string $message): TmesisException
    {
        return ErrorMap::toException($status, ErrorResponse::fromArray(['error' => [
            'code' => $code,
            'message' => $message,
            'details' => null,
            'request_id' => '',
            'milestone' => null,
        ]]));
    }
}
