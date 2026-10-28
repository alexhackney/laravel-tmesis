<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One encoder ring buffer: depth against capacity, and what it dropped when it
 * filled.
 *
 * The counts are named `*_fragments` on the wire, but since `v0.9.0` (handover
 * 3.8, F68) unit() says what they count: FRAGMENTS on every per-rung entry, and
 * BYTES on the `ingest` entry, whose counts always were bytes. The accessor names
 * follow the wire and do not change; read unit() before presenting one, or use
 * the unit-honest depth() / capacity() / droppedTotal() beside it.
 */
final readonly class RingSnapshot
{
    /** The counts on this entry are fragments: every per-rung ring. */
    public const UNIT_FRAGMENTS = 'fragments';

    /** The counts on this entry are bytes: the `ingest` ring. */
    public const UNIT_BYTES = 'bytes';

    /**
     * The closed `unit` set (`v0.9.0`, handover 3.8).
     *
     * @var list<string>
     */
    public const UNITS = [
        self::UNIT_FRAGMENTS,
        self::UNIT_BYTES,
    ];

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
     * What depth, capacity and the dropped total on this entry count, one of UNITS
     * (`v0.9.0`, handover 3.8): `bytes` on the `ingest` entry, `fragments` on every
     * rung. Absent (a node before `v0.9.0`) reads by the entry: `bytes` on the
     * `ingest` entry, whose counts were always bytes, and `fragments` on every rung.
     */
    public function unit(): string
    {
        $value = $this->raw['unit'] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->rung() === 'ingest' ? self::UNIT_BYTES : self::UNIT_FRAGMENTS;
    }

    public function isBytes(): bool
    {
        return $this->unit() === self::UNIT_BYTES;
    }

    /**
     * The wire's `capacity_fragments`: BYTES on the `ingest` entry (see unit()).
     */
    public function capacityFragments(): int
    {
        return $this->int('capacity_fragments');
    }

    /**
     * The wire's `depth_fragments`: BYTES on the `ingest` entry (see unit()).
     */
    public function depthFragments(): int
    {
        return $this->int('depth_fragments');
    }

    /**
     * The wire's `dropped_fragments_total`: BYTES on the `ingest` entry (see unit()).
     */
    public function droppedFragmentsTotal(): int
    {
        return $this->int('dropped_fragments_total');
    }

    /**
     * The ring's depth in unit(), whatever the wire calls it.
     */
    public function depth(): int
    {
        return $this->depthFragments();
    }

    /**
     * The ring's capacity in unit(), whatever the wire calls it.
     */
    public function capacity(): int
    {
        return $this->capacityFragments();
    }

    /**
     * What the ring dropped since the session started, in unit(), whatever the wire
     * calls it.
     */
    public function droppedTotal(): int
    {
        return $this->droppedFragmentsTotal();
    }

    /**
     * @return list<DropSnapshot>
     */
    public function drops(): array
    {
        $out = [];

        foreach ($this->array('drops') as $item) {
            if (is_array($item)) {
                $out[] = DropSnapshot::fromArray($item);
            }
        }

        return $out;
    }

    public function fillRatio(): float
    {
        return $this->float('fill_ratio');
    }

    public function lastDropAtRaw(): ?string
    {
        return $this->nullableString('last_drop_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastDropAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_drop_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function rung(): string
    {
        return $this->string('rung');
    }

    public function secondsSinceLastDrop(): ?float
    {
        return $this->nullableFloat('seconds_since_last_drop');
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
