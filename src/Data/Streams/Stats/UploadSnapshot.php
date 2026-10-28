<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The upload queue behind delivery: depth, throughput, failures and lag.
 */
final readonly class UploadSnapshot
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

    public function bytesTotal(): int
    {
        return $this->int('bytes_total');
    }

    public function failedTotal(): int
    {
        return $this->int('failed_total');
    }

    public function lastLagSeconds(): ?float
    {
        return $this->nullableFloat('last_lag_seconds');
    }

    public function lastUploadAtRaw(): ?string
    {
        return $this->nullableString('last_upload_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastUploadAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_upload_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function okTotal(): int
    {
        return $this->int('ok_total');
    }

    public function queueDepth(): int
    {
        return $this->int('queue_depth');
    }

    public function secondsSinceLastUpload(): ?float
    {
        return $this->nullableFloat('seconds_since_last_upload');
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
