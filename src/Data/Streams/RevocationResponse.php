<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The body of POST /api/v1/keys/revocations.
 *
 * @see RevocationResponse in docs/contract/openapi.json at v0.1.0-rc2.
 *
 * `count()` is the size of the list NOW IN FORCE, which is the whole list that was
 * pushed: the push replaces wholesale rather than merging. A count smaller than the
 * list sent is a node that refused entries, not a partial success.
 */
final readonly class RevocationResponse
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

    public function count(): int
    {
        $value = $this->raw['count'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
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
