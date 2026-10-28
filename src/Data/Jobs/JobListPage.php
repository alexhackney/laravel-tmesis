<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * One page of this node's jobs, `(submitted_at, id)` DESCENDING, terminal ones
 * included. The openapi component JobListPage (tmesis `v0.6.0`, `docs/API.md` 9.10a),
 * the body of `listTranscodeJobs`.
 *
 * The cursor is opaque and bound to the `state` filter it was issued under.
 */
final readonly class JobListPage
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
     * @return list<JobListItem>
     */
    public function items(): array
    {
        $out = [];

        foreach (is_array($this->raw['items'] ?? null) ? $this->raw['items'] : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = JobListItem::fromArray($item);
            }
        }

        return $out;
    }

    public function limit(): int
    {
        $value = $this->raw['limit'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Send it back as `cursor` for the next page; null on the last page.
     */
    public function nextCursor(): ?string
    {
        $value = $this->raw['next_cursor'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
