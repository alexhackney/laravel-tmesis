<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One recording artifact for this stream, still running or finished.
 */
final readonly class RecordingSnapshot
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

    public function artifact(): string
    {
        return $this->string('artifact');
    }

    public function bytesTotal(): int
    {
        return $this->int('bytes_total');
    }

    /**
     * `mpegts` (an SRT publish) or `flv` (an RTMP publish) for the ingest copy: the
     * capture container, never remuxed. `mp4` is the master's, M4.
     */
    public function container(): string
    {
        return $this->string('container');
    }

    public function droppedBytes(): int
    {
        return $this->int('dropped_bytes');
    }

    public function durationSeconds(): float
    {
        return $this->float('duration_seconds');
    }

    public function endedAtRaw(): ?string
    {
        return $this->nullableString('ended_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function endedAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('ended_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function part(): int
    {
        return $this->int('part');
    }

    /**
     * The node's LOCAL file. Not a URL and not fetchable over the API. Since Tmesis
     * rc7.
     */
    public function path(): string
    {
        return $this->string('path');
    }

    public function recordingId(): string
    {
        return $this->string('recording_id');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function startedAtRaw(): string
    {
        return $this->string('started_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function startedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('started_at'));
    }

    public function state(): string
    {
        return $this->string('state');
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
