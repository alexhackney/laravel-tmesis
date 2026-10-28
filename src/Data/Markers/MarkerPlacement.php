<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Markers;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * Where one rendition carries a marker's tag. The openapi component MarkerPlacement
 * AND the `marker.placed` schema's `$defs.MarkerPlacement`: one shape, one class
 * (tmesis v0.6.0), read off `MarkerRecord::placements()` and
 * `Events\MarkerPlaced::placements()` alike.
 */
final readonly class MarkerPlacement
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

    /**
     * @param  mixed  $items  a `placements` array as it arrived
     * @return list<self>
     */
    public static function listFrom(mixed $items): array
    {
        $out = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = self::fromArray($item);
            }
        }

        return $out;
    }

    /**
     * The rung whose playlists carry the tag.
     */
    public function rendition(): string
    {
        $value = $this->raw['rendition'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The segment the tag was written in front of: its file name under `<rung>/`.
     */
    public function segmentIndex(): int
    {
        return $this->int('segment_index');
    }

    public function mediaSequence(): int
    {
        return $this->int('media_sequence');
    }

    /**
     * That segment's EXT-X-PROGRAM-DATE-TIME. The tag's START-DATE is the boundary at
     * its END for a now marker, or the told instant.
     */
    public function programDateTimeRaw(): string
    {
        $value = $this->raw['program_date_time'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function programDateTime(): DateTimeImmutable
    {
        return Rfc3339::parse($this->programDateTimeRaw());
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
