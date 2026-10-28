<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The jobs pause in force on GET /api/v1/health (tmesis `v0.9.0`, handover 3.5).
 * The openapi component HealthJobsPause: while it holds no queued job is claimed,
 * running jobs finish, and a submission is still accepted and queued.
 *
 * A pause restored at boot emits no `jobs.paused`, so this (or the heartbeat's
 * `jobs_pause`) is the source of truth for "stop offering jobs to this box".
 */
final readonly class HealthJobsPause
{
    public const SOURCE_FLEET = 'fleet';

    public const SOURCE_API = 'api';

    /**
     * The closed `source` enum, held equal to the schema by its test.
     */
    public const SOURCES = [self::SOURCE_FLEET, self::SOURCE_API];

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
     * RFC3339 UTC: when the pause ends by itself. Null when it holds until cleared.
     */
    public function untilRaw(): ?string
    {
        $value = $this->raw['until'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Parsed from the raw value above.
     */
    public function until(): ?DateTimeImmutable
    {
        $raw = $this->untilRaw();

        return $raw === null ? null : Rfc3339::parse($raw);
    }

    public function reason(): string
    {
        $value = $this->raw['reason'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * CLOSED set. `fleet`: the control plane's desired state (`jobs_pause`). `api`:
     * `PUT /api/v1/transcode/pause`.
     */
    public function source(): string
    {
        $value = $this->raw['source'] ?? null;

        return is_string($value) ? $value : '';
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
}
