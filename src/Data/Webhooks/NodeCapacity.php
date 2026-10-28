<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * What a node can do, on `node.online`.
 *
 * The `$defs.NodeCapacity` shape of `node.online.schema.json`. Not the openapi
 * `HealthCapacity`, which is a different pair of fields. Since tmesis `v0.9.0`
 * (handover 3.2) it carries `admission`, and encodeSlotsTotal() means something only
 * while that is `on`: read admissionOn() before routing on it. The boot
 * `node.capacity_changed` (reason `benchmark`) follows with the whole live capacity.
 */
final readonly class NodeCapacity
{
    /**
     * Unit admission is enforced: a publish past encodeSlotsTotal() is refused
     * `over_capacity` (`v0.9.0`).
     */
    public const ADMISSION_ON = 'on';

    /**
     * Every publish is admitted and still counted; nobody measured.
     */
    public const ADMISSION_OFF = 'off';

    /**
     * A node that does not encode live (passthrough or transcoder).
     */
    public const ADMISSION_NOT_APPLICABLE = 'not_applicable';

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
     * The live UNIT limit while admission is `on`. `0` while it is `off` or
     * `not_applicable`, which means NOBODY MEASURED, never "no capacity" and never a
     * reason to stop routing to the box (`v0.9.0`, handover 3.2).
     */
    public function encodeSlotsTotal(): int
    {
        return (int) ($this->raw['encode_slots_total'] ?? 0);
    }

    /**
     * CLOSED set: the ADMISSION_* constants, the health `live_capacity.admission`
     * value (`v0.9.0`). Empty from a node older than that.
     */
    public function admission(): string
    {
        $value = $this->raw['admission'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Whether unit admission is on, so encodeSlotsTotal() is a limit the node
     * enforces rather than an unmeasured `0`. NOT "the box was benched": a told
     * `limits.max_streams` turns admission on too (source `configured`), and
     * `node.online` carries no source; the boot `node.capacity_changed` `benchmark`
     * says which (NodeLiveCapacity::source()).
     */
    public function admissionOn(): bool
    {
        return $this->admission() === self::ADMISSION_ON;
    }

    public function uplinkMbps(): int
    {
        return (int) ($this->raw['uplink_mbps'] ?? 0);
    }

    public function diskFreeBytes(): int
    {
        return (int) ($this->raw['disk_free_bytes'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
