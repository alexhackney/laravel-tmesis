<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Markers;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One marker told on a stream, as the node holds it. The openapi component
 * MarkerRecord (tmesis v0.6.0, `docs/API.md` 9.8a).
 *
 * `queued` waits for the next cut, `placed` is in the live, DVR and replay playlists,
 * and `expired` was still queued when its session ended, so it never landed. Model a
 * marker as queued on the `202` and LANDED on `marker.placed`, never on the POST.
 */
final readonly class MarkerRecord
{
    public const STATE_QUEUED = 'queued';

    public const STATE_PLACED = 'placed';

    public const STATE_EXPIRED = 'expired';

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

    public function id(): string
    {
        return $this->string('id');
    }

    public function stream(): string
    {
        return $this->string('stream');
    }

    /**
     * The publish session the marker was told to: idempotency is per session.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    /**
     * `out` or `in` (the `MarkerRequest::KIND_*` constants).
     */
    public function kind(): string
    {
        return $this->string('kind');
    }

    /**
     * The tag's CLASS.
     */
    public function class(): string
    {
        return $this->string('class');
    }

    /**
     * The told instant; null for now.
     */
    public function atRaw(): ?string
    {
        return $this->nullableString('at');
    }

    public function at(): ?DateTimeImmutable
    {
        return self::parse($this->atRaw());
    }

    public function durationSeconds(): ?float
    {
        $value = $this->raw['duration_seconds'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The told splice_info_section, upper case hex without `0x`; null for none.
     */
    public function scte35(): ?string
    {
        return $this->nullableString('scte35');
    }

    /**
     * `queued`, `placed` or `expired` (the `STATE_*` constants).
     */
    public function state(): string
    {
        return $this->string('state');
    }

    public function isQueued(): bool
    {
        return $this->state() === self::STATE_QUEUED;
    }

    public function isPlaced(): bool
    {
        return $this->state() === self::STATE_PLACED;
    }

    public function isExpired(): bool
    {
        return $this->state() === self::STATE_EXPIRED;
    }

    /**
     * The tag's START-DATE, identical on every rendition. Null until placed.
     */
    public function startDateRaw(): ?string
    {
        return $this->nullableString('start_date');
    }

    public function startDate(): ?DateTimeImmutable
    {
        return self::parse($this->startDateRaw());
    }

    /**
     * When the node recorded the landing. Null until placed.
     */
    public function placedAtRaw(): ?string
    {
        return $this->nullableString('placed_at');
    }

    public function placedAt(): ?DateTimeImmutable
    {
        return self::parse($this->placedAtRaw());
    }

    /**
     * Every media rendition carries the tag. False on a placed marker means the
     * session drained before every rendition cut again; placements() says which do.
     */
    public function complete(): bool
    {
        return ($this->raw['complete'] ?? false) === true;
    }

    /**
     * One per rendition carrying the tag; empty until placed.
     *
     * @return list<MarkerPlacement>
     */
    public function placements(): array
    {
        return MarkerPlacement::listFrom($this->raw['placements'] ?? null);
    }

    public function createdAtRaw(): string
    {
        return $this->string('created_at');
    }

    public function createdAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('created_at'));
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

    private static function parse(?string $value): ?DateTimeImmutable
    {
        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }
}
