<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One part of a multipart transfer object. The openapi component PartStatus (tmesis
 * `v0.5.0`, `docs/API.md` 12a.4). Internal claim ids are never exposed.
 *
 * `state()` takes the same six values as `ObjectStatus::STATES`.
 */
final readonly class PartStatus
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

    public function partNumber(): int
    {
        return $this->int('part_number');
    }

    public function offsetBytes(): int
    {
        return $this->int('offset_bytes');
    }

    public function sizeBytes(): int
    {
        return $this->int('size_bytes');
    }

    public function checksumSha256(): string
    {
        $value = $this->raw['checksum_sha256'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function state(): string
    {
        $value = $this->raw['state'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function attempts(): int
    {
        return $this->int('attempts');
    }

    public function etag(): ?string
    {
        $value = $this->raw['etag'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        $value = $this->raw['completed_at'] ?? null;

        return (! is_string($value) || $value === '') ? null : Rfc3339::parse($value);
    }

    public function error(): ?ObjectError
    {
        $value = $this->raw['error'] ?? null;

        /** @var array<string, mixed>|null $value */
        return is_array($value) ? ObjectError::fromArray($value) : null;
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
