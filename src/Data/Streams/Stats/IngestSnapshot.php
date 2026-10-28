<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * What arrived at the ingest socket for the current session.
 */
final readonly class IngestSnapshot
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
     * a rate over rate_window_seconds, recomputed on every read; NOT an average since the session started
     */
    public function bitrateBps(): float
    {
        return $this->float('bitrate_bps');
    }

    /**
     * The largest wall-clock gap between two reads off the publisher over
     * rateWindowSeconds(), or null before two reads or once the window holds none
     * (`v0.9.0`, handover 3.8). REQUIRED and nullable. Read it beside
     * `encode[].speed_ratio`: a long gap with the speed dipping is late input, not a
     * slow encoder.
     */
    public function arrivalGapMaxSeconds(): ?float
    {
        return $this->nullableFloat('arrival_gap_max_seconds');
    }

    public function bytesTotal(): int
    {
        return $this->int('bytes_total');
    }

    /**
     * Payload bytes of inbound media dropped. SRT loss is an estimate because late SRT
     * packets carry no byte count; RTMP overflow is the ingest ring's eviction. Until
     * tmesis v0.9.0 this field repeated dropped_packets_total, so a value read from a
     * pre-v0.9.1 node is packets.
     */
    public function droppedBytesTotal(): int
    {
        return $this->int('dropped_bytes_total');
    }

    /**
     * Inbound packets the transport reported lost, late, discarded or undecryptable
     * (SRT) or evicted by the ingest ring (RTMP, reason `overflow`): the sum of
     * drops(), in packets. Since Tmesis rc7.
     */
    public function droppedPacketsTotal(): int
    {
        return $this->int('dropped_packets_total');
    }

    /**
     * @return list<DropSnapshot>
     */
    public function drops(): array
    {
        $out = [];

        foreach ($this->array('drops') as $item) {
            if (is_array($item)) {
                $out[] = DropSnapshot::fromArray($item);
            }
        }

        return $out;
    }

    public function lastPacketAtRaw(): ?string
    {
        return $this->nullableString('last_packet_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastPacketAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_packet_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * the window bitrate_bps is computed over
     */
    public function rateWindowSeconds(): int
    {
        return $this->int('rate_window_seconds');
    }

    public function secondsSinceLastPacket(): ?float
    {
        return $this->nullableFloat('seconds_since_last_packet');
    }

    /**
     * MPEG-TS continuity-counter errors in the received container, one per PID per
     * hole: the input-integrity signal, independent of the transport's own drop
     * counters. Always 0 on RTMP. Since Tmesis rc7.
     */
    public function tsCcErrorsTotal(): int
    {
        return $this->int('ts_cc_errors_total');
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

    private function nullableFloat(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
