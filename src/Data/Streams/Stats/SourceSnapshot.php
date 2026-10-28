<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * What the node probed the source to be for the current session, on
 * `GET /streams/{id}/stats` as `source`.
 *
 * The same eight fields `stream.live` carries under `ladder_resolved.source` (seven
 * before tmesis `v0.4.0` added `audio_codec`), kept as the stats body's own
 * component. The envelope's `source` is null before the first publish of a session
 * probed anything, and for a passthrough stream (no ladder), which never probes.
 */
final readonly class SourceSnapshot
{
    /**
     * The `audio_codec` value for a source the probe found NO audio track on. Not
     * null: null means the source was not probed at all.
     */
    public const AUDIO_NONE = 'none';

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
     * The source rate snapped to the standard set, as a rational string. Null when it
     * did not snap and the ladder's rates applied as written.
     */
    public function fps(): ?string
    {
        return $this->nullableString('fps');
    }

    /**
     * The source's audio codec in ffprobe's spelling (`aac`, `opus`, `mp3`); `none`
     * (AUDIO_NONE) when the probe found no audio track; null when the source was not
     * probed. Since tmesis `v0.4.0`.
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
     * The response as it arrived, unknown fields included.
     *
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
