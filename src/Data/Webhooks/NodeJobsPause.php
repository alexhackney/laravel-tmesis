<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The jobs pause in force when a node came up, on `node.online` (`v0.9.0`, handover
 * 3.5).
 *
 * The `$defs.NodeJobsPause` shape of `node.online.schema.json`, the health
 * `jobs.paused` block. A pause RESTORED from the journal at boot emits no
 * `jobs.paused`, so this (and the first heartbeat) is how a receiver learns of it.
 * While one is active the box claims no new transcode job: stop offering it jobs.
 */
final readonly class NodeJobsPause
{
    /**
     * The control plane's desired state (this enrollment's `jobs_pause`).
     */
    public const SOURCE_FLEET = 'fleet';

    /**
     * `PUT /api/v1/transcode/pause` on the box itself.
     */
    public const SOURCE_API = 'api';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The raw `until`, or null for a pause that holds until it is cleared.
     */
    public function untilRaw(): ?string
    {
        $value = $this->raw['until'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * When the pause ends by itself; null until it is cleared.
     */
    public function until(): ?DateTimeImmutable
    {
        $value = $this->untilRaw();

        return $value === null ? null : Rfc3339::parse($value);
    }

    public function reason(): string
    {
        $value = $this->raw['reason'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * CLOSED set: the SOURCE_* constants.
     */
    public function source(): string
    {
        $value = $this->raw['source'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
