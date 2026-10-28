<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One encoder rung: how fast it is running against realtime and when it last
 * made progress.
 */
final readonly class EncodeSnapshot
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

    public function droppedFramesTotal(): int
    {
        return $this->int('dropped_frames_total');
    }

    public function fps(): float
    {
        return $this->float('fps');
    }

    public function framesTotal(): int
    {
        return $this->int('frames_total');
    }

    public function lastFragmentAtRaw(): ?string
    {
        return $this->nullableString('last_fragment_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastFragmentAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_fragment_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function lastProgressAtRaw(): ?string
    {
        return $this->nullableString('last_progress_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastProgressAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_progress_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * The rate this rung was PINNED to for the session, as a rational string
     * (`30000/1001`). Compare against fps(), which is measured throughput and reads
     * above the source rate while the encoder eats a backlog. Since Tmesis rc5.
     */
    public function resolvedFps(): string
    {
        return $this->string('resolved_fps');
    }

    public function restarts(): int
    {
        return $this->int('restarts');
    }

    public function rung(): string
    {
        return $this->string('rung');
    }

    public function secondsBelowRealtime(): ?float
    {
        return $this->nullableFloat('seconds_below_realtime');
    }

    public function secondsSinceLastFragment(): ?float
    {
        return $this->nullableFloat('seconds_since_last_fragment');
    }

    public function secondsSinceLastProgress(): ?float
    {
        return $this->nullableFloat('seconds_since_last_progress');
    }

    public function speedRatio(): float
    {
        return $this->float('speed_ratio');
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
}
