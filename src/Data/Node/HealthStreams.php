<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * Stream counts. provisioned is nullable: a node with no registry cannot
 * answer it.
 */
final readonly class HealthStreams
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

    public function live(): int
    {
        return $this->int('live');
    }

    /**
     * Unsealed registry rows. Null when the registry could not be read; never 0
     * as a stand-in for unknown.
     */
    public function provisioned(): ?int
    {
        return $this->nullableInt('provisioned');
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

    private function nullableInt(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
