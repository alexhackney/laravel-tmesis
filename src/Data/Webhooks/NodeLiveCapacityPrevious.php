<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The prior value of each live capacity field that changed, on `node.capacity_changed`
 * (`v0.9.0`, handover 3.3).
 *
 * The `$defs.NodeLiveCapacityPrevious` shape. It holds ONLY the fields that changed
 * since the last event of the SAME reason from the same process (within one process
 * only the used counts change), so every accessor is null for a field that did not
 * change, and none of them is null for any other reason: unlike NodeLiveCapacity, no
 * field here is nullable on the wire. No history crosses a restart.
 */
final readonly class NodeLiveCapacityPrevious
{
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
     * The names of the fields that changed, as sent.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        return array_keys($this->raw);
    }

    public function admission(): ?string
    {
        return $this->string('admission');
    }

    public function source(): ?string
    {
        return $this->string('source');
    }

    public function unitLadder(): ?string
    {
        return $this->string('unit_ladder');
    }

    public function encodeSlotsTotal(): ?int
    {
        return $this->int('encode_slots_total');
    }

    public function encodeSlotsUsed(): ?int
    {
        return $this->int('encode_slots_used');
    }

    public function deviceSessionsTotal(): ?int
    {
        return $this->int('device_sessions_total');
    }

    public function deviceSessionsUsed(): ?int
    {
        return $this->int('device_sessions_used');
    }

    public function measuredAtRaw(): ?string
    {
        return $this->string('measured_at');
    }

    public function measuredAt(): ?DateTimeImmutable
    {
        $value = $this->measuredAtRaw();

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
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

        return is_string($value) ? $value : null;
    }

    private function int(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
