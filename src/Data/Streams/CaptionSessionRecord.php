<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The provider session for the current publish, nested in the record's `captions`
 * as `session`. Present only on a single-stream read that sent `include=captions`
 * (`getStream($id, includeCaptions: true)`) AND only while captions are on for the
 * stream; otherwise the key is absent, never null.
 *
 * The stats body's `CaptionSnapshot` is a SIBLING of this block, not the same block
 * under another name (finding F1, confirmed by tmesis 2026-09-23; `docs/API.md` 9.5
 * "The stats block is not this block"): this one calls the
 * request id `provider_request_id` and lets it, `started_at` and `reason` be null,
 * can read `idle`, and counts `finals` differently: see finals().
 *
 * @see CaptionSessionRecord in docs/contract/openapi.json at tmesis v0.3.0.
 */
final readonly class CaptionSessionRecord
{
    public const STATE_IDLE = 'idle';

    public const STATE_CONNECTING = 'connecting';

    public const STATE_LIVE = 'live';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_ENDED = 'ended';

    /**
     * The closed set the schema enumerates.
     */
    public const STATES = [
        self::STATE_IDLE,
        self::STATE_CONNECTING,
        self::STATE_LIVE,
        self::STATE_DEGRADED,
        self::STATE_ENDED,
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

    /**
     * One of STATES. `idle` is no caption session running: the stream is not publishing,
     * captions are off, or (since tmesis `v0.9.3`) captions are on while the box's
     * captioned capacity is full and no fallback can take the stream, which a
     * `captions.skipped` timeline event names. Since tmesis `v0.9.1`, K8, `degraded`
     * returns to live by itself unless its reason is auth_error or quota_exceeded, and
     * since `v0.9.3` its reason can also be `stalled` (a connected provider answering
     * none of the flowing audio, or answering too late; never an outage since `v0.9.4`); a fallback may restore captions after
     * either refusal. Since `v0.9.5` a failover onto a self-hosted fallback refused at
     * captioned capacity leaves the stream on its primary (degraded after a degradation,
     * still retrying before one) until a slot frees, named by a `captions.skipped`
     * timeline event and no webhook. Connecting includes a provider switch; ended means
     * the session closed with the show.
     */
    public function state(): string
    {
        $value = $this->raw['state'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The provider's name for the socket. Null until the provider has named it
     * (Deepgram does so in a message AFTER the open).
     */
    public function providerRequestId(): ?string
    {
        return $this->nullableString('provider_request_id');
    }

    public function provider(): ?string
    {
        return $this->nullableString('provider');
    }

    public function fallback(): ?bool
    {
        $value = $this->raw['fallback'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * RFC3339 UTC, docs/API.md section 4. Null before the session has started.
     */
    public function startedAtRaw(): ?string
    {
        return $this->nullableString('started_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function startedAt(): ?DateTimeImmutable
    {
        $value = $this->startedAtRaw();

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * Final cues JOURNALED so far (`internal/node/captions.go` `captionRecord`). NOT the
     * same count as `CaptionSnapshot::finals()` on the stats body, which is the
     * captioner's own count of results placed on the timeline; the two can differ for
     * the same session at the same moment (F1).
     */
    public function finals(): int
    {
        return $this->int('finals');
    }

    /**
     * Milliseconds of audio that never reached the provider.
     */
    public function droppedPcmMs(): int
    {
        return $this->int('dropped_pcm_ms');
    }

    public function reconnects(): int
    {
        return $this->int('reconnects');
    }

    /**
     * The degraded or ended reason, in `caption.session_ended`'s vocabulary
     * (`CaptionSessionEnded::ENDED_REASONS`). Null while the session is idle,
     * connecting or live, where `CaptionSnapshot::reason()` on the stats body reads
     * `''`. BY DESIGN (F4, confirmed by tmesis 2026-09-23), not a gap to fill.
     */
    public function reason(): ?string
    {
        return $this->nullableString('reason');
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
}
