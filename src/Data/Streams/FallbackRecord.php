<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The EFFECTIVE fallback settings on a StreamRecord. Since tmesis v0.6.0.
 *
 * Only `end_seconds` is acted on at this tag. `hold_seconds` (M4), `chain` and
 * `slate_asset` (M5) are reserved on the spec side and never appear here.
 *
 * @see FallbackRecord in docs/contract/openapi.json at tmesis v0.6.0.
 */
final readonly class FallbackRecord
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
     * Seconds a held session of this stream waits for its publisher to rejoin before
     * the node seals it: the stream's own value (`StreamSpec::fallbackEndSeconds()`),
     * else the node's `fallback.end_seconds` (3600 by default). Always a value.
     */
    public function endSeconds(): int
    {
        $value = $this->raw['end_seconds'] ?? null;

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
