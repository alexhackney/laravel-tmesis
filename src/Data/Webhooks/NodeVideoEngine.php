<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * One workload's encoder, on `node.online` and `node.capacity_changed` (`v0.9.0`,
 * handover 3.4).
 *
 * The `$defs.NodeVideoEngine` shape of both schemas: the health `video_engine` block
 * with the two pin fields. The live ladder's is `video_engine`, the VOD jobs' is
 * `jobs_video_engine`, and each is pinned by its own config key
 * (`encode.video_encoder`, `jobs.video_encoder`). A pin that fails at boot is NEVER
 * replaced by a fallback: usable() is false and that workload is refused until the
 * pin is repaired or set to `auto` and tmesis restarts.
 */
final readonly class NodeVideoEngine
{
    public const ACCELERATOR_NONE = 'none';

    public const ACCELERATOR_NVENC = 'nvenc';

    public const ACCELERATOR_QSV = 'qsv';

    public const PLACEMENT_NONE = 'none';

    public const PLACEMENT_DISCRETE = 'discrete';

    public const PLACEMENT_INTEGRATED = 'integrated';

    public const PLACEMENT_UNKNOWN = 'unknown';

    /**
     * The probe chooses. Every other value is a pin.
     */
    public const REQUESTED_AUTO = 'auto';

    public const REQUESTED_LIBX264 = 'libx264';

    public const REQUESTED_H264_NVENC = 'h264_nvenc';

    public const REQUESTED_H264_QSV = 'h264_qsv';

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

    /**
     * The ffmpeg encoder the workload is built with. Under a failed pin this names
     * the PIN, never a fallback.
     */
    public function encoder(): string
    {
        return $this->string('encoder');
    }

    public function preset(): string
    {
        return $this->string('preset');
    }

    /**
     * CLOSED set: the ACCELERATOR_* constants.
     */
    public function accelerator(): string
    {
        return $this->string('accelerator');
    }

    /**
     * CLOSED set: the PLACEMENT_* constants. `unknown` is a real answer, never a
     * default.
     */
    public function placement(): string
    {
        return $this->string('placement');
    }

    /**
     * TRUE when the box HAS an acceleration device and encodes on the CPU anyway: an
     * alert, not information.
     */
    public function fellBackWithDeviceAvailable(): bool
    {
        return (bool) ($this->raw['fell_back_with_device_available'] ?? false);
    }

    /**
     * One sentence saying why this encoder and not another.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * The config value, CLOSED set: the REQUESTED_* constants (`v0.9.0`).
     */
    public function requested(): string
    {
        return $this->string('requested');
    }

    /**
     * Whether a config key pinned this encoder rather than leaving it to the probe.
     */
    public function isPinned(): bool
    {
        return $this->requested() !== self::REQUESTED_AUTO;
    }

    /**
     * False ONLY for a pin that failed at boot (`v0.9.0`). Alert on it: a live pin
     * that is not usable refuses every publish `encoder_unavailable`, a jobs pin
     * answers the job submit `503 encoder_unavailable`, and neither clears by
     * waiting. Absent reads as true, which is what every pre-`v0.9.0` node meant.
     */
    public function usable(): bool
    {
        $value = $this->raw['usable'] ?? null;

        return is_bool($value) ? $value : true;
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
}
