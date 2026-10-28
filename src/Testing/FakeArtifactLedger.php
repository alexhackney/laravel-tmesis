<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Artifacts\ArtifactPage;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryEntry;
use AlexHackney\Tmesis\Data\Artifacts\InventoryPage;
use AlexHackney\Tmesis\Data\Artifacts\MasterInfo;
use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\MasterTarget;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\PlanTargetStatus;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use AlexHackney\Tmesis\Data\ErrorResponse;
use AlexHackney\Tmesis\Exceptions\ErrorMap;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * FakeTmesis's in-memory artifact ledger: generations, plan revisions, transfer
 * objects and decisions, with the cheap rules of the node's journal applied in the
 * node's order (`internal/journal/artifactplan.go` and `artifactdecision.go`).
 *
 * Modelled: finalized and abandoned generations, plan revisions with the stale,
 * duplicate and conflict rules, one single-PUT transfer object per inventory path and
 * target, per-target completion and failure toggles, release, override and discard
 * with the waiver rules, and the abandoned discard; since tmesis `v0.8.0` the operator
 * purge (recorded on the decision, with its hold and conflict rules) and the told
 * master (a `recording_master` generation queued from a stream's finalized ingest
 * sources, which hold `master_pending` until the test finishes or fails it). Not
 * modelled: multipart (every parts page is empty), retention clocks (retention never
 * elapses), retirement (a purged generation drops its retention hold and is otherwise
 * left as it was), the remux itself, and authorization refresh beyond the binding
 * checks.
 *
 * OWNERS: a generation belongs to a stream or, since tmesis `v0.6.0`, to a transcode
 * job. Every method takes the OWNER where the node's route takes the stream: a stream
 * id, or jobOwner($jobId) for a job generation, which is what FakeJobArtifactsResource
 * passes. The two can never collide (a stream id has no `:`), and a generation under
 * the other owner is the same `404` as at the node.
 *
 * SECRETS: nothing here holds a signed URL or header. A plan's initial authorizations
 * and a refresh's bundle are read for their bindings and the operations they supply,
 * and a refresh is remembered as a hash for the retry rule, never as a value.
 *
 * @internal reached through FakeTmesis and FakeArtifactsResource
 */
final class FakeArtifactLedger
{
    /** Every timestamp the fake writes: it has no clock. */
    public const NOW = '2026-01-01T00:00:00.000Z';

    /** An abandoned generation's discard deadline: its session's end plus 24 hours. */
    private const ABANDONED_DEADLINE = '2026-01-02T00:00:00.000Z';

    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 1000;

    private const MIB = 1048576;

    private const JOB_OWNER = 'job:';

    /** The kinds a stream's generations take. */
    private const STREAM_KINDS = [ArtifactStatus::KIND_HLS_SEALED, ArtifactStatus::KIND_RECORDING_INGEST, ArtifactStatus::KIND_RECORDING_MASTER];

    /**
     * @var array<string, array{stream: string, status: array<string, mixed>, inventory: list<array<string, mixed>>, labels: array<string, string>}>
     */
    private array $generations = [];

    /**
     * Plan revisions per generation, indexed by revision; revision 0 is the baseline.
     *
     * @var array<string, array<int, array{request_id: string, targets: list<array<string, mixed>>}>>
     */
    private array $plans = [];

    /** @var array<string, array<string, array{hash: string, revision: int}>> */
    private array $planRequests = [];

    /**
     * Transfer objects per generation and revision.
     *
     * @var array<string, array<int, list<array<string, mixed>>>>
     */
    private array $objects = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $decisions = [];

    /** @var array<string, array<string, array{hash: string, revision: int}>> */
    private array $decisionRequests = [];

    /**
     * Accepted master requests per stream, by request id: the request hash and the
     * generation it made.
     *
     * @var array<string, array<string, array{hash: string, generation: string}>>
     */
    private array $masterRequests = [];

    /**
     * The told target of each master generation, kept to file as its plan revision 1
     * when the master completes. A PlanTarget of a master carries no credential:
     * MasterRequest refuses initial authorizations.
     *
     * @var array<string, PlanTarget>
     */
    private array $masterTargets = [];

    /**
     * The hash of the accepted bundle per object and authorization revision, for the
     * retry rule. A hash, never the bundle: the bundle is a credential.
     *
     * @var array<string, string>
     */
    private array $authorizations = [];

    /**
     * The owner key of a job's generations.
     */
    public static function jobOwner(string $job): string
    {
        return self::JOB_OWNER.$job;
    }

    /**
     * A FINALIZED job generation (tmesis `v0.6.0`): no stream, session or epoch, the job
     * instead, and a baseline plan like any other. `$kind` is one of
     * `ArtifactStatus::JOB_KINDS`.
     *
     * @param  list<InventoryEntry|array<string, mixed>>|null  $inventory
     * @param  array<string, string>  $labels
     */
    public function seedJobFinalized(string $job, string $generation, string $kind, ?array $inventory, ?string $inventorySha256, array $labels, int $retentionHours): void
    {
        if (! in_array($kind, ArtifactStatus::JOB_KINDS, true)) {
            throw InvalidConfigurationException::invalid('kind', 'a job generation is one of '.implode(', ', ArtifactStatus::JOB_KINDS));
        }

        $this->seed(self::jobOwner($job), $generation, $kind, $inventory, $inventorySha256, $labels, [
            'stream' => null,
            'session' => null,
            'session_epoch' => null,
            'job_id' => $job,
        ], $retentionHours);
    }

    /**
     * @param  list<InventoryEntry|array<string, mixed>>|null  $inventory
     * @param  array<string, string>  $labels
     */
    public function seedFinalized(
        string $stream,
        string $generation,
        string $kind,
        ?array $inventory,
        ?string $inventorySha256,
        array $labels,
        ?string $session,
        int $sessionEpoch,
        int $retentionHours,
    ): void {
        if (! in_array($kind, self::STREAM_KINDS, true)) {
            throw InvalidConfigurationException::invalid('kind', 'a stream generation is one of '.implode(', ', self::STREAM_KINDS).'; seed a job generation with artifactFinalizedForJob()');
        }

        $this->seed($stream, $generation, $kind, $inventory, $inventorySha256, $labels, [
            'stream' => $stream,
            'session' => $session ?? (string) Str::ulid(),
            'session_epoch' => $sessionEpoch,
            'job_id' => null,
        ], $retentionHours);
    }

    /**
     * @param  list<InventoryEntry|array<string, mixed>>|null  $inventory
     * @param  array<string, string>  $labels
     * @param  array{stream: ?string, session: ?string, session_epoch: ?int, job_id: ?string}  $identity
     */
    private function seed(string $owner, string $generation, string $kind, ?array $inventory, ?string $inventorySha256, array $labels, array $identity, int $retentionHours): void
    {
        $entries = self::inventory($kind, $inventory);
        usort($entries, static fn (array $a, array $b): int => strcmp(self::str($a, 'path'), self::str($b, 'path')));

        $sha = $inventorySha256 ?? hash('sha256', (string) json_encode($entries));
        $recording = in_array($kind, [ArtifactStatus::KIND_RECORDING_INGEST, ArtifactStatus::KIND_RECORDING_MASTER], true);

        $this->forget($generation);

        $this->generations[$generation] = [
            'stream' => $owner,
            'labels' => $labels,
            'inventory' => $entries,
            'status' => [
                'id' => $generation,
                ...$identity,
                'kind' => $kind,
                'recording_id' => $recording ? $generation : null,
                'state' => ArtifactStatus::STATE_FINALIZED,
                'inventory_sha256' => $sha,
                'object_count' => count($entries),
                'size_bytes' => array_sum(array_map(static fn (array $entry): int => self::int($entry, 'size_bytes'), $entries)),
                'finalized_at' => self::NOW,
                'registered_at' => $recording ? self::NOW : null,
                'retention_hours' => $retentionHours,
                'retention_expires_at' => self::plusHours($retentionHours),
                'created_at' => self::NOW,
                'master' => null,
                'purge_requested_at' => null,
            ],
        ];

        $this->plans[$generation] = [0 => ['request_id' => 'baseline', 'targets' => []]];
        $this->objects[$generation] = [0 => []];
    }

    /**
     * An `hls_sealed` generation that never finalized and whose session has ended.
     *
     * @param  array<string, string>  $labels
     */
    public function seedAbandoned(string $stream, string $generation, array $labels, ?string $session, int $sessionEpoch): void
    {
        $this->forget($generation);

        $this->generations[$generation] = [
            'stream' => $stream,
            'labels' => $labels,
            'inventory' => [],
            'status' => [
                'id' => $generation,
                'stream' => $stream,
                'session' => $session ?? (string) Str::ulid(),
                'session_epoch' => $sessionEpoch,
                'job_id' => null,
                'kind' => ArtifactStatus::KIND_HLS_SEALED,
                'recording_id' => null,
                'state' => ArtifactStatus::STATE_HELD,
                'inventory_sha256' => null,
                'object_count' => 0,
                'size_bytes' => 0,
                'finalized_at' => null,
                'registered_at' => null,
                'retention_hours' => 24,
                'retention_expires_at' => null,
                'created_at' => self::NOW,
                'master' => null,
                'purge_requested_at' => null,
            ],
        ];
    }

    /**
     * The `ArtifactIdentity` block the `artifact.*` and `transfer.*` events carry.
     *
     * @return array<string, mixed>
     */
    public function identity(string $stream, string $generation): array
    {
        $row = $this->row($stream, $generation);

        return [
            'stream' => $row['status']['stream'],
            'session' => $row['status']['session'],
            'session_epoch' => $row['status']['session_epoch'],
            'job_id' => $row['status']['job_id'] ?? null,
            'generation' => $generation,
            'kind' => $row['status']['kind'],
            'inventory_sha256' => $row['status']['inventory_sha256'] ?? '',
            'labels' => $row['labels'],
        ];
    }

    /**
     * Put every object of one target on the current revision into `$state`.
     */
    public function settleTarget(string $stream, string $generation, string $targetId, string $state, ?string $errorCode = null): void
    {
        $this->row($stream, $generation);
        $revision = $this->currentRevision($generation);

        if ($revision === null) {
            throw InvalidConfigurationException::invalid('generation', "{$generation} is not finalized and has no transfers");
        }

        $found = false;

        foreach ($this->objects[$generation][$revision] as $i => $object) {
            if (self::str($object, 'target_id') !== $targetId) {
                continue;
            }

            $found = true;

            $this->objects[$generation][$revision][$i] = array_replace($object, $state === ObjectStatus::STATE_COMPLETE
                ? [
                    'state' => ObjectStatus::STATE_COMPLETE,
                    'authorization_required' => [],
                    'etag' => '"'.substr(self::str($object, 'checksum_sha256'), 0, 32).'"',
                    'completed_at' => self::NOW,
                    'error' => null,
                ]
                : [
                    'state' => ObjectStatus::STATE_FAILED,
                    'completed_at' => null,
                    'error' => ['code' => $errorCode ?? 'attempts_exhausted', 'message' => 'failed in FakeTmesis'],
                ]);
        }

        if (! $found) {
            throw InvalidConfigurationException::invalid('target', "{$targetId} is not a target of revision {$revision} of {$generation}");
        }

        $this->registerByTransfer($generation, $revision, $targetId);
    }

    /**
     * A master registers when its registration target, the FIRST target of its current
     * plan, completes (tmesis `v0.8.0`, `registration_mode: transfer`, the journal's
     * registerByTransferTx): `registered_at` and the retention deadline are set then,
     * and never before.
     */
    private function registerByTransfer(string $generation, int $revision, string $targetId): void
    {
        $status = $this->generations[$generation]['status'];

        if ($status['kind'] !== ArtifactStatus::KIND_RECORDING_MASTER
            || ($status['registered_at'] ?? null) !== null
            || self::str($this->plans[$generation][$revision]['targets'][0] ?? [], 'id') !== $targetId
            || in_array($targetId, $this->incompleteTargets($generation, $revision), true)) {
            return;
        }

        $status['registered_at'] = self::NOW;
        $status['retention_expires_at'] = self::plusHours(self::int($status, 'retention_hours'));
        $this->generations[$generation]['status'] = $status;
    }

    /**
     * The decision on the generation's current revision (or an abandoned discard),
     * null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function currentDecision(string $generation): ?array
    {
        $revision = $this->currentRevision($generation) ?? 0;

        return $this->decisions[$generation][$revision] ?? null;
    }

    /**
     * Every seeded generation, as `[owner, generation, state]`: the owner is the
     * stream, or jobOwner() for a job generation.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function generations(): array
    {
        $out = [];

        foreach ($this->generations as $id => $row) {
            $out[] = [$row['stream'], $id, self::str($row['status'], 'state')];
        }

        return $out;
    }

    public function knows(string $stream, string $generation): bool
    {
        return ($this->generations[$generation]['stream'] ?? null) === $stream;
    }

    public function listArtifacts(string $stream, ?int $limit, ?string $cursor): ArtifactPage
    {
        $items = [];

        foreach ($this->generations as $id => $row) {
            if ($row['stream'] === $stream) {
                $items[] = $this->status($id);
            }
        }

        usort($items, static fn (array $a, array $b): int => [self::str($a, 'created_at'), self::str($a, 'id')] <=> [self::str($b, 'created_at'), self::str($b, 'id')]);

        return ArtifactPage::fromArray(self::page($items, $limit, $cursor, "artifacts|{$stream}"));
    }

    public function getArtifact(string $stream, string $generation): ArtifactStatus
    {
        $this->row($stream, $generation);

        return ArtifactStatus::fromArray($this->status($generation));
    }

    public function getInventory(string $stream, string $generation, ?int $limit, ?string $cursor): InventoryPage
    {
        $row = $this->finalized($stream, $generation);

        return InventoryPage::fromArray(self::page($row['inventory'], $limit, $cursor, "inventory|{$stream}|{$generation}"));
    }

    public function getPlan(string $stream, string $generation): PlanStatus
    {
        $this->finalized($stream, $generation);

        return PlanStatus::fromArray($this->planStatus($generation, (int) $this->currentRevision($generation)));
    }

    public function listTransfers(string $stream, string $generation, ?int $limit, ?string $cursor): TransferPage
    {
        $this->finalized($stream, $generation);
        $revision = (int) $this->currentRevision($generation);

        $items = $this->objects[$generation][$revision];
        usort($items, static fn (array $a, array $b): int => [self::str($a, 'target_id'), self::str($a, 'object_key')] <=> [self::str($b, 'target_id'), self::str($b, 'object_key')]);

        return TransferPage::fromArray(self::page($items, $limit, $cursor, "transfers|{$stream}|{$generation}", $revision));
    }

    /**
     * Multipart is not modelled: every object is a single PUT and has no parts.
     */
    public function listParts(string $stream, string $generation, string $objectId, ?int $limit, ?string $cursor): PartPage
    {
        $this->row($stream, $generation);
        $this->object($generation, $objectId);

        return PartPage::fromArray(self::page([], $limit, $cursor, "parts|{$stream}|{$generation}|{$objectId}"));
    }

    /**
     * A refresh, checked for its bindings and remembered as a revision and a hash.
     */
    public function authorize(string $stream, string $generation, string $objectId, AuthorizationRequest $authorization): ObjectStatus
    {
        $row = $this->row($stream, $generation);
        [$revision, $index, $object] = $this->object($generation, $objectId);

        if ($row['status']['state'] !== ArtifactStatus::STATE_FINALIZED) {
            throw self::conflict('artifact_not_finalized', "The generation {$generation} is not finalized.");
        }

        $target = $this->target($generation, $revision, self::str($object, 'target_id'));

        // The node's binding checks (transfer/signed.go validate), in its order and
        // all one code, before the journal's stale check.
        if (self::str($target, 'authorization') !== PlanTargetStatus::AUTHORIZATION_SIGNED) {
            throw self::conflict('transfer_identity_mismatch', "The target of object {$objectId} is not signed.");
        }

        $mismatch = match (true) {
            $authorization->planRevision() !== $revision => 'plan revision',
            $authorization->inventorySha256() !== $row['status']['inventory_sha256'] => 'inventory',
            $authorization->targetId() !== self::str($object, 'target_id') => 'target',
            $authorization->objectKey() !== self::str($object, 'object_key') => 'object key',
            $authorization->multipartUploadId() !== ($object['multipart_upload_id'] ?? null) => 'multipart upload',
            default => null,
        };

        if ($mismatch !== null) {
            throw self::conflict('transfer_identity_mismatch', "The refresh for object {$objectId} names another {$mismatch}.");
        }

        if ($revision !== $this->currentRevision($generation)) {
            throw self::conflict('transfer_plan_stale', "The object {$objectId} is not on the current plan revision.");
        }

        $current = self::int($object, 'authorization_revision');
        $hash = self::bundleHash($authorization->requests());
        $key = $objectId.'|'.$authorization->authorizationRevision();

        if ($authorization->authorizationRevision() === $current && $current > 0) {
            if (($this->authorizations[$key] ?? null) !== $hash) {
                throw self::conflict('request_conflict', "Authorization revision {$current} of object {$objectId} was accepted with another bundle.");
            }

            return ObjectStatus::fromArray($object);
        }

        if ($authorization->authorizationRevision() !== $current + 1) {
            throw self::conflict('transfer_identity_mismatch', "Object {$objectId} is at authorization revision {$current}; send ".($current + 1).'.');
        }

        $this->authorizations[$key] = $hash;

        $object = array_replace($object, [
            'authorization_revision' => $current + 1,
            'authorization_available' => true,
        ]);

        if ($object['state'] === ObjectStatus::STATE_WAITING_AUTHORIZATION) {
            $object = array_replace($object, ['state' => ObjectStatus::STATE_QUEUED, 'authorization_required' => [], 'error' => null]);
        }

        $this->objects[$generation][$revision][$index] = $object;

        return ObjectStatus::fromArray($object);
    }

    /**
     * A plan revision, in the node's order: the request-alone checks, then the
     * journal's (the request id, the generation's identity, the stale guard, the
     * targets).
     */
    public function replacePlan(string $stream, string $generation, PlanRequest $plan): PlanStatus
    {
        $row = $this->row($stream, $generation);
        self::checkInitialBindings($plan);
        $hash = self::planHash($plan);

        $prior = $this->planRequests[$generation][$plan->requestId()] ?? null;

        if ($prior !== null) {
            if ($prior['hash'] !== $hash) {
                throw self::conflict('request_conflict', "The request {$plan->requestId()} was accepted with another body.");
            }

            return PlanStatus::fromArray($this->planStatus($generation, $prior['revision']));
        }

        if ($row['status']['state'] !== ArtifactStatus::STATE_FINALIZED) {
            throw self::conflict('artifact_not_finalized', "The generation {$generation} is not finalized.");
        }

        if ($plan->inventorySha256() !== $row['status']['inventory_sha256']) {
            throw self::conflict('artifact_identity_mismatch', "The plan names another inventory than generation {$generation}'s.");
        }

        $current = (int) $this->currentRevision($generation);

        if ($plan->expectedRevision() !== $current) {
            throw self::conflict('transfer_plan_stale', "Expected revision {$plan->expectedRevision()}, current is {$current}.");
        }

        $revision = $current + 1;
        $targets = [];
        $objects = [];

        foreach ($plan->targets() as $i => $target) {
            if ($target->artifact() !== $row['status']['kind']) {
                throw self::invalid("targets[{$i}].artifact", "must be the generation's kind, {$row['status']['kind']}");
            }

            $targets[] = self::targetStatus($target);
            $objects = [...$objects, ...$this->objectsFor($generation, $revision, $i, $target, $row['inventory'])];
        }

        $this->plans[$generation][$revision] = ['request_id' => $plan->requestId(), 'targets' => $targets];
        $this->objects[$generation][$revision] = $objects;
        $this->planRequests[$generation][$plan->requestId()] = ['hash' => $hash, 'revision' => $revision];

        return PlanStatus::fromArray($this->planStatus($generation, $revision), created: $revision === 1);
    }

    /**
     * File a revision the NODE writes, with no request to check: a job generation's
     * revision 1 from the Spec's targets at finalization, and the empty revision that
     * supersedes a failed or cancelled job's plans (tmesis `v0.6.0`, `docs/API.md` 9.12).
     *
     * @param  list<PlanTarget>  $targets
     */
    public function fileNodePlan(string $owner, string $generation, array $targets): int
    {
        $row = $this->finalized($owner, $generation);
        $revision = (int) $this->currentRevision($generation) + 1;
        $snapshots = [];
        $objects = [];

        foreach ($targets as $i => $target) {
            $snapshots[] = self::targetStatus($target);
            $objects = [...$objects, ...$this->objectsFor($generation, $revision, $i, $target, $row['inventory'])];
        }

        $this->plans[$generation][$revision] = ['request_id' => (string) Str::ulid(), 'targets' => $snapshots];
        $this->objects[$generation][$revision] = $objects;

        return $revision;
    }

    /**
     * The target ids of the current revision, keyed by id.
     *
     * @return array<string, true>
     */
    public function currentTargets(string $generation): array
    {
        $revision = $this->currentRevision($generation);
        $out = [];

        foreach ($revision === null ? [] : $this->plans[$generation][$revision]['targets'] as $target) {
            $out[self::str($target, 'id')] = true;
        }

        return $out;
    }

    /**
     * Whether every object of the current revision is complete (an empty revision
     * is): what a job output's `completed` state means.
     */
    public function landed(string $generation): bool
    {
        $revision = $this->currentRevision($generation);

        return $revision !== null && $this->incompleteTargets($generation, $revision) === [];
    }

    /**
     * The objects of the current revision already complete, as the `landed` entries of
     * `job.failed`.
     *
     * @return list<array{generation: string, target_id: string, object_key: string}>
     */
    public function landedObjects(string $generation): array
    {
        $revision = $this->currentRevision($generation);
        $out = [];

        foreach ($revision === null ? [] : $this->objects[$generation][$revision] as $object) {
            if ($object['state'] === ObjectStatus::STATE_COMPLETE) {
                $out[] = ['generation' => $generation, 'target_id' => self::str($object, 'target_id'), 'object_key' => self::str($object, 'object_key')];
            }
        }

        return $out;
    }

    public function release(string $stream, string $generation, ReleaseRequest $release): DecisionStatus
    {
        return $this->decide($stream, $generation, 'release', $release->toArray(), DecisionStatus::ACTION_RELEASE, []);
    }

    public function override(string $stream, string $generation, OverrideRequest $override): DecisionStatus
    {
        return $this->decide($stream, $generation, 'override', $override->toArray(), $override->action(), $override->waivedTargets());
    }

    /**
     * The operator purge (tmesis `v0.8.0`, `docs/API.md` 12a.5b), in the node's order:
     * an exact duplicate answers the stored decision, a source of a pending master is
     * `409 artifact_held`, a decision already purged is `409 release_conflict`, an
     * undecided held generation named with a revision or an inventory is `409
     * artifact_identity_mismatch`, a
     * decision already recorded takes the purge if the body names its revision and
     * inventory, and otherwise the purge records the release (or the abandoned
     * discard, for revision 0 and an empty inventory) with every rule a release has.
     */
    public function purge(string $stream, string $generation, ReleaseRequest $purge): DecisionStatus
    {
        $row = $this->row($stream, $generation);
        $body = $purge->toArray();
        $hash = hash('sha256', 'purge'.(string) json_encode($body));
        $prior = $this->decisionRequests[$generation][$purge->requestId()] ?? null;

        if ($prior !== null) {
            if ($prior['hash'] !== $hash) {
                throw self::conflict('request_conflict', "The request {$purge->requestId()} was accepted with another body.");
            }

            return DecisionStatus::fromArray($this->decisionStatus($generation, $prior['revision']), recorded: false);
        }

        if ($this->pendingMasterOf($generation) !== null) {
            throw self::error(409, 'artifact_held', "A hold no purge removes stands on {$generation}.", [['path' => 'hold_reasons', 'message' => 'master_pending']]);
        }

        $abandoned = $row['status']['state'] !== ArtifactStatus::STATE_FINALIZED;
        $revision = $abandoned ? 0 : (int) $this->currentRevision($generation);
        $decision = $this->decisions[$generation][$revision] ?? null;

        if ($decision !== null && ($decision['purge_requested_at'] ?? null) !== null) {
            throw self::conflict('release_conflict', "The generation {$generation} was already purged under another request.");
        }

        // A held generation's purge is bound to revision 0 and no inventory, checked
        // before whether it is abandoned (tmesis journal PurgeArtifact, then abandonTx).
        if ($decision === null && $abandoned && ($purge->planRevision() !== 0 || $purge->inventorySha256() !== '')) {
            throw self::conflict('artifact_identity_mismatch', "A held generation is purged with plan_revision 0 and no inventory_sha256; {$generation} is held.");
        }

        if ($decision === null) {
            $this->decide($stream, $generation, 'purge', $body, $abandoned ? DecisionStatus::ACTION_DISCARD : DecisionStatus::ACTION_RELEASE, []);
        } elseif ($purge->planRevision() !== $revision) {
            throw self::conflict('transfer_plan_stale', "The purge names revision {$purge->planRevision()}, current is {$revision}.");
        } elseif ($purge->inventorySha256() !== ($row['status']['inventory_sha256'] ?? '')) {
            throw self::conflict('artifact_identity_mismatch', "The purge names another inventory than generation {$generation}'s.");
        }

        $this->decisions[$generation][$revision]['purge_requested_at'] = self::NOW;
        $this->decisions[$generation][$revision]['purge_reason'] = $purge->reason();
        $this->decisionRequests[$generation][$purge->requestId()] = ['hash' => $hash, 'revision' => $revision];

        return DecisionStatus::fromArray($this->decisionStatus($generation, $revision), recorded: true);
    }

    /**
     * POST /streams/{id}/masters (tmesis `v0.8.0`, `docs/API.md` 12a.8): a new
     * `recording_master` generation, held, its master `queued`, the sources in join
     * order (here the order they were seeded, which stands for their recordings'
     * start). An exact retry answers the generation as it is now; the request id with
     * another body is `409 request_conflict`. Each source must be a FINALIZED
     * `recording_ingest` generation of this stream, listed once and not purged
     * (`422 invalid_source` naming each, or `409 artifact_not_finalized` for one still
     * held). A stream the fake neither holds (`$streamKnown`) nor has a generation on
     * is `404`, before anything else, as the node's streamMustExist.
     */
    public function createMaster(string $stream, MasterRequest $master, bool $streamKnown = true): ArtifactStatus
    {
        if (! $streamKnown && ! $this->ownsAny($stream)) {
            throw self::error(404, 'not_found', "No such stream [{$stream}].");
        }

        $hash = hash('sha256', (string) json_encode(self::redactMaster($master)));
        $prior = $this->masterRequests[$stream][$master->requestId()] ?? null;

        if ($prior !== null) {
            if ($prior['hash'] !== $hash) {
                throw self::conflict('request_conflict', "The request {$master->requestId()} was used on stream {$stream} with another body.");
            }

            return ArtifactStatus::fromArray($this->status($prior['generation']), created: false);
        }

        $details = [];

        foreach ($master->sources() as $i => $source) {
            $row = $this->generations[$source] ?? null;
            $why = match (true) {
                $row === null => 'unknown',
                $row['stream'] !== $stream => 'another stream',
                $row['status']['kind'] !== ArtifactStatus::KIND_RECORDING_INGEST => 'not recording_ingest',
                array_search($source, $master->sources(), true) !== $i => 'listed twice',
                (($this->currentDecision($source) ?? [])['purge_requested_at'] ?? null) !== null => 'retirement has begun',
                default => null,
            };

            if ($why !== null) {
                $details[] = ['path' => "sources[{$i}]", 'message' => $why];
            }
        }

        if ($details !== []) {
            throw self::error(422, 'invalid_source', 'one or more sources cannot form this stream\'s master', $details);
        }

        foreach ($master->sources() as $source) {
            $this->finalized($stream, $source);
        }

        $joined = array_values(array_filter(array_keys($this->generations), static fn (string $id): bool => in_array($id, $master->sources(), true)));
        $generation = (string) Str::ulid();
        $first = $this->generations[$joined[0]]['status'];

        $this->generations[$generation] = [
            'stream' => $stream,
            'labels' => $this->generations[$joined[0]]['labels'],
            'inventory' => [],
            'status' => [
                'id' => $generation,
                'stream' => $stream,
                'session' => $first['session'],
                'session_epoch' => $first['session_epoch'],
                'job_id' => null,
                'kind' => ArtifactStatus::KIND_RECORDING_MASTER,
                'recording_id' => $generation,
                'state' => ArtifactStatus::STATE_HELD,
                'inventory_sha256' => null,
                'object_count' => 0,
                'size_bytes' => 0,
                'finalized_at' => null,
                'registered_at' => null,
                'retention_hours' => self::int($first, 'retention_hours'),
                'retention_expires_at' => null,
                'created_at' => self::NOW,
                'master' => [
                    'request_id' => $master->requestId(),
                    'state' => MasterInfo::STATE_QUEUED,
                    'sources' => $joined,
                    'capture_container' => str_ends_with(self::str($this->generations[$joined[0]]['inventory'][0] ?? [], 'path'), '.flv') ? 'flv' : 'mpegts',
                    'target' => self::masterTarget($master->target()),
                    'reason' => $master->reason() ?? '',
                    'error' => null,
                ],
                'purge_requested_at' => null,
            ],
        ];

        $this->masterTargets[$generation] = $master->target();
        $this->masterRequests[$stream][$master->requestId()] = ['hash' => $hash, 'generation' => $generation];

        return ArtifactStatus::fromArray($this->status($generation), created: true);
    }

    /**
     * Move a queued or running master on: `running`, `completed` (finalized with one
     * `master.mp4`, and its told target filed as plan revision 1 as the node does) or
     * `failed` with `$error`. Its sources lose `master_pending` once it is finished.
     */
    public function advanceMaster(string $stream, string $generation, string $state, ?string $error = null): void
    {
        $row = $this->row($stream, $generation);
        $master = $row['status']['master'] ?? null;

        if (! is_array($master) || ! in_array($master['state'] ?? null, [MasterInfo::STATE_QUEUED, MasterInfo::STATE_RUNNING], true)) {
            throw InvalidConfigurationException::invalid('generation', "{$generation} is not a queued or running master");
        }

        if (! in_array($state, MasterInfo::STATES, true) || $state === MasterInfo::STATE_QUEUED) {
            throw InvalidConfigurationException::invalid('state', 'a master moves to running, completed or failed');
        }

        $master['state'] = $state;
        $master['error'] = $state === MasterInfo::STATE_FAILED ? ($error ?? 'failed in FakeTmesis') : null;
        $row['status']['master'] = $master;

        if ($state !== MasterInfo::STATE_COMPLETED) {
            $this->generations[$generation] = $row;

            return;
        }

        $entries = self::inventory(ArtifactStatus::KIND_RECORDING_MASTER, [['path' => 'master.mp4', 'publish_order' => InventoryEntry::ORDER_MEDIA]]);

        $row['inventory'] = $entries;
        $row['status'] = array_replace($row['status'], [
            'state' => ArtifactStatus::STATE_FINALIZED,
            'inventory_sha256' => hash('sha256', (string) json_encode($entries)),
            'object_count' => count($entries),
            'size_bytes' => array_sum(array_map(static fn (array $entry): int => self::int($entry, 'size_bytes'), $entries)),
            'finalized_at' => self::NOW,
            // registration_mode `transfer`: the master registers when its told target
            // (revision 1's first) completes, and its deadline is UNKNOWN until then
            // (settleTarget(); the node's registerByTransferTx).
            'registered_at' => null,
            'retention_expires_at' => null,
        ]);
        $this->generations[$generation] = $row;
        $this->plans[$generation] = [0 => ['request_id' => 'baseline', 'targets' => []]];
        $this->objects[$generation] = [0 => []];

        $this->fileNodePlan($stream, $generation, [$this->masterTargets[$generation]]);
    }

    /**
     * Every master requested on the stream, as recorded: the request body with a
     * signed target reduced to its bucket fields, and the generation it made.
     *
     * @return list<array{generation: string, request: array<string, mixed>}>
     */
    public function masters(string $stream): array
    {
        $out = [];

        foreach ($this->masterRequests[$stream] ?? [] as $request) {
            $master = $this->generations[$request['generation']]['status']['master'] ?? [];
            $out[] = ['generation' => $request['generation'], 'request' => is_array($master) ? $master : []];
        }

        return $out;
    }

    /**
     * A master request as the call log keeps it. A master's signed target carries no
     * authorizations (MasterRequest refuses them), so the body is already free of
     * credentials; this is the one place that is asserted.
     *
     * @return array<string, mixed>
     */
    public static function redactMaster(MasterRequest $master): array
    {
        $body = $master->toArray();

        if (is_array($body['target']['signed'] ?? null)) {
            unset($body['target']['signed']['authorizations']);
        }

        return $body;
    }

    /**
     * Whether any generation is seeded under the stream (or job owner).
     */
    private function ownsAny(string $owner): bool
    {
        foreach ($this->generations as $row) {
            if ($row['stream'] === $owner) {
                return true;
            }
        }

        return false;
    }

    /**
     * The queued or running master a generation is a source of, if any.
     */
    private function pendingMasterOf(string $generation): ?string
    {
        foreach ($this->generations as $id => $row) {
            $master = $row['status']['master'] ?? null;

            if (is_array($master)
                && in_array($master['state'] ?? null, [MasterInfo::STATE_QUEUED, MasterInfo::STATE_RUNNING], true)
                && in_array($generation, (array) ($master['sources'] ?? []), true)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The resolved, secret-free MasterTarget for a told target: the plan's own
     * resolution (targetStatus(), so the node's defaults, 8 uploads and 16 MiB parts,
     * read the same before and after completion) cut to the MasterTarget fields.
     *
     * @return array<string, mixed>
     */
    private static function masterTarget(PlanTarget $target): array
    {
        $resolved = self::targetStatus($target);

        return [
            'id' => $resolved['id'],
            'artifact' => ArtifactStatus::KIND_RECORDING_MASTER,
            'authorization' => $resolved['authorization'],
            'destination_ref' => $resolved['destination_ref'],
            'endpoint' => $resolved['endpoint'],
            'region' => $resolved['region'],
            'bucket' => $resolved['bucket'],
            'credential_profile' => $resolved['credential_profile'],
            'upload_concurrency' => $resolved['upload_concurrency'],
            'addressing' => $resolved['addressing'],
            'part_size_bytes' => $resolved['part_size_bytes'],
            'key' => (string) $resolved['key'],
        ];
    }

    /**
     * A plan with every signed initial authorization reduced to its bindings and the
     * operations it supplies: what the call log keeps, never a URL or a header value.
     *
     * @return array<string, mixed>
     */
    public static function redactPlan(PlanRequest $plan): array
    {
        $body = $plan->toArray();
        $targets = is_array($body['targets'] ?? null) ? array_values($body['targets']) : [];

        foreach ($targets as $i => $row) {
            if (! is_array($row) || ! is_array($row['signed'] ?? null)) {
                continue;
            }

            $authorizations = is_array($row['signed']['authorizations'] ?? null) ? $row['signed']['authorizations'] : null;
            unset($row['signed']['authorizations']);

            if ($authorizations !== null) {
                foreach ($plan->targets()[$i]->signed()?->authorizations() ?? [] as $authorization) {
                    $row['signed']['authorizations'][] = [
                        'artifact_path' => $authorization->artifactPath(),
                        'target_id' => $plan->targets()[$i]->id(),
                        'object_key' => $authorization->objectKey(),
                        'supplied' => $authorization->requests()->supplied(),
                    ];
                }
            }

            $targets[$i] = $row;
        }

        $body['targets'] = $targets;

        return $body;
    }

    /**
     * The node's request-alone checks on initial authorizations
     * (`internal/api/artifacts.go` validateTargetDestination), each a `422` on its
     * path. PlanRequest derives all three, so these are the fake agreeing with the
     * node rather than a path a builder can reach.
     */
    private static function checkInitialBindings(PlanRequest $plan): void
    {
        $body = $plan->toArray();
        $details = [];

        foreach (is_array($body['targets'] ?? null) ? $body['targets'] : [] as $i => $target) {
            $authorizations = is_array($target) && is_array($target['signed'] ?? null) && is_array($target['signed']['authorizations'] ?? null)
                ? $target['signed']['authorizations']
                : [];

            foreach ($authorizations as $j => $authorization) {
                if (! is_array($authorization)) {
                    continue;
                }

                $at = "targets[{$i}].signed.authorizations[{$j}]";

                if (($authorization['plan_revision'] ?? null) !== $plan->expectedRevision() + 1) {
                    $details[] = ['path' => "{$at}.plan_revision", 'message' => 'must be expected_revision plus 1, the revision this plan creates'];
                }

                if (($authorization['inventory_sha256'] ?? null) !== $plan->inventorySha256()) {
                    $details[] = ['path' => "{$at}.inventory_sha256", 'message' => "must be the plan's inventory_sha256"];
                }

                if (($authorization['target_id'] ?? null) !== ($target['id'] ?? null)) {
                    $details[] = ['path' => "{$at}.target_id", 'message' => "must be this target's id"];
                }
            }
        }

        if ($details !== []) {
            throw self::error(422, 'validation_failed', 'the request has field problems', $details);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $waived
     */
    private function decide(string $stream, string $generation, string $route, array $body, string $action, array $waived): DecisionStatus
    {
        $row = $this->row($stream, $generation);
        $hash = hash('sha256', $route.(string) json_encode($body));
        $requestId = self::str($body, 'request_id');
        $revision = self::int($body, 'plan_revision');
        $sha = self::str($body, 'inventory_sha256');

        $prior = $this->decisionRequests[$generation][$requestId] ?? null;

        if ($prior !== null) {
            if ($prior['hash'] !== $hash) {
                throw self::conflict('request_conflict', "The request {$requestId} was accepted with another body.");
            }

            return DecisionStatus::fromArray($this->decisionStatus($generation, $prior['revision']), recorded: false);
        }

        if ($row['status']['state'] !== ArtifactStatus::STATE_FINALIZED) {
            return $this->abandon($generation, $hash, $body, $action, $revision, $sha, $waived);
        }

        if ($sha !== $row['status']['inventory_sha256']) {
            throw self::conflict('artifact_identity_mismatch', "The decision names another inventory than generation {$generation}'s.");
        }

        $current = (int) $this->currentRevision($generation);

        if ($revision !== $current) {
            throw self::conflict('transfer_plan_stale', "The decision names revision {$revision}, current is {$current}.");
        }

        if (isset($this->decisions[$generation][$current])) {
            throw self::conflict('release_conflict', "Revision {$current} of {$generation} already has a decision.");
        }

        $this->checkWaivers($generation, $current, $action, $waived);

        return $this->record($generation, $current, $hash, $body, $action, $waived, self::str($row['status'], 'retention_expires_at'));
    }

    /**
     * An unfinalized generation takes one decision: the discard of the abandoned
     * shape. Release and override, and any other shape, are `artifact_not_finalized`.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $waived
     */
    private function abandon(string $generation, string $hash, array $body, string $action, int $revision, string $sha, array $waived): DecisionStatus
    {
        if ($action !== DecisionStatus::ACTION_DISCARD || $revision !== 0 || $sha !== '') {
            throw self::conflict('artifact_not_finalized', "The generation {$generation} is not finalized; it takes only the abandoned discard.");
        }

        // A held recording_master is abandoned only once its master FAILED (tmesis
        // v0.8.0, journal abandonTx, D35): a queued or running one is still being
        // written, and the node answers its discard or purge `409 artifact_not_finalized`.
        $master = $this->generations[$generation]['status']['master'] ?? null;

        if ($this->generations[$generation]['status']['kind'] === ArtifactStatus::KIND_RECORDING_MASTER
            && (! is_array($master) || ($master['state'] ?? null) !== MasterInfo::STATE_FAILED)) {
            throw self::conflict('artifact_not_finalized', "The master of generation {$generation} has not failed; it is not abandoned.");
        }

        if ($waived !== []) {
            throw self::invalid('waived_targets', 'an abandoned generation has no targets to waive');
        }

        if (isset($this->decisions[$generation][0])) {
            throw self::conflict('release_conflict', "The generation {$generation} already has a decision.");
        }

        $this->generations[$generation]['status']['retention_expires_at'] = self::ABANDONED_DEADLINE;

        return $this->record($generation, 0, $hash, $body, $action, [], self::ABANDONED_DEADLINE);
    }

    /**
     * @param  list<string>  $waived
     */
    private function checkWaivers(string $generation, int $revision, string $action, array $waived): void
    {
        $targets = array_map(static fn (array $target): string => self::str($target, 'id'), $this->plans[$generation][$revision]['targets']);
        $incomplete = $this->incompleteTargets($generation, $revision);

        if (count($waived) !== count(array_unique($waived))) {
            throw self::invalid('waived_targets', 'a target is waived twice');
        }

        foreach ($waived as $id) {
            if (! in_array($id, $targets, true)) {
                throw self::invalid('waived_targets', "target {$id} is not in revision {$revision}");
            }
        }

        if ($action === DecisionStatus::ACTION_RELEASE && $incomplete !== []) {
            throw self::conflict('transfer_incomplete', count($incomplete)." target(s) of revision {$revision} are not complete.");
        }

        $required = $action === DecisionStatus::ACTION_DISCARD ? $targets : $incomplete;

        foreach ($required as $id) {
            if (! in_array($id, $waived, true)) {
                throw self::invalid('waived_targets', $action === DecisionStatus::ACTION_DISCARD
                    ? "a discard waives every target, and omits {$id}"
                    : "incomplete target {$id} must be waived");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $waived
     */
    private function record(string $generation, int $revision, string $hash, array $body, string $action, array $waived, ?string $retention): DecisionStatus
    {
        $requestId = self::str($body, 'request_id');

        $this->decisions[$generation][$revision] = [
            'request_id' => $requestId,
            'generation' => $generation,
            'plan_revision' => $revision,
            'action' => $action,
            'actor' => 'admin_token',
            'reason' => self::str($body, 'reason'),
            'waived_targets' => $waived,
            'current' => true,
            'release_state' => $action === DecisionStatus::ACTION_RELEASE ? ArtifactStatus::RELEASE_RELEASED : ArtifactStatus::RELEASE_OVERRIDDEN,
            'retention_expires_at' => $retention === '' ? null : $retention,
            'created_at' => self::NOW,
            'purge_requested_at' => null,
            'purge_reason' => null,
        ];

        $this->decisionRequests[$generation][$requestId] = ['hash' => $hash, 'revision' => $revision];

        return DecisionStatus::fromArray($this->decisionStatus($generation, $revision), recorded: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionStatus(string $generation, int $revision): array
    {
        $decision = $this->decisions[$generation][$revision] ?? [];
        $decision['current'] = $revision === ($this->currentRevision($generation) ?? 0);

        return $decision;
    }

    /**
     * The generation's status with the fields that move computed from the ledger.
     *
     * @return array<string, mixed>
     */
    private function status(string $generation): array
    {
        $status = $this->generations[$generation]['status'];
        $finalized = $status['state'] === ArtifactStatus::STATE_FINALIZED;
        $revision = $this->currentRevision($generation);
        $decision = $this->currentDecision($generation);

        $purged = ($decision['purge_requested_at'] ?? null) !== null;

        if ($finalized) {
            // A null deadline is UNKNOWN and holds (decision #68): a master before its
            // registration target lands. A purge carries neither retention reason.
            $reasons = $purged ? [] : [($status['retention_expires_at'] ?? null) === null ? 'retention_clock_unknown' : 'retention_not_elapsed'];

            if ($decision === null && $this->incompleteTargets($generation, (int) $revision) !== []) {
                $reasons[] = 'copies_incomplete';
            }

            if ($decision === null) {
                $reasons[] = 'release_required';
            }
        } else {
            $reasons = $decision === null ? ['not_finalized', 'release_required'] : ($purged ? ['not_finalized'] : ['not_finalized', 'retention_not_elapsed']);
        }

        if ($this->pendingMasterOf($generation) !== null) {
            $reasons[] = 'master_pending';
        }

        return [
            ...$status,
            'plan_revision' => $revision,
            'release_state' => self::str($decision ?? [], 'release_state') ?: ArtifactStatus::RELEASE_UNRELEASED,
            'held' => $reasons !== [],
            'hold_reasons' => array_values(array_intersect(ArtifactStatus::HOLD_REASONS, $reasons)),
            'purge_requested_at' => $decision['purge_requested_at'] ?? null,
            'local_present' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planStatus(string $generation, int $revision): array
    {
        $plan = $this->plans[$generation][$revision];
        $objects = $this->objects[$generation][$revision];

        $targets = array_map(fn (array $target): array => [
            ...$target,
            'counts' => self::counts(array_filter($objects, static fn (array $object): bool => self::str($object, 'target_id') === self::str($target, 'id'))),
        ], $plan['targets']);

        return [
            'generation' => $generation,
            'revision' => $revision,
            'request_id' => $plan['request_id'],
            'inventory_sha256' => $this->generations[$generation]['status']['inventory_sha256'],
            'current' => $revision === $this->currentRevision($generation),
            'targets' => $targets,
            'counts' => self::counts($objects),
            'created_at' => self::NOW,
        ];
    }

    /**
     * The target ids of a revision with any object not complete.
     *
     * @return list<string>
     */
    private function incompleteTargets(string $generation, int $revision): array
    {
        $ids = [];

        foreach ($this->objects[$generation][$revision] ?? [] as $object) {
            if ($object['state'] !== ObjectStatus::STATE_COMPLETE) {
                $ids[self::str($object, 'target_id')] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * One object per inventory path for one target. A signed target's objects wait
     * for authorization unless the plan carried an initial one for that path, which
     * is checked the way the node checks it before the commit.
     *
     * @param  list<array<string, mixed>>  $inventory
     * @return list<array<string, mixed>>
     */
    private function objectsFor(string $generation, int $revision, int $i, PlanTarget $target, array $inventory): array
    {
        $keys = [];

        foreach ($inventory as $entry) {
            $keys[self::str($entry, 'path')] = self::objectKey($target, $entry);
        }

        $installed = [];

        foreach ($target->signed()?->authorizations() ?? [] as $j => $authorization) {
            $path = $authorization->artifactPath();
            $at = "targets[{$i}].signed.authorizations[{$j}].artifact_path";

            if (! isset($keys[$path])) {
                throw self::invalid($at, 'not a path of the inventory');
            }

            if (isset($installed[$path])) {
                throw self::invalid($at, 'given twice for one target');
            }

            if ($authorization->objectKey() !== $keys[$path]) {
                throw self::conflict('transfer_identity_mismatch', "The initial authorization for {$path} does not bind to its object.");
            }

            $installed[$path] = true;
        }

        $objects = [];

        foreach ($inventory as $entry) {
            $path = self::str($entry, 'path');
            $waiting = $target->signed() !== null && ! isset($installed[$path]);

            $objects[] = [
                'id' => (string) Str::ulid(),
                'generation' => $generation,
                'plan_revision' => $revision,
                'target_id' => $target->id(),
                'artifact_path' => $path,
                'object_key' => $keys[$path],
                'size_bytes' => self::int($entry, 'size_bytes'),
                'checksum_sha256' => self::str($entry, 'checksum_sha256'),
                'state' => $waiting ? ObjectStatus::STATE_WAITING_AUTHORIZATION : ObjectStatus::STATE_QUEUED,
                'phase' => 'put',
                'multipart_upload_id' => null,
                'part_size_bytes' => null,
                'part_count' => 0,
                'completed_part_count' => 0,
                'completion_body_sha256' => null,
                'authorization_revision' => isset($installed[$path]) ? 1 : 0,
                'authorization_available' => isset($installed[$path]),
                'authorization_required' => $waiting ? ['put'] : [],
                'reconcile_required' => false,
                'etag' => null,
                'provider_checksum' => null,
                'provider_checksum_type' => null,
                'provider_version_id' => null,
                'completed_at' => null,
                'error' => $waiting ? ['code' => 'authorization_missing', 'message' => 'Supply signed requests for this object'] : null,
            ];
        }

        return $objects;
    }

    /**
     * The exact told key: a one-file generation's key, or the prefix joined to the
     * path with an HLS master renamed. The node inserts nothing.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function objectKey(PlanTarget $target, array $entry): string
    {
        if ($target->key() !== null) {
            return $target->key();
        }

        $path = self::str($entry, 'path');

        if (self::int($entry, 'publish_order') === InventoryEntry::ORDER_MASTER) {
            $dir = dirname($path);
            $path = ($dir === '.' ? '' : $dir.'/').($target->masterName() ?? ArtifactStatus::MASTER_NAMES[$target->artifact()] ?? basename($path));
        }

        $prefix = rtrim($target->prefix() ?? '', '/');

        return $prefix === '' ? $path : $prefix.'/'.$path;
    }

    /**
     * The secret-free target snapshot, resolved the way a fake box would: a
     * configured destination's bucket and profile are its name.
     *
     * @return array<string, mixed>
     */
    private static function targetStatus(PlanTarget $target): array
    {
        $signed = $target->signed();

        return [
            'id' => $target->id(),
            'artifact' => $target->artifact(),
            'authorization' => $signed !== null ? PlanTargetStatus::AUTHORIZATION_SIGNED : PlanTargetStatus::AUTHORIZATION_CONFIGURED,
            'destination_ref' => $target->destinationRef(),
            'endpoint' => $signed?->endpoint() ?? '',
            'region' => $signed?->region() ?? '',
            'bucket' => $signed?->bucket() ?? (string) $target->destinationRef(),
            'credential_profile' => $signed !== null ? null : $target->destinationRef(),
            'upload_concurrency' => $signed?->uploadConcurrency() ?? 8,
            'addressing' => $signed?->addressing() ?? 'auto',
            'part_size_bytes' => ($signed?->partSizeMb() ?? 16) * self::MIB,
            'key' => $target->key(),
            'prefix' => $target->prefix(),
            'master_name' => isset(ArtifactStatus::MASTER_NAMES[$target->artifact()]) ? ($target->masterName() ?? ArtifactStatus::MASTER_NAMES[$target->artifact()]) : null,
        ];
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $objects
     * @return array<string, int>
     */
    private static function counts(array $objects): array
    {
        $counts = array_fill_keys(ObjectStatus::STATES, 0);

        foreach ($objects as $object) {
            $counts[self::str($object, 'state')]++;
        }

        return $counts;
    }

    /**
     * The generation's current revision; null while it is not finalized.
     */
    private function currentRevision(string $generation): ?int
    {
        $plans = $this->plans[$generation] ?? [];

        return $plans === [] ? null : max(array_keys($plans));
    }

    /**
     * @return array{stream: string, status: array<string, mixed>, inventory: list<array<string, mixed>>, labels: array<string, string>}
     */
    private function row(string $stream, string $generation): array
    {
        $row = $this->generations[$generation] ?? null;

        // A generation under another stream is the same 404 as an unknown one.
        if ($row === null || $row['stream'] !== $stream) {
            throw self::error(404, 'not_found', "No generation {$generation} on ".self::describe($stream).'.');
        }

        return $row;
    }

    /**
     * @return array{stream: string, status: array<string, mixed>, inventory: list<array<string, mixed>>, labels: array<string, string>}
     */
    private function finalized(string $stream, string $generation): array
    {
        $row = $this->row($stream, $generation);

        if ($row['status']['state'] !== ArtifactStatus::STATE_FINALIZED) {
            throw self::conflict('artifact_not_finalized', "The generation {$generation} is not finalized.");
        }

        return $row;
    }

    /**
     * An object of the generation on any revision, as `[revision, index, row]`.
     *
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     */
    private function object(string $generation, string $objectId): array
    {
        foreach ($this->objects[$generation] ?? [] as $revision => $objects) {
            foreach ($objects as $index => $object) {
                if ($object['id'] === $objectId) {
                    return [$revision, $index, $object];
                }
            }
        }

        throw self::error(404, 'not_found', "No transfer object {$objectId} on generation {$generation}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function target(string $generation, int $revision, string $targetId): array
    {
        foreach ($this->plans[$generation][$revision]['targets'] ?? [] as $target) {
            if ($target['id'] === $targetId) {
                return $target;
            }
        }

        return [];
    }

    private static function describe(string $owner): string
    {
        return str_starts_with($owner, self::JOB_OWNER) ? 'job '.substr($owner, strlen(self::JOB_OWNER)) : "stream {$owner}";
    }

    private function forget(string $generation): void
    {
        unset(
            $this->generations[$generation],
            $this->plans[$generation],
            $this->planRequests[$generation],
            $this->objects[$generation],
            $this->decisions[$generation],
            $this->decisionRequests[$generation],
            $this->masterTargets[$generation],
        );
    }

    /**
     * A page of `$items` behind an opaque cursor bound to its scope and, for
     * transfers, to the revision read: another scope is `400`, another revision `409
     * transfer_plan_stale`, as at the node.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function page(array $items, ?int $limit, ?string $cursor, string $scope, ?int $revision = null): array
    {
        $limit = min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = 0;

        if ($cursor !== null) {
            $decoded = json_decode((string) base64_decode($cursor, true), true);

            if (! is_array($decoded) || ($decoded['s'] ?? null) !== $scope || ! is_int($decoded['o'] ?? null)) {
                throw self::error(400, 'bad_request', 'The cursor does not belong to this listing.');
            }

            if (($decoded['r'] ?? null) !== $revision) {
                throw self::conflict('transfer_plan_stale', 'The cursor belongs to a replaced plan revision.');
            }

            $offset = $decoded['o'];
        }

        $next = $offset + $limit < count($items)
            ? base64_encode((string) json_encode(['s' => $scope, 'r' => $revision, 'o' => $offset + $limit]))
            : null;

        return [
            'items' => array_slice($items, $offset, $limit),
            'limit' => $limit,
            'next_cursor' => $next,
        ];
    }

    /**
     * Seeded entries, or a small default set for the kind: segments, a playlist and
     * the replay master for HLS, one file for a recording.
     *
     * @param  list<InventoryEntry|array<string, mixed>>|null  $inventory
     * @return list<array<string, mixed>>
     */
    private static function inventory(string $kind, ?array $inventory): array
    {
        $inventory ??= match ($kind) {
            ArtifactStatus::KIND_HLS_SEALED, ArtifactStatus::KIND_JOB_HLS => [
                ['path' => 'v1/init.mp4', 'publish_order' => InventoryEntry::ORDER_MEDIA],
                ['path' => 'v1/seg_00001.m4s', 'publish_order' => InventoryEntry::ORDER_MEDIA],
                ['path' => 'v1/seg_00002.m4s', 'publish_order' => InventoryEntry::ORDER_MEDIA],
                ['path' => 'v1/playlist.m3u8', 'publish_order' => InventoryEntry::ORDER_PLAYLISTS],
                ['path' => ArtifactStatus::MASTER_NAMES[$kind], 'publish_order' => InventoryEntry::ORDER_MASTER],
            ],
            ArtifactStatus::KIND_JOB_MP4 => [['path' => '1080p.mp4', 'publish_order' => InventoryEntry::ORDER_MEDIA]],
            ArtifactStatus::KIND_JOB_AUDIO => [['path' => 'audio.m4a', 'publish_order' => InventoryEntry::ORDER_MEDIA]],
            ArtifactStatus::KIND_JOB_THUMBNAILS => [
                ['path' => '000000.000.jpg', 'publish_order' => InventoryEntry::ORDER_MEDIA],
                ['path' => '000010.000.jpg', 'publish_order' => InventoryEntry::ORDER_MEDIA],
            ],
            ArtifactStatus::KIND_JOB_TRANSCRIPT => [
                ['path' => 'transcript.json', 'publish_order' => InventoryEntry::ORDER_MEDIA],
                ['path' => 'transcript.srt', 'publish_order' => InventoryEntry::ORDER_MEDIA],
                ['path' => 'transcript.vtt', 'publish_order' => InventoryEntry::ORDER_MEDIA],
            ],
            ArtifactStatus::KIND_JOB_PROBE => [['path' => 'probe.json', 'publish_order' => InventoryEntry::ORDER_MEDIA]],
            default => [['path' => 'recording.ts', 'publish_order' => InventoryEntry::ORDER_MEDIA]],
        };

        $out = [];

        foreach ($inventory as $entry) {
            $raw = $entry instanceof InventoryEntry ? $entry->toArray() : $entry;
            $path = self::str($raw, 'path');
            $playlist = str_ends_with($path, '.m3u8');

            $out[] = [
                'path' => $path,
                'size_bytes' => self::int($raw, 'size_bytes') ?: 1024,
                'checksum_sha256' => self::str($raw, 'checksum_sha256') ?: hash('sha256', $path),
                'content_type' => self::str($raw, 'content_type') ?: self::contentType($path),
                'cache_control' => self::str($raw, 'cache_control') ?: ($playlist ? 'public, max-age=60' : 'public, max-age=31536000, immutable'),
                'publish_order' => self::int($raw, 'publish_order'),
            ];
        }

        return $out;
    }

    private static function contentType(string $path): string
    {
        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'flv' => 'video/x-flv',
            'vtt' => 'text/vtt',
            'json' => 'application/json',
            'jpg' => 'image/jpeg',
            'm4a' => 'audio/mp4',
            'srt' => 'application/x-subrip',
            default => 'video/mp4',
        };
    }

    /**
     * The node hashes a plan without its initial authorizations, so an exact retry
     * that carries fresh ones is still the same request.
     */
    private static function planHash(PlanRequest $plan): string
    {
        $body = self::redactPlan($plan);
        $targets = is_array($body['targets'] ?? null) ? $body['targets'] : [];

        foreach ($targets as $i => $target) {
            if (is_array($target) && is_array($target['signed'] ?? null)) {
                unset($targets[$i]['signed']['authorizations']);
            }
        }

        $body['targets'] = $targets;

        return hash('sha256', (string) json_encode($body));
    }

    private static function bundleHash(AuthorizationBundle $bundle): string
    {
        return hash('sha256', (string) json_encode($bundle->toArray()));
    }

    private static function plusHours(int $hours): string
    {
        return (new DateTimeImmutable(self::NOW))->modify("+{$hours} hours")->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function conflict(string $code, string $message): TmesisException
    {
        return self::error(409, $code, $message);
    }

    private static function invalid(string $path, string $message): TmesisException
    {
        return self::error(422, 'validation_failed', 'the request has field problems', [['path' => $path, 'message' => $message]]);
    }

    /**
     * Through ErrorMap, the one place a status and a code become an exception, so a
     * consumer's catch and `code()` branch see exactly what the node would give them.
     *
     * @param  list<array{path: string, message: string}>  $details
     */
    private static function error(int $status, string $code, string $message, array $details = []): TmesisException
    {
        return ErrorMap::toException($status, ErrorResponse::fromArray(['error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details === [] ? null : $details,
            'request_id' => '',
            'milestone' => null,
        ]]));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_int($value) ? $value : 0;
    }
}
