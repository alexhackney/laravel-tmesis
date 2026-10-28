<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * Disk headroom against the configured floor. ok() false means the node is
 * shedding to protect the live path.
 */
final readonly class HealthDisk
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

    public function floorBytes(): int
    {
        return $this->int('floor_bytes');
    }

    public function freeBytes(): int
    {
        return $this->int('free_bytes');
    }

    public function ok(): bool
    {
        return $this->bool('ok');
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
