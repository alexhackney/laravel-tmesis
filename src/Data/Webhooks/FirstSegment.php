<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The first delivered segment, on `stream.live`.
 *
 * The `$defs.FirstSegment` shape of `stream.live.schema.json`.
 */
final readonly class FirstSegment
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function mediaSequence(): int
    {
        return (int) ($this->raw['media_sequence'] ?? 0);
    }

    /**
     * The EXT-X-PROGRAM-DATE-TIME of that segment, raw. Null when the packager had
     * no wall-clock anchor.
     */
    public function programDateTimeRaw(): ?string
    {
        $value = $this->raw['program_date_time'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function programDateTime(): ?DateTimeImmutable
    {
        $value = $this->programDateTimeRaw();

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function durationSeconds(): float
    {
        return (float) ($this->raw['duration_seconds'] ?? 0.0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
