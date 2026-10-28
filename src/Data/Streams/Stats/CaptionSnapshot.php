<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

/**
 * The caption session's counters, on `GET /streams/{id}/stats` as `captions`.
 *
 * The envelope's `captions` is REQUIRED and null until a captioner has reported, so
 * `StreamStats::captions()` is nullable, this class never reads `idle`, and it
 * outlives the session's close for a late reader.
 *
 * A SIBLING of the record's `CaptionSessionRecord`, not the same block under another
 * name (finding F1, confirmed by tmesis 2026-09-23; `docs/API.md` 9.5 now carries the
 * six-row "The stats block is not this block" table). This one calls the request
 * id `request_id` (both carry `provider` since v0.9.2), declares every field non-null, `reason`
 * included, and counts `finals` differently: see finals(). Convergence would be
 * additive only and is not scheduled upstream.
 *
 * @see CaptionSnapshot in docs/contract/openapi.json at tmesis v0.3.0.
 */
final readonly class CaptionSnapshot
{
    public const STATE_CONNECTING = 'connecting';

    public const STATE_LIVE = 'live';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_ENDED = 'ended';

    /**
     * The closed set the schema enumerates. No `idle`: the block does not exist
     * before a captioner has reported.
     */
    public const STATES = [
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
     * One of STATES. Since tmesis `v0.9.1`, K8, degraded returns to live by itself
     * unless its reason is auth_error or quota_exceeded; ended means the session closed
     * with the show.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    /** The open-string STT provider: `deepgram` or `self_hosted` in tmesis v0.9.1. */
    public function provider(): string
    {
        return $this->string('provider');
    }

    /**
     * The provider's name for the socket (the record's `provider_request_id`).
     */
    public function requestId(): string
    {
        return $this->string('request_id');
    }

    /**
     * The CAPTIONER'S count of final results placed on the timeline
     * (`internal/node/captions.go` `captionStatsSnapshot`). NOT the same count as
     * `CaptionSessionRecord::finals()`, which is the JOURNALED count; the two can
     * differ for the same session at the same moment (F1).
     */
    public function finals(): int
    {
        return $this->int('finals');
    }

    public function droppedPcmMs(): int
    {
        return $this->int('dropped_pcm_ms');
    }

    public function reconnects(): int
    {
        return $this->int('reconnects');
    }

    /**
     * The degraded or ended reason in `caption.session_ended`'s vocabulary. The schema
     * declares a plain string, so a healthy session reads `''`, never null, where
     * `CaptionSessionRecord::reason()` reads null. BY DESIGN (F4, confirmed by tmesis
     * 2026-09-23): the stats route spells "not yet" as the zero value on every block.
     * Test for a reason with `!== ''` here and `!== null` on the record.
     */
    public function reason(): string
    {
        return $this->string('reason');
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
