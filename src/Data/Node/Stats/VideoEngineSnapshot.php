<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node\Stats;

/**
 * What the node encodes with: the live ladder (NodeStats::videoEngine()) or, since
 * `v0.9.0`, VOD jobs (NodeStats::jobsVideoEngine()), the same fields for both.
 *
 * NodeStats::videoEngine() is null rather than a zeroed block when the node
 * encodes nothing at all: "this box runs libx264" and "this box encodes
 * nothing" are different facts.
 */
final readonly class VideoEngineSnapshot
{
    /** No pin: the node picked the encoder itself (`v0.9.0`, handover 3.4). */
    public const REQUESTED_AUTO = 'auto';

    public const REQUESTED_LIBX264 = 'libx264';

    public const REQUESTED_H264_NVENC = 'h264_nvenc';

    public const REQUESTED_H264_QSV = 'h264_qsv';

    /**
     * The config values a pin can name. The stats component types `requested` as a
     * bare string; this is the enum the health and `node.online` blocks close it to.
     *
     * @var list<string>
     */
    public const REQUESTED = [
        self::REQUESTED_AUTO,
        self::REQUESTED_LIBX264,
        self::REQUESTED_H264_NVENC,
        self::REQUESTED_H264_QSV,
    ];

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

    public function accelerator(): string
    {
        return $this->string('accelerator');
    }

    public function device(): string
    {
        return $this->string('device');
    }

    public function encoder(): string
    {
        return $this->string('encoder');
    }

    public function fellBackWithDeviceAvailable(): bool
    {
        return $this->bool('fell_back_with_device_available');
    }

    public function placement(): string
    {
        return $this->string('placement');
    }

    public function preset(): string
    {
        return $this->string('preset');
    }

    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * The config value (`v0.9.0`, handover 3.4): `auto`, or the pinned encoder
     * (`encode.video_encoder` for the live block, `jobs.video_encoder` for the
     * jobs block). One of REQUESTED, never refused here: the stats component does
     * not close the set.
     */
    public function requested(): string
    {
        return $this->string('requested');
    }

    /**
     * False ONLY for a pin that failed at boot (`v0.9.0`, handover 3.4). The node
     * never falls back from a failed pin: encoder() then names the PIN, and the
     * workload this block encodes is refused until the pin is repaired or set to
     * `auto` and the node restarts. Alert on it.
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
