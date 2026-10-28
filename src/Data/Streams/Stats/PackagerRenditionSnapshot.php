<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One rendition inside a running packager: what it has announced and what it
 * has lost.
 *
 * Live state. StreamStats::packager() is empty between sessions, which is the
 * honest answer rather than a zeroed row: the announced window belongs to the
 * packager consuming a running encoder and ends with it.
 */
final readonly class PackagerRenditionSnapshot
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

    public function awaitingKeyframe(): bool
    {
        return $this->bool('awaiting_keyframe');
    }

    public function discontinuitiesTotal(): int
    {
        return $this->int('discontinuities_total');
    }

    public function droppedFragmentsTotal(): int
    {
        return $this->int('dropped_fragments_total');
    }

    public function encoderGapsTotal(): int
    {
        return $this->int('encoder_gaps_total');
    }

    public function failed(): bool
    {
        return $this->bool('failed');
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

    public function mediaSequence(): int
    {
        return $this->int('media_sequence');
    }

    public function openFragments(): int
    {
        return $this->int('open_fragments');
    }

    public function rendition(): string
    {
        return $this->string('rendition');
    }

    public function sealed(): bool
    {
        return $this->bool('sealed');
    }

    public function secondsSinceLastSegment(): ?float
    {
        return $this->nullableFloat('seconds_since_last_segment');
    }

    public function segmentsAnnounced(): int
    {
        return $this->int('segments_announced');
    }

    public function sinkFailuresTotal(): int
    {
        return $this->int('sink_failures_total');
    }

    public function splicesTotal(): int
    {
        return $this->int('splices_total');
    }

    public function targetDuration(): int
    {
        return $this->int('target_duration');
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

    private function nullableFloat(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
