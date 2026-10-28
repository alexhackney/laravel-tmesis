<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

/**
 * The low-delay preview tap's counters for this stream, on
 * `GET /streams/{id}/stats` as `preview`.
 *
 * The envelope's `preview` is null for a stream that has never had a tap armed, so
 * `StreamStats::preview()` is nullable and this class only ever describes a tap that
 * exists. The tap's two halves also appear in `transport[]` as `_preview_video` and
 * `_preview_audio` (`TransportRungSnapshot::isPreviewTap()`); they are never packaged.
 *
 * @see PreviewSnapshot in docs/contract/openapi.json at tmesis v0.2.0.
 */
final readonly class PreviewSnapshot
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
     * Whether the stream's effective `preview.enabled` is on. A tap can be armed and
     * have no monitor watching.
     */
    public function enabled(): bool
    {
        return (bool) ($this->raw['enabled'] ?? false);
    }

    /**
     * WHEP monitors watching right now.
     */
    public function monitors(): int
    {
        return $this->int('monitors');
    }

    /**
     * The stream's effective `preview.max_monitors`.
     */
    public function maxMonitors(): int
    {
        return $this->int('max_monitors');
    }

    public function framesVideoTotal(): int
    {
        return $this->int('frames_video_total');
    }

    public function framesAudioTotal(): int
    {
        return $this->int('frames_audio_total');
    }

    /**
     * Frames the tap shed because a monitor could not keep up. The live path is never
     * held for a preview.
     */
    public function tapDroppedTotal(): int
    {
        return $this->int('tap_dropped_total');
    }

    public function sendErrorsTotal(): int
    {
        return $this->int('send_errors_total');
    }

    /**
     * WHEP sessions joined over the life of the stream, watching or not.
     */
    public function sessionsTotal(): int
    {
        return $this->int('sessions_total');
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
}
