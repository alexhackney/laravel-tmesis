<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The jobs pause the box's engine holds. The openapi component JobPauseResponse
 * (tmesis `v0.9.0`, handover 3.5, `docs/API.md` 9.12a), the `200` body of
 * `setTranscodePause` and `getTranscodePause`.
 *
 * Exactly `{"paused": false}` when no pause is active (none set, cleared, or `until`
 * passed, a past `until` on the PUT included); otherwise `paused` true with `until`,
 * `reason`, `source` and `set_at`. Only `paused` is required, so every other accessor
 * answers null on the inactive shape.
 *
 * While paused the box claims no queued job, running jobs finish, and a submission
 * still queues (`202`). A control plane's stop-offering rule reads the HEARTBEAT's `jobs_pause`
 * as the source of truth; this is the standalone box's read.
 */
final readonly class JobPauseResponse
{
    /** The control plane's desired state (`jobs_pause`). */
    public const SOURCE_FLEET = 'fleet';

    /** `PUT /api/v1/transcode/pause`. */
    public const SOURCE_API = 'api';

    /** The closed `source` enum. */
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
     * True while the pause is active.
     */
    public function paused(): bool
    {
        return ($this->raw['paused'] ?? false) === true;
    }

    /**
     * When the pause ends by itself; null while it holds until cleared, and on the
     * inactive shape.
     */
    public function untilRaw(): ?string
    {
        return $this->string('until');
    }

    public function until(): ?DateTimeImmutable
    {
        $value = $this->untilRaw();

        return $value === null ? null : Rfc3339::parse($value);
    }

    /**
     * Why, verbatim; null on the inactive shape.
     */
    public function reason(): ?string
    {
        return $this->string('reason');
    }

    /**
     * `fleet` or `api` (one of SOURCES); null on the inactive shape.
     */
    public function source(): ?string
    {
        return $this->string('source');
    }

    /**
     * When the pause was last written; null on the inactive shape.
     */
    public function setAtRaw(): ?string
    {
        return $this->string('set_at');
    }

    public function setAt(): ?DateTimeImmutable
    {
        $value = $this->setAtRaw();

        return $value === null ? null : Rfc3339::parse($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function string(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
