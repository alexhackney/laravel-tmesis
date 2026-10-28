<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * What the node probed the source to be before it resolved the ladder, on
 * `stream.live` under `ladder_resolved.source`.
 *
 * The `$defs.SourceSpec` shape of `stream.live.schema.json`. `codec`, `fps` and
 * `audio_codec` are nullable: a probe that failed leaves every rung as written, and
 * a rate that did not snap to the standard set is reported as null rather than as a
 * guess.
 */
final readonly class SourceSpec
{
    /**
     * The `audio_codec` value for a source the probe found NO audio track on. Not
     * null: null means the source was not probed at all.
     */
    public const AUDIO_NONE = 'none';

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

    public function codec(): ?string
    {
        return $this->nullableString('codec');
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
     * The source rate snapped to the standard set, as a rational string
     * (`30000/1001`, `60`). Null when it did not snap and the ladder's rates applied
     * as written.
     */
    public function fps(): ?string
    {
        return $this->nullableString('fps');
    }

    /**
     * The source's audio codec in ffprobe's spelling (`aac`, `opus`, `mp3`); `none`
     * (AUDIO_NONE) when the probe found no audio track; null when the source was not
     * probed. Since tmesis `v0.4.0` (4.2 / EDGE-5): it is what decides whether a
     * `rung:<name>` relay can carry the source audio without a second encode.
     */
    public function audioCodec(): ?string
    {
        return $this->nullableString('audio_codec');
    }

    /**
     * The ingest block's window rate, repeated so the specs read as one block.
     */
    public function bitrateBps(): float
    {
        return $this->float('bitrate_bps');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function probedAtRaw(): string
    {
        return $this->string('probed_at');
    }

    public function probedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('probed_at'));
    }

    public function probeMs(): int
    {
        return $this->int('probe_ms');
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

    private function nullableString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
