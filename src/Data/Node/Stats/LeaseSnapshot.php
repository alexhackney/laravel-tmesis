<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node\Stats;

use AlexHackney\Tmesis\Keys\FencingEpoch;

/**
 * One fencing lease held by this node.
 *
 * fencingEpoch() is the FENCING epoch, one of the three things the contract
 * calls epoch; the other two are KeyEpoch and SessionEpoch.
 */
final readonly class LeaseSnapshot
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

    public function fencedTotal(): int
    {
        return $this->int('fenced_total');
    }

    /**
     * The FENCING epoch: which generation of control plane owns this lease. Typed,
     * because the contract calls three different numbers "epoch" and merging them
     * is how a stale control plane fences a live one.
     */
    public function fencingEpoch(): FencingEpoch
    {
        return FencingEpoch::fromInt($this->int('fencing_epoch'));
    }

    public function held(): bool
    {
        return $this->bool('held');
    }

    public function prefix(): string
    {
        return $this->string('prefix');
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
