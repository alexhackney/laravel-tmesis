<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\SessionEpoch;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One stream in the node registry: GET /api/v1/streams/:id. The openapi
 * component StreamRecord, and it is FLAT. There is no session object, no
 * probe, no packager, no lease, no health, and no effective_config at tmesis
 * v0.3.0 (rc5 only widened state with input_lost; rc6, step 1 and the v0.2.0 tag
 * changed nothing here); those are M4. The record is 22 keys since v0.6.0: `recording`
 * and `preview` (rc7), `captions` (v0.3.0) and `fallback` (v0.6.0) are the EFFECTIVE
 * values resolved
 * through the node's config, and `preview_monitor` is the one optional key, present
 * only on a single-stream read that asked for `include=preview`. The caption session
 * is nested inside `captions` instead, present only with `include=captions`.
 * docs/API.md 9.5 is the shape to tolerate, never the shape to require.
 */
final readonly class StreamRecord
{
    public const DELIVERY_MODE_BUCKET = 'bucket';

    public const DELIVERY_MODE_ORIGIN = 'origin';

    /**
     * The box serves AND the bucket receives the same output (tmesis v0.6.0, final
     * plan 11.57). A WIDENING of the old two-value enum: a consumer that switched on
     * `bucket` and `origin` must take this third value. A `both` stream has two
     * playback roots: bucket first, the box as the fallback.
     */
    public const DELIVERY_MODE_BOTH = 'both';

    /** Every value `delivery_mode` takes since tmesis v0.6.0. */
    public const DELIVERY_MODES = [self::DELIVERY_MODE_BUCKET, self::DELIVERY_MODE_ORIGIN, self::DELIVERY_MODE_BOTH];

    public const PROTOCOL_SRT = 'srt';

    public const PROTOCOL_RTMP = 'rtmp';

    /**
     * RTMP over TLS on the RTMP port (tmesis `v0.8.0`, `ingest.rtmp.encryption`): a
     * WIDENING, so a receiver that switched on the two old values must take this one.
     */
    public const PROTOCOL_RTMPS = 'rtmps';

    /**
     * The row exists and nobody is publishing. ALSO what a provisioned stream reads
     * again after its publisher stops (a clean stop, an expired hold, a kick, a
     * takeover, a node drain), because none of those seals the row (API.md 9.5,
     * `internal/node/streamstore.go`).
     */
    public const STATE_PROVISIONED = 'provisioned';

    public const STATE_PUBLISHING = 'publishing';

    public const STATE_LIVE = 'live';

    /**
     * The publisher left and the node is holding the session with the live window
     * on its last segment (stream.input_lost). Between live and either
     * input-returned-to-live or, when the hold expires, `provisioned` (the timer is
     * `stream.unpublished` `timeout` then `stream.sealed` `fallback_end` for the
     * SESSION, and the row goes back to `provisioned`). An ad hoc stream that was
     * never POSTed has no row to fall back to and is a 404 once its session and its
     * stats are gone. Since Tmesis rc5.
     */
    public const STATE_INPUT_LOST = 'input_lost';

    public const STATE_DRAINING = 'draining';

    /**
     * ENDLIST written, the replay is on disk. Reached ONLY through
     * `DELETE /streams/{id}` without `retain_config`, the one caller of the journal's
     * `SealStream`. A consumer state machine that expected `sealed` after a
     * publisher stop reads `provisioned`.
     */
    public const STATE_SEALED = 'sealed';

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
     * RFC3339 UTC, docs/API.md section 4
     */
    public function createdAtRaw(): string
    {
        return $this->string('created_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function createdAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('created_at'));
    }

    /**
     * `bucket`, `origin` or `both` (the `DELIVERY_MODE_*` constants): the node's
     * configured mode. `both` since tmesis v0.6.0; read servesFromBucket() and
     * servesFromOrigin() rather than comparing, so a `both` stream is on both roots.
     */
    public function deliveryMode(): string
    {
        return $this->string('delivery_mode');
    }

    /**
     * Whether the bucket receives this stream's output: `bucket` or `both`. On a
     * `both` stream the bucket root is the one to hand a player first.
     */
    public function servesFromBucket(): bool
    {
        return in_array($this->deliveryMode(), [self::DELIVERY_MODE_BUCKET, self::DELIVERY_MODE_BOTH], true);
    }

    /**
     * Whether the box's own delivery listener serves this stream's output: `origin`
     * or `both`. On a `both` stream the box is the fallback root.
     */
    public function servesFromOrigin(): bool
    {
        return in_array($this->deliveryMode(), [self::DELIVERY_MODE_ORIGIN, self::DELIVERY_MODE_BOTH], true);
    }

    public function dvrWindowSeconds(): int
    {
        return $this->int('dvr_window_seconds');
    }

    /**
     * The SESSION epoch: the node's local publish generation for this stream. Null
     * when nothing has published yet.
     *
     * Typed, because docs/API.md 8.3 names three things "epoch" and this record
     * carries two of them. minKeyEpoch() below is the other.
     */
    public function epoch(): ?SessionEpoch
    {
        $value = $this->nullableInt('epoch');

        return $value === null ? null : SessionEpoch::fromInt($value);
    }

    /**
     * The EFFECTIVE fallback settings: `endSeconds()` is the stream's own value,
     * else the node's `fallback.end_seconds`. REQUIRED since tmesis v0.6.0.
     */
    public function fallback(): FallbackRecord
    {
        return FallbackRecord::fromArray($this->object('fallback'));
    }

    public function id(): string
    {
        return $this->string('id');
    }

    /**
     * Labels set on the stream. The node stores and echoes them and never interprets
     * them.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $out = [];

        foreach ($this->array('labels') as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function ladder(): string
    {
        return $this->string('ladder');
    }

    /**
     * The KEY epoch floor: the node refuses a publish key below this. Raising it is
     * how the control plane invalidates every key already minted for this stream.
     *
     * Null when the stream has no floor set.
     */
    public function minKeyEpoch(): ?KeyEpoch
    {
        $value = $this->nullableInt('min_key_epoch');

        return $value === null ? null : KeyEpoch::fromInt($value);
    }

    public function playback(): StreamPlayback
    {
        return StreamPlayback::fromArray($this->array('playback'));
    }

    /**
     * The EFFECTIVE preview settings, resolved through the node's config. Since
     * Tmesis rc7.
     */
    public function preview(): PreviewRecord
    {
        return PreviewRecord::fromArray($this->object('preview'));
    }

    /**
     * Where a producer's monitor connects. Null unless the read sent
     * `include=preview` (`getStream($id, includePreview: true)`); the list route
     * never carries it. Since Tmesis rc7.
     */
    public function previewMonitor(): ?PreviewMonitorRecord
    {
        $value = $this->raw['preview_monitor'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return PreviewMonitorRecord::fromArray($value);
    }

    public function protocol(): ?string
    {
        return $this->nullableString('protocol');
    }

    /**
     * The EFFECTIVE caption settings, resolved through the node's `transcription.*`
     * config. Always present; `captions()->session()` is null unless the read sent
     * `include=captions` (`getStream($id, includeCaptions: true)`). Since tmesis
     * v0.3.0.
     */
    public function captions(): CaptionsRecord
    {
        return CaptionsRecord::fromArray($this->object('captions'));
    }

    /**
     * The EFFECTIVE recording settings, resolved through the node's config. Since
     * Tmesis rc7.
     */
    public function recording(): RecordingRecord
    {
        return RecordingRecord::fromArray($this->object('recording'));
    }

    public function sealedAtRaw(): ?string
    {
        return $this->nullableString('sealed_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function sealedAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('sealed_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function segmentSeconds(): int
    {
        return $this->int('segment_seconds');
    }

    public function session(): ?string
    {
        return $this->nullableString('session');
    }

    public function startedAtRaw(): ?string
    {
        return $this->nullableString('started_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function startedAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('started_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function updatedAtRaw(): string
    {
        return $this->string('updated_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function updatedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('updated_at'));
    }

    public function version(): int
    {
        return $this->int('version');
    }

    public function webhookUrl(): ?string
    {
        return $this->nullableString('webhook_url');
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

    private function nullableInt(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function object(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }
}
