<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * Admission capacity. measured() false means these are configured or
 * inferred numbers rather than benchmarked ones.
 */
final readonly class DoctorCapacity
{
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

    public function diskFloorBytes(): int
    {
        return $this->int('disk_floor_bytes');
    }

    public function diskFreeBytes(): int
    {
        return $this->int('disk_free_bytes');
    }

    public function encodeSlotsTotal(): int
    {
        return $this->int('encode_slots_total');
    }

    public function encodeSlotsUsed(): int
    {
        return $this->int('encode_slots_used');
    }

    public function maxStreams(): int
    {
        return $this->int('max_streams');
    }

    public function measured(): bool
    {
        return $this->bool('measured');
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
