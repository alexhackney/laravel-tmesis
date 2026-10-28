<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The last capacity benchmark. stale() true means the numbers behind
 * capacity() were measured on different hardware or a different build.
 */
final readonly class DoctorBenchmark
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

    public function profile(): string
    {
        return $this->string('profile');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function ranAtRaw(): string
    {
        return $this->string('ran_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function ranAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('ran_at'));
    }

    public function stale(): bool
    {
        return $this->bool('stale');
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

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
