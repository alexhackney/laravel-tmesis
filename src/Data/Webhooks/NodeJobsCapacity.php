<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * A node's job slots and where the number came from, on `node.capacity_changed`
 * (`v0.9.0`, handover 3.3).
 *
 * The `$defs.NodeJobsCapacity` shape. The event carries null instead when jobs are
 * off.
 */
final readonly class NodeJobsCapacity
{
    /**
     * `jobs.max_concurrent`.
     */
    public const SOURCE_CONFIGURED = 'configured';

    /**
     * The stored job measurement valid for this box.
     */
    public const SOURCE_MEASURED = 'measured';

    /**
     * No measurement: the box runs one job.
     */
    public const SOURCE_UNMEASURED = 'unmeasured';

    /**
     * A measurement of another box: the box runs one job.
     */
    public const SOURCE_STALE = 'stale';

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

    public function slots(): int
    {
        $value = $this->raw['slots'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
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
