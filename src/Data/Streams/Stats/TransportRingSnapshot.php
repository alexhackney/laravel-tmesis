<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

/**
 * The ring behind one transport rung, counted in fragments and in bytes.
 */
final readonly class TransportRingSnapshot
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

    public function capacityBytes(): int
    {
        return $this->int('capacity_bytes');
    }

    public function capacityFragments(): int
    {
        return $this->int('capacity_fragments');
    }

    public function depthBytes(): int
    {
        return $this->int('depth_bytes');
    }

    public function depthFragments(): int
    {
        return $this->int('depth_fragments');
    }

    public function droppedBytesTotal(): int
    {
        return $this->int('dropped_bytes_total');
    }

    public function droppedFragmentsTotal(): int
    {
        return $this->int('dropped_fragments_total');
    }

    public function droppedKeyframesTotal(): int
    {
        return $this->int('dropped_keyframes_total');
    }

    public function oversizeAdmittedTotal(): int
    {
        return $this->int('oversize_admitted_total');
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
