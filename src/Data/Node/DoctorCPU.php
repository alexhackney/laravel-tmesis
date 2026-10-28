<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * The CPU as the node sees it. restricted() true means a cgroup limit
 * applies and allowance() is the effective core count, not logical_cores().
 */
final readonly class DoctorCPU
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

    public function allowance(): float
    {
        return $this->float('allowance');
    }

    public function arch(): string
    {
        return $this->string('arch');
    }

    public function logicalCores(): int
    {
        return $this->int('logical_cores');
    }

    public function model(): string
    {
        return $this->string('model');
    }

    public function physicalCores(): ?int
    {
        return $this->nullableInt('physical_cores');
    }

    public function restricted(): bool
    {
        return $this->bool('restricted');
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

    private function nullableInt(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
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
}
