<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The full body of GET /api/v1/health. The openapi component HealthFull. A
 * 503 answers the error envelope rather than this shape, so a draining node
 * arrives as UnavailableException and never as a HealthFull with status
 * "unavailable".
 */
final readonly class HealthFull
{
    public const ADMISSION_OPEN = 'open';

    public const ADMISSION_DRAINING = 'draining';

    public const ADMISSION_SHUTTING_DOWN = 'shutting_down';

    public const INGEST_NOT_WIRED = 'not_wired';

    public const INGEST_LISTENING = 'listening';

    public const ROLE_EDGE = 'edge';

    public const ROLE_ORIGIN = 'origin';

    public const ROLE_TRANSCODER = 'transcoder';

    public const ROLE_STANDALONE = 'standalone';

    public const STATUS_OK = 'ok';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const DEGRADED_SHEDDING_INGEST = 'shedding_ingest';

    /**
     * Two causes share this reason. Either the encoder is too slow (`speed_ratio` under 0.99), or,
     * since tmesis v0.9.6, every video rung of an open, attached stream has delivered no fragment
     * for the rung idle bound while audio still flows (`speed_ratio` about 1.0). Tell them apart
     * by `encode[].seconds_since_last_fragment`.
     */
    public const DEGRADED_ENCODER_BEHIND = 'encoder_behind';

    public const DEGRADED_UPLOAD_BACKLOG = 'upload_backlog';

    public const DEGRADED_DISK_FLOOR_NEAR = 'disk_floor_near';

    /**
     * The pinned live encoder is not usable and every publish is refused
     * `encoder_unavailable` (tmesis `v0.9.0`, handover 3.4). `ready` stays true.
     */
    public const DEGRADED_ENCODER_UNAVAILABLE = 'encoder_unavailable';

    /**
     * The pinned jobs encoder is not usable and job submissions are refused `503
     * encoder_unavailable` (tmesis `v0.9.0`, handover 3.4). Publishes are unaffected.
     */
    public const DEGRADED_JOBS_ENCODER_UNAVAILABLE = 'jobs_encoder_unavailable';

    /**
     * A configured transcription provider (live, batch or fallback) has not worked for 30
     * seconds in a row; `transcription()` names which (tmesis `v0.9.1`, K5). Captions
     * fail soft: `ready` stays true and no show is touched.
     */
    public const DEGRADED_TRANSCRIPTION_UNAVAILABLE = 'transcription_unavailable';

    /**
     * The closed `degraded_reasons` item enum, held equal to the schema by its test.
     */
    public const DEGRADED_REASONS = [
        self::DEGRADED_SHEDDING_INGEST,
        self::DEGRADED_ENCODER_BEHIND,
        self::DEGRADED_UPLOAD_BACKLOG,
        self::DEGRADED_DISK_FLOOR_NEAR,
        self::DEGRADED_ENCODER_UNAVAILABLE,
        self::DEGRADED_JOBS_ENCODER_UNAVAILABLE,
        self::DEGRADED_TRANSCRIPTION_UNAVAILABLE,
    ];

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Whether this node admits new publishes and jobs (tmesis `v0.7.0` D3): `open`;
     * `draining`, in place with the process up (a fleet drain command); or
     * `shutting_down`, the SIGTERM drain. `draining()` is true for either of the two.
     */
    public function admission(): string
    {
        return $this->string('admission');
    }

    /**
     * The job slots and their use. NULL until the box has a real number: a `tmesis
     * bench --profile job` measurement valid for this box, or a pinned
     * `jobs.max_concurrent` (tmesis `v0.6.0`). An unmeasured box runs one job and
     * reports null here, never 0 or 1; read `jobs()->slots()` for what it resolves.
     */
    public function capacity(): ?HealthCapacity
    {
        $value = $this->raw['capacity'] ?? null;

        return is_array($value) ? HealthCapacity::fromArray($value) : null;
    }

    public function configSource(): string
    {
        return $this->string('config_source');
    }

    public function contractVersion(): int
    {
        return $this->int('contract_version');
    }

    /**
     * The closed set of reasons this node calls itself degraded. Empty when it
     * is not.
     *
     * @return list<string>
     */
    public function degradedReasons(): array
    {
        $out = [];

        foreach ($this->array('degraded_reasons') as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Whether the node names this reason among its degraded ones.
     */
    public function degradedBecause(string $reason): bool
    {
        return in_array($reason, $this->degradedReasons(), true);
    }

    /**
     * Null when the free space probe failed: an unreadable filesystem is not a
     * full one.
     */
    public function disk(): ?HealthDisk
    {
        $value = $this->raw['disk'] ?? null;

        return is_array($value) ? HealthDisk::fromArray($value) : null;
    }

    public function draining(): bool
    {
        return $this->bool('draining');
    }

    /**
     * Honest state of the media path, separate from status: a process can be
     * alive and accept no publishers.
     */
    public function ingest(): string
    {
        return $this->string('ingest');
    }

    /**
     * The transcode queue (tmesis `v0.6.0`). Null when the node has no job engine or
     * its job table cannot be read, never zeros as a stand-in for unknown.
     */
    public function jobs(): ?HealthJobs
    {
        $value = $this->raw['jobs'] ?? null;

        return is_array($value) ? HealthJobs::fromArray($value) : null;
    }

    /**
     * What VOD transcode jobs encode with (`jobs.video_encoder`, tmesis `v0.9.0`,
     * handover 3.4), the same fields as `videoEngine()`. Null when jobs are off.
     */
    public function jobsVideoEngine(): ?HealthVideoEngine
    {
        $value = $this->raw['jobs_video_engine'] ?? null;

        return is_array($value) ? HealthVideoEngine::fromArray($value) : null;
    }

    public function journal(): ?HealthJournal
    {
        $value = $this->raw['journal'] ?? null;

        return is_array($value) ? HealthJournal::fromArray($value) : null;
    }

    /**
     * The live capacity (tmesis `v0.9.0`, handover 3.6): REQUIRED and never null on
     * the wire. `capacity()` stays the JOB slots. A body that lacks it (a pre-0.9.0
     * node) decodes as an empty object, every enum `""`, never a guess at `off`.
     */
    public function liveCapacity(): HealthLiveCapacity
    {
        $value = $this->raw['live_capacity'] ?? null;

        return HealthLiveCapacity::fromArray(is_array($value) ? $value : []);
    }

    public function node(): string
    {
        return $this->string('node');
    }

    public function nodeRole(): string
    {
        return $this->string('node_role');
    }

    /**
     * Startup finished: journal open, orphans reaped, listeners bound. This is
     * the field a load balancer reads.
     */
    public function ready(): bool
    {
        return $this->bool('ready');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function startedAtRaw(): string
    {
        return $this->string('started_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function startedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('started_at'));
    }

    public function status(): string
    {
        return $this->string('status');
    }

    public function streams(): ?HealthStreams
    {
        $value = $this->raw['streams'] ?? null;

        return is_array($value) ? HealthStreams::fromArray($value) : null;
    }

    /**
     * One entry per configured transcription role, live, batch, then fallback
     * (tmesis `v0.9.1`, K6). REQUIRED on the wire and empty when every provider is `none`;
     * a body that lacks it (a pre-0.9.1 node) decodes as an empty list too.
     *
     * @return list<HealthTranscription>
     */
    public function transcription(): array
    {
        $out = [];

        foreach ($this->array('transcription') as $item) {
            if (is_array($item)) {
                $out[] = HealthTranscription::fromArray($item);
            }
        }

        return $out;
    }

    public function uptimeSeconds(): float
    {
        return $this->float('uptime_seconds');
    }

    public function version(): string
    {
        return $this->string('version');
    }

    /**
     * What this box selected to encode its LIVE ladder with, probed at boot. Null
     * when the node encodes no live stream (no ladder configured, or the transcoder
     * role since tmesis `v0.9.0`), which is not the same as encoding on the CPU.
     */
    public function videoEngine(): ?HealthVideoEngine
    {
        $value = $this->raw['video_engine'] ?? null;

        return is_array($value) ? HealthVideoEngine::fromArray($value) : null;
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
