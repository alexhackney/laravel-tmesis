<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * One page of a generation's transfer objects. The openapi component TransferPage
 * (tmesis `v0.5.0`, `docs/API.md` 12a.4), ordered `(target_id, object_key)` ascending,
 * each with its exact mapped key.
 *
 * `nextCursor()` is opaque and null on the last page. It is bound to the route, the
 * stream, the generation AND the plan revision read: a cursor from a replaced revision
 * is `409 transfer_plan_stale`, one from another scope is `400`. The resource's walker
 * follows it for you.
 */
final readonly class TransferPage
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
     * @return list<ObjectStatus>
     */
    public function items(): array
    {
        $value = $this->raw['items'] ?? null;
        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = ObjectStatus::fromArray($item);
            }
        }

        return $out;
    }

    /**
     * The page size that applied: the node clamps anything above 1000.
     */
    public function limit(): int
    {
        $value = $this->raw['limit'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Send it back as `cursor` for the next page; null on the last one.
     */
    public function nextCursor(): ?string
    {
        $value = $this->raw['next_cursor'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
}
