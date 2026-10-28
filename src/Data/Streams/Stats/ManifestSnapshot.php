<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * HLS manifest publishing: how many, how late, and how long ago.
 */
final readonly class ManifestSnapshot
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

    public function lastLatencySeconds(): ?float
    {
        return $this->nullableFloat('last_latency_seconds');
    }

    public function lastPublishedAtRaw(): ?string
    {
        return $this->nullableString('last_published_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastPublishedAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_published_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function publishedTotal(): int
    {
        return $this->int('published_total');
    }

    public function sinceLastPublishedSeconds(): ?float
    {
        return $this->nullableFloat('since_last_published_seconds');
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

    private function nullableString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableFloat(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
