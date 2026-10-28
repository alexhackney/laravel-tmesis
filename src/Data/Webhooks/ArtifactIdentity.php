<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * The identity block every `artifact.*` and `transfer.*` event carries, flat beside its
 * own fields (tmesis `v0.5.0`, WEBHOOKS.md 8.28, contract 7.1).
 *
 * Not a `$defs` entry: each of the six schemas inlines the eight fields, so the name is
 * the contract prose's. Read from the whole payload; only these eight keys are kept.
 *
 * **A job generation has no stream (`v0.6.0`).** `stream()`, `session()` and
 * `sessionEpoch()` are NULL on one and `jobId()` names the transcode job instead; its
 * events go to the job's `callback_url`. Branch on isJobGeneration() and route the
 * follow-up to `jobArtifacts()` rather than `artifacts()`.
 */
final readonly class ArtifactIdentity
{
    public const KIND_HLS_SEALED = 'hls_sealed';

    public const KIND_RECORDING_INGEST = 'recording_ingest';

    public const KIND_RECORDING_MASTER = 'recording_master';

    private const KEYS = ['stream', 'session', 'session_epoch', 'job_id', 'generation', 'kind', 'inventory_sha256', 'labels'];

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data  the event payload, or just the identity block
     */
    public static function fromArray(array $data): self
    {
        return new self(array_intersect_key($data, array_flip(self::KEYS)));
    }

    /**
     * The stream the generation came from; null on a job generation.
     */
    public function stream(): ?string
    {
        return is_string($this->raw['stream'] ?? null) ? $this->raw['stream'] : null;
    }

    /**
     * The publish session the generation came from; null on a job generation.
     */
    public function session(): ?string
    {
        return is_string($this->raw['session'] ?? null) ? $this->raw['session'] : null;
    }

    /**
     * The SESSION epoch, the publish identity: not the key epoch and not the fencing
     * epoch. Null on a job generation.
     */
    public function sessionEpoch(): ?SessionEpoch
    {
        $value = $this->raw['session_epoch'] ?? null;

        return is_numeric($value) ? SessionEpoch::fromInt((int) $value) : null;
    }

    /**
     * The transcode job a job generation belongs to (`job_<ULID>`); null on a stream
     * generation.
     */
    public function jobId(): ?string
    {
        return is_string($this->raw['job_id'] ?? null) ? $this->raw['job_id'] : null;
    }

    /**
     * Whether this generation is a transcode job's output: `jobId()` is set, or the
     * kind is a `job_*` one.
     */
    public function isJobGeneration(): bool
    {
        return $this->jobId() !== null || in_array($this->kind(), ArtifactStatus::JOB_KINDS, true);
    }

    /**
     * The immutable output generation (a ULID). Every artifact route and event keys on
     * this.
     */
    public function generation(): string
    {
        return is_string($this->raw['generation'] ?? null) ? $this->raw['generation'] : '';
    }

    /**
     * One of `ArtifactStatus::KINDS`: `hls_sealed`, `recording_ingest`,
     * `recording_master`, or a `job_*` kind.
     */
    public function kind(): string
    {
        return is_string($this->raw['kind'] ?? null) ? $this->raw['kind'] : '';
    }

    public function isRecording(): bool
    {
        return in_array($this->kind(), [self::KIND_RECORDING_INGEST, self::KIND_RECORDING_MASTER], true);
    }

    /**
     * SHA-256 of the committed inventory, 64 lowercase hex digits. EMPTY on the
     * `artifact.released` of an abandoned generation's discard, which never finalized.
     */
    public function inventorySha256(): string
    {
        return is_string($this->raw['inventory_sha256'] ?? null) ? $this->raw['inventory_sha256'] : '';
    }

    /**
     * The stream's labels (a job generation: the job's), echoed back. The node never interprets them.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = $this->raw['labels'] ?? null;

        if (! is_array($labels)) {
            return [];
        }

        $out = [];

        foreach ($labels as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
