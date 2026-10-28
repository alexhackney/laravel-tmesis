<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Keys\SessionEpoch;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The current publish session for a stream.
 *
 * sessionEpoch() is the SESSION epoch, the node's local publish generation, and
 * it is not the key epoch the publisher presented.
 */
final readonly class SessionSnapshot
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

    public function closedAtRaw(): ?string
    {
        return $this->nullableString('closed_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function closedAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('closed_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * When the publisher left and the session was held (stream.input_lost), raw.
     * Null while a publisher is attached. Since Tmesis rc5.
     */
    public function heldSinceRaw(): ?string
    {
        return $this->nullableString('held_since');
    }

    /**
     * Parsed. Null while a publisher is attached.
     */
    public function heldSince(): ?DateTimeImmutable
    {
        $value = $this->nullableString('held_since');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function id(): string
    {
        return $this->string('id');
    }

    /**
     * The SRT receive latency this publisher's connection was negotiated to, in
     * milliseconds: the larger of the node's `ingest.srt.latency_ms` floor and the
     * publisher's own setting. 0 for RTMP, which has no such window. Since Tmesis rc7.
     */
    public function latencyMs(): int
    {
        return $this->int('latency_ms');
    }

    public function protocol(): string
    {
        return $this->string('protocol');
    }

    public function reconnects(): int
    {
        return $this->int('reconnects');
    }

    public function remote(): string
    {
        return $this->string('remote');
    }

    /**
     * The SESSION epoch: the node's publish generation for this stream, raised every
     * time a publisher attaches. Not the key epoch the publisher presented.
     */
    public function sessionEpoch(): SessionEpoch
    {
        return SessionEpoch::fromInt($this->int('session_epoch'));
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

    public function uptimeSeconds(): float
    {
        return $this->float('uptime_seconds');
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
