<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * Encode slots on GET /api/v1/health. Not the node.online NodeCapacity,
 * which is a different pair of fields.
 */
final readonly class HealthCapacity
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

    public function encodeSlotsTotal(): int
    {
        return $this->int('encode_slots_total');
    }

    public function encodeSlotsUsed(): int
    {
        return $this->int('encode_slots_used');
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
}
