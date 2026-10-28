<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Markers;

/**
 * The answer to `placeMarker`. The openapi component MarkerResponse (tmesis v0.6.0).
 *
 * `$created` is true when THIS request queued the marker (`202`), false when the
 * same request under an id already on the stream answered the stored marker as it is
 * now (`200`, idempotent: queued, placed or expired, and nothing queued twice). The
 * `202` is NOT the landing; `marker.placed` is.
 */
final readonly class MarkerResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private array $raw,
        /**
         * Whether this call queued the marker: `202` rather than the idempotent `200`.
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

    public function marker(): MarkerRecord
    {
        $value = $this->raw['marker'] ?? null;

        /** @var array<string, mixed> $value */
        $value = is_array($value) ? $value : [];

        return MarkerRecord::fromArray($value);
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
