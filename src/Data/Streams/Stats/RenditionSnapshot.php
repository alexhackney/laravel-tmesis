<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One delivered rendition: segments out, and every packager drop and sink
 * failure behind them.
 */
final readonly class RenditionSnapshot
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

    public function journalFailuresTotal(): int
    {
        return $this->int('journal_failures_total');
    }

    public function lastSegmentAtRaw(): ?string
    {
        return $this->nullableString('last_segment_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastSegmentAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_segment_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function lastSegmentDurationSeconds(): float
    {
        return $this->float('last_segment_duration_seconds');
    }

    public function mediaSequence(): int
    {
        return $this->int('media_sequence');
    }

    public function packagerDroppedTotal(): int
    {
        return $this->int('packager_dropped_total');
    }

    /**
     * @return list<DropSnapshot>
     */
    public function packagerDrops(): array
    {
        $out = [];

        foreach ($this->array('packager_drops') as $item) {
            if (is_array($item)) {
                $out[] = DropSnapshot::fromArray($item);
            }
        }

        return $out;
    }

    public function rendition(): string
    {
        return $this->string('rendition');
    }

    public function secondsSinceLastSegment(): ?float
    {
        return $this->nullableFloat('seconds_since_last_segment');
    }

    public function segmentsTotal(): int
    {
        return $this->int('segments_total');
    }

    /**
     * @return list<DropSnapshot>
     */
    public function sinkFailures(): array
    {
        $out = [];

        foreach ($this->array('sink_failures') as $item) {
            if (is_array($item)) {
                $out[] = DropSnapshot::fromArray($item);
            }
        }

        return $out;
    }

    public function sinkFailuresTotal(): int
    {
        return $this->int('sink_failures_total');
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

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function nullableFloat(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
