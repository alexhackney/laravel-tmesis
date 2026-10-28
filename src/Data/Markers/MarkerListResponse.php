<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Markers;

/**
 * GET /api/v1/streams/:id/markers: every marker told on the stream, EVERY session,
 * oldest first; empty when none. The openapi component MarkerListResponse (tmesis
 * v0.6.0).
 */
final readonly class MarkerListResponse
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
     * @return list<MarkerRecord>
     */
    public function markers(): array
    {
        $out = [];
        $value = $this->raw['markers'] ?? null;

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = MarkerRecord::fromArray($item);
            }
        }

        return $out;
    }

    /**
     * The marker with this id, the newest session's first, or null. Ids are unique
     * per SESSION, so an id reused by a later session names the later marker here.
     */
    public function find(string $id, ?string $session = null): ?MarkerRecord
    {
        foreach (array_reverse($this->markers()) as $marker) {
            if ($marker->id() === $id && ($session === null || $marker->session() === $session)) {
                return $marker;
            }
        }

        return null;
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
