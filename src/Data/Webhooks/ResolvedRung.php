<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * One rung as the encoder was actually told to run it, on `stream.live` under
 * `ladder_resolved.rungs[]`.
 *
 * The `$defs.ResolvedRung` shape of `stream.live.schema.json`. The configured
 * ladder is a set of CEILINGS: a rung is never upscaled above the source and its
 * rate is never raised above the source rate, so these values can be smaller than
 * the ladder the stream was provisioned with. An audio-only rung reports zero
 * dimensions and an empty rate.
 */
final readonly class ResolvedRung
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function name(): string
    {
        return $this->string('name');
    }

    public function width(): int
    {
        return $this->int('width');
    }

    public function height(): int
    {
        return $this->int('height');
    }

    /**
     * The rate this rung was pinned to, as a rational string. Empty for an
     * audio-only rung.
     */
    public function fps(): string
    {
        return $this->string('fps');
    }

    public function audioOnly(): bool
    {
        return (bool) ($this->raw['audio_only'] ?? false);
    }

    /**
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
}
