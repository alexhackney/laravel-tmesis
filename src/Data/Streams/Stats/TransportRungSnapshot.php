<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One first-party transport rung while an encoder is running: its loopback
 * socket, its ring, and what it has carried.
 *
 * url() is the loopback listener the encoder was told to write to. It is bound
 * to 127.0.0.1 and carries no credential, so it is safe to log, unlike a relay
 * destination.
 *
 * Since rc7 the low-delay preview tap's two halves are rows here too, named
 * `_preview_video` and `_preview_audio`. They are never packaged and no rendition
 * can be named with a leading underscore, so isPreviewTap() is the one place that
 * rule lives (decision D8).
 */
final readonly class TransportRungSnapshot
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

    public function connected(): bool
    {
        return $this->bool('connected');
    }

    public function discardedBytesTotal(): int
    {
        return $this->int('discarded_bytes_total');
    }

    public function fragmentBytesTotal(): int
    {
        return $this->int('fragment_bytes_total');
    }

    public function fragmentsTotal(): int
    {
        return $this->int('fragments_total');
    }

    public function haveInitSegment(): bool
    {
        return $this->bool('have_init_segment');
    }

    public function initSegmentBytes(): int
    {
        return $this->int('init_segment_bytes');
    }

    public function lastFragmentAtRaw(): ?string
    {
        return $this->nullableString('last_fragment_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastFragmentAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_fragment_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function outcome(): string
    {
        return $this->string('outcome');
    }

    public function rejectedConnectionsTotal(): int
    {
        return $this->int('rejected_connections_total');
    }

    public function ring(): TransportRingSnapshot
    {
        return TransportRingSnapshot::fromArray($this->array('ring'));
    }

    public function rung(): string
    {
        return $this->string('rung');
    }

    /**
     * Whether this row is one half of the preview tap rather than a ladder rung: the
     * rung name begins with `_`. A consumer building the rendition list skips these.
     */
    public function isPreviewTap(): bool
    {
        return str_starts_with($this->rung(), '_');
    }

    public function secondsSinceLastFragment(): ?float
    {
        return $this->nullableFloat('seconds_since_last_fragment');
    }

    public function socketBytesTotal(): int
    {
        return $this->int('socket_bytes_total');
    }

    public function tracks(): int
    {
        return $this->int('tracks');
    }

    /**
     * The loopback listener the encoder was told to write to. No credential rides in it.
     */
    public function url(): string
    {
        return $this->string('url');
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

    private function nullableFloat(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
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
