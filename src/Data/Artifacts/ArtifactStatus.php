<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One output generation: an immutable set of files the node finalized (a sealed HLS
 * replay, an ingest recording, a told master since tmesis `v0.8.0` (master() carries
 * its request and progress), and since tmesis `v0.6.0` one output of a transcode
 * job). The openapi component ArtifactStatus, the body of `getArtifact`
 * and `getJobArtifact` and an item of `listArtifacts` and `listJobArtifacts` (tmesis
 * `v0.5.0`, `docs/API.md` 12a.2).
 *
 * **A generation belongs to a stream OR to a job (`v0.6.0`).** A stream generation
 * names `stream()`, `session()` and `sessionEpoch()` and a null `jobId()`; a JOB
 * generation (the `job_*` kinds) names `jobId()` and has all three null. Branch on
 * isJobGeneration() and read it back on the matching resource: `artifacts()` for a
 * stream, `jobArtifacts()` for a job.
 *
 * Before finalization `inventorySha256()`, `finalizedAt()` and `planRevision()` are
 * null; the retention policy never is. Nothing here is a local filesystem path, and a
 * read survives the local deletion of the generation.
 *
 * `retentionExpiresAt()` null is UNKNOWN (a recording that never registered, decision
 * #68), never zero: such a generation is held with `retention_clock_unknown`.
 */
final readonly class ArtifactStatus
{
    public const KIND_HLS_SEALED = 'hls_sealed';

    public const KIND_RECORDING_INGEST = 'recording_ingest';

    public const KIND_RECORDING_MASTER = 'recording_master';

    public const KIND_JOB_HLS = 'job_hls';

    public const KIND_JOB_MP4 = 'job_mp4';

    public const KIND_JOB_AUDIO = 'job_audio';

    public const KIND_JOB_THUMBNAILS = 'job_thumbnails';

    public const KIND_JOB_TRANSCRIPT = 'job_transcript';

    public const KIND_JOB_PROBE = 'job_probe';

    /**
     * The six kinds of a transcode job's generations (tmesis `v0.6.0`), one per output.
     *
     * @var list<string>
     */
    public const JOB_KINDS = [
        self::KIND_JOB_HLS,
        self::KIND_JOB_MP4,
        self::KIND_JOB_AUDIO,
        self::KIND_JOB_THUMBNAILS,
        self::KIND_JOB_TRANSCRIPT,
        self::KIND_JOB_PROBE,
    ];

    /** @var list<string> */
    public const KINDS = [
        self::KIND_HLS_SEALED,
        self::KIND_RECORDING_INGEST,
        self::KIND_RECORDING_MASTER,
        self::KIND_JOB_HLS,
        self::KIND_JOB_MP4,
        self::KIND_JOB_AUDIO,
        self::KIND_JOB_THUMBNAILS,
        self::KIND_JOB_TRANSCRIPT,
        self::KIND_JOB_PROBE,
    ];

    /**
     * The kinds whose targets name a PREFIX (`PlanTarget`): the HLS shape
     * (`hls_sealed`, `job_hls`) and the multi-file `files` shape (`job_mp4`,
     * `job_thumbnails`, `job_transcript`). Every other kind is one file and names a KEY.
     *
     * @var list<string>
     */
    public const PREFIX_KINDS = [
        self::KIND_HLS_SEALED,
        self::KIND_JOB_HLS,
        self::KIND_JOB_MP4,
        self::KIND_JOB_THUMBNAILS,
        self::KIND_JOB_TRANSCRIPT,
    ];

    /**
     * The kinds with a master playlist, and the master's own name, which a target's
     * `master_name` replaces remotely. `playlist.m3u8` for `hls_sealed` since tmesis
     * `v0.8.0`: the final seal ends the live master in place (it was `vod.m3u8`).
     *
     * @var array<string, string>
     */
    public const MASTER_NAMES = [
        self::KIND_HLS_SEALED => 'playlist.m3u8',
        self::KIND_JOB_HLS => 'playlist.m3u8',
    ];

    public const STATE_HELD = 'held';

    public const STATE_FINALIZED = 'finalized';

    public const RELEASE_UNRELEASED = 'unreleased';

    public const RELEASE_RELEASED = 'released';

    public const RELEASE_OVERRIDDEN = 'overridden';

    public const RELEASE_RETIRING = 'retiring';

    public const RELEASE_DELETED = 'deleted';

    /**
     * The eleven hold reasons, in the order the node reports them (contract 5.3).
     * `master_pending` (tmesis `v0.8.0`) is a `recording_ingest` source of a queued
     * or running told master (`Artifacts::createMaster()`); a purge of it is `409
     * artifact_held`.
     *
     * @var list<string>
     */
    public const HOLD_REASONS = [
        'not_finalized',
        'retention_clock_unknown',
        'retention_not_elapsed',
        'copies_incomplete',
        'release_required',
        'writer_active',
        'recovery_active',
        'transfer_active',
        'master_pending',
        'local_integrity_failed',
        'retirement_failed',
    ];

    /**
     * The three reasons that mean something still works on the generation. A held
     * `hls_sealed` generation carrying none of them after its session ended is
     * ABANDONED (`docs/API.md` 12a.5a) and takes `OverrideRequest::discardAbandoned()`.
     *
     * @var list<string>
     */
    public const ACTIVE_HOLD_REASONS = ['writer_active', 'recovery_active', 'transfer_active'];

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private array $raw,
        /**
         * Whether THIS call journaled the generation: `202` from `createMaster()` for
         * a new request, false for its exact retry (`200`, the generation as it is
         * now) and on every read.
         *
         * From the status code, the only place the fact exists.
         */
        public bool $created = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $created = false): self
    {
        return new self($data, $created);
    }

    /**
     * The generation id, a ULID minted by the node.
     */
    public function id(): string
    {
        return $this->string('id');
    }

    /**
     * The stream the generation came from; null for a job generation.
     */
    public function stream(): ?string
    {
        return $this->nullableString('stream');
    }

    /**
     * The publish session; null for a job generation.
     */
    public function session(): ?string
    {
        return $this->nullableString('session');
    }

    /**
     * The session's epoch; null for a job generation.
     */
    public function sessionEpoch(): ?int
    {
        $value = $this->raw['session_epoch'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The transcode job a job generation belongs to (`job_<ULID>`); null for a stream
     * generation.
     */
    public function jobId(): ?string
    {
        return $this->nullableString('job_id');
    }

    public function isJobGeneration(): bool
    {
        return $this->jobId() !== null || in_array($this->kind(), self::JOB_KINDS, true);
    }

    /**
     * One of KINDS.
     */
    public function kind(): string
    {
        return $this->string('kind');
    }

    /**
     * The recording a recording generation belongs to; null for any other kind.
     */
    public function recordingId(): ?string
    {
        return $this->nullableString('recording_id');
    }

    /**
     * `held` until finalized, then `finalized`.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    public function isFinalized(): bool
    {
        return $this->state() === self::STATE_FINALIZED;
    }

    /**
     * SHA-256 of the committed inventory, 64 lowercase hex digits; null until
     * finalized. Every mutation names it.
     */
    public function inventorySha256(): ?string
    {
        return $this->nullableString('inventory_sha256');
    }

    public function objectCount(): int
    {
        return $this->int('object_count');
    }

    public function sizeBytes(): int
    {
        return $this->int('size_bytes');
    }

    public function finalizedAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('finalized_at');
    }

    /**
     * When a recording registered; its retention counts from here. Null for
     * `hls_sealed` and for a recording not yet registered.
     */
    public function registeredAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('registered_at');
    }

    public function retentionHours(): int
    {
        return $this->int('retention_hours');
    }

    /**
     * The earliest retention eligibility, only ever raised. NULL IS UNKNOWN, never
     * zero (decision #68).
     */
    public function retentionExpiresAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('retention_expires_at');
    }

    /**
     * The current plan revision; 0 is the empty baseline written at finalisation,
     * never a release. Null until finalized.
     */
    public function planRevision(): ?int
    {
        $value = $this->raw['plan_revision'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * One of the RELEASE_* values.
     */
    public function releaseState(): string
    {
        return $this->string('release_state');
    }

    /**
     * True while any hold reason stands: the local files are kept.
     */
    public function held(): bool
    {
        return (bool) ($this->raw['held'] ?? false);
    }

    /**
     * Why the local files are kept, from HOLD_REASONS. Empty means the next
     * retirement pass may remove them.
     *
     * @return list<string>
     */
    public function holdReasons(): array
    {
        $value = $this->raw['hold_reasons'] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    public function localPresent(): bool
    {
        return (bool) ($this->raw['local_present'] ?? false);
    }

    public function createdAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('created_at'));
    }

    /**
     * The told master's request and progress on a `recording_master` generation
     * (`Artifacts::createMaster()`, tmesis `v0.8.0`); null on every other kind.
     */
    public function master(): ?MasterInfo
    {
        $value = $this->raw['master'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return MasterInfo::fromArray($value);
    }

    /**
     * When an operator purge (`purgeArtifact()`, tmesis `v0.8.0`) was recorded on the
     * current decision: the next retirement pass may remove the local files from then
     * on, whatever retentionExpiresAt() says (which a purge never changes). Null when
     * none was. A purged generation carries neither retention hold reason.
     */
    public function purgeRequestedAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('purge_requested_at');
    }

    public function isPurged(): bool
    {
        return $this->purgeRequestedAt() !== null;
    }

    /**
     * The response as it arrived, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableTime(string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($key);

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }
}
