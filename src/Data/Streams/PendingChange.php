<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * One field a PATCH accepted but has not applied yet.
 *
 * @see PendingChange in docs/contract/openapi.json at v0.1.0-rc2.
 *
 * `when()` is the whole point: `next_session` means the change is stored and takes
 * effect when the current publisher reconnects, so a control plane that reported it
 * as done would be describing a stream that is still running the old ladder.
 */
final readonly class PendingChange
{
    /** Applied now, to the running stream. */
    public const WHEN_LIVE = 'live';

    /** Applied at the next segment boundary. */
    public const WHEN_NEXT_SEGMENT = 'next_segment';

    /** Applied when the next publish session starts. */
    public const WHEN_NEXT_SESSION = 'next_session';

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
     * `live`, `next_segment` or `next_session`.
     */
    public function when(): string
    {
        $value = $this->raw['when'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The value in force right now. Any JSON value, including null.
     */
    public function current(): mixed
    {
        return $this->raw['current'] ?? null;
    }

    /**
     * The value that will be in force. Any JSON value, including null.
     */
    public function requested(): mixed
    {
        return $this->raw['requested'] ?? null;
    }

    /**
     * Why it cannot take effect now, in the node's own words.
     */
    public function reason(): string
    {
        $value = $this->raw['reason'] ?? null;

        return is_string($value) ? $value : '';
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
