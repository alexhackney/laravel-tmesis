<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One transfer plan revision: resolved, secret-free target snapshots with per-target
 * counts. The openapi component PlanStatus (tmesis `v0.5.0`, `docs/API.md` 12a.3), the
 * body of `getTransferPlan` and `replaceTransferPlan`.
 *
 * Revision 0 is the empty baseline written at finalisation, never a release.
 */
final readonly class PlanStatus
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private array $raw,
        /**
         * Whether THIS call created revision 1: `replaceTransferPlan` answered `201`
         * with a `Location`.
         *
         * From the status code, the only place the fact exists. A `200` is EITHER a
         * later revision this call created OR an exact duplicate of an accepted
         * request, which hands back its original revision with a truthful
         * `current()`. The two are the same outcome for the caller, which is what
         * makes the retry safe. Always false on a read.
         */
        public bool $created = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $created = false): self
    {
        return new self($data, $created);
    }

    public function generation(): string
    {
        return $this->string('generation');
    }

    public function revision(): int
    {
        $value = $this->raw['revision'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The request that created this revision; `baseline` for revision 0.
     */
    public function requestId(): string
    {
        return $this->string('request_id');
    }

    public function inventorySha256(): string
    {
        return $this->string('inventory_sha256');
    }

    /**
     * Whether this revision is still the current one. An exact duplicate returns its
     * historical revision, and this says whether a later one replaced it.
     */
    public function current(): bool
    {
        return (bool) ($this->raw['current'] ?? false);
    }

    /**
     * In the order the plan named them; the first is the registration target of a
     * recording awaiting remote registration.
     *
     * @return list<PlanTargetStatus>
     */
    public function targets(): array
    {
        $value = $this->raw['targets'] ?? null;
        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = PlanTargetStatus::fromArray($item);
            }
        }

        return $out;
    }

    public function counts(): TransferCounts
    {
        $value = $this->raw['counts'] ?? null;

        /** @var array<string, mixed> $counts */
        $counts = is_array($value) ? $value : [];

        return TransferCounts::fromArray($counts);
    }

    public function createdAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('created_at'));
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
}
