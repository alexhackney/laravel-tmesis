<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * What this node actually encodes with. fell_back_with_device_available()
 * true is an alert rather than information: an automatic CPU fallback
 * silently turns a multi-slot GPU box into a two-slot one.
 *
 * The same component serves health `video_engine` (the live ladder,
 * `encode.video_encoder`) and `jobs_video_engine` (VOD jobs, `jobs.video_encoder`)
 * since tmesis `v0.9.0`. `usable()` false is the other alert.
 */
final readonly class HealthVideoEngine
{
    public const REQUESTED_AUTO = 'auto';

    public const REQUESTED_LIBX264 = 'libx264';

    public const REQUESTED_H264_NVENC = 'h264_nvenc';

    public const REQUESTED_H264_QSV = 'h264_qsv';

    /**
     * The closed `requested` enum (tmesis `v0.9.0`), held equal to the schema by its
     * test.
     */
    public const REQUESTED = [
        self::REQUESTED_AUTO,
        self::REQUESTED_LIBX264,
        self::REQUESTED_H264_NVENC,
        self::REQUESTED_H264_QSV,
    ];

    public const ACCELERATOR_NONE = 'none';

    public const ACCELERATOR_NVENC = 'nvenc';

    public const ACCELERATOR_QSV = 'qsv';

    public const PLACEMENT_NONE = 'none';

    public const PLACEMENT_DISCRETE = 'discrete';

    public const PLACEMENT_INTEGRATED = 'integrated';

    public const PLACEMENT_UNKNOWN = 'unknown';

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
     * CLOSED set. Both Intel targets (Arc and Quick Sync) are qsv: they are the
     * same encoder and differ in placement.
     */
    public function accelerator(): string
    {
        return $this->string('accelerator');
    }

    /**
     * The ffmpeg encoder every video rung is built with.
     */
    public function encoder(): string
    {
        return $this->string('encoder');
    }

    /**
     * TRUE when this box HAS an acceleration device and is encoding on the CPU
     * anyway. It is the one field here worth alerting on: that box passes every
     * other health check and is wrong only in capacity.
     */
    public function fellBackWithDeviceAvailable(): bool
    {
        return $this->bool('fell_back_with_device_available');
    }

    /**
     * CLOSED set. Where the device sits. unknown is a real answer and never a
     * default: a box whose topology could not be read must not have its capacity
     * filed under the wrong kind of GPU.
     */
    public function placement(): string
    {
        return $this->string('placement');
    }

    /**
     * The DISTINCT set of presets the ladder's video rungs map to in the chosen
     * encoder's own vocabulary, comma separated in ladder order, and one value
     * when the ladder uses one. NOT the ladder's own preset except on libx264.
     */
    public function preset(): string
    {
        return $this->string('preset');
    }

    /**
     * One sentence saying why this encoder and not another.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * CLOSED set (tmesis `v0.9.0`, handover 3.4). The config value: `auto`, or the
     * pinned encoder.
     */
    public function requested(): string
    {
        return $this->string('requested');
    }

    /**
     * Whether the encoder is pinned rather than chosen by the probe.
     */
    public function pinned(): bool
    {
        return $this->requested() !== '' && $this->requested() !== self::REQUESTED_AUTO;
    }

    /**
     * False ONLY for a pin that failed at boot (tmesis `v0.9.0`, handover 3.4).
     * `encoder()` then names the PIN, never a fallback, and the workload is refused
     * (publishes `encoder_unavailable`, job submissions `503 encoder_unavailable`)
     * until the pin is repaired or set to `auto` and tmesis restarts. Alert on it:
     * it does not clear by waiting.
     *
     * True when absent: a node before `v0.9.0` had no pins, so nothing it encoded
     * with could have failed one.
     */
    public function usable(): bool
    {
        $value = $this->raw['usable'] ?? null;

        return is_bool($value) ? $value : true;
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

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
