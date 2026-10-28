<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The `preview` block on a StreamRecord: the EFFECTIVE low-delay preview settings,
 * resolved through the node's `preview.*` config (CONFIG.md 6.15) for every value
 * the stream did not set itself.
 *
 * @see PreviewRecord in docs/contract/openapi.json at tmesis v0.2.0.
 */
final readonly class PreviewRecord
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
     * Whether the session's encoder runs a preview tap. Changing it is a
     * `next_session` change: the tap is an output of the session's ffmpeg.
     */
    public function enabled(): bool
    {
        return $this->bool('enabled');
    }

    /**
     * How many WHEP monitors may watch at once. 50 by default; read at every join, so
     * lowering it refuses the next monitor and never kicks one already watching.
     */
    public function maxMonitors(): int
    {
        return $this->int('max_monitors');
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

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
