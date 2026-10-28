<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\StreamPlayback;
use DateTimeImmutable;

/**
 * ENDLIST written: the sealed manifest is the replay. Milestone M1 for a
 * single rendition, M4 for the full ladder. sealed_by is a string rather than
 * an enum because the set grows inside v1 (fallback_end joined it at rc5,
 * crash_recovered at tmesis `v0.8.0`), and a backed enum would throw on the day a
 * value first ships.
 *
 * **The replay is at the live URLs (tmesis `v0.8.0`, WEBHOOKS.md 8.9).** The final
 * seal ends the live playlists IN PLACE: `vod.master` is the stream's
 * `playlist.m3u8` (the URL stream.live advertised), each rendition its
 * `<rung>.m3u8`, each caption playlist `captions_<lang>.m3u8`. `vod.m3u8`,
 * `<rung>_vod.m3u8` and `captions_<lang>_vod.m3u8` are no longer written. The
 * package passes the URLs through and builds none.
 */
class StreamSealed extends TmesisWebhookReceived
{
    public const EVENT = 'stream.sealed';

    public const DELIVERY_MODE_BUCKET = 'bucket';

    public const DELIVERY_MODE_ORIGIN = 'origin';

    /**
     * The box serves AND the bucket receives the same output (tmesis v0.6.0, final
     * plan 11.57): a WIDENING, so a receiver that switched on the two old values
     * must take this third one.
     */
    public const DELIVERY_MODE_BOTH = 'both';

    public const SEALED_BY_API_DELETE = 'api_delete';

    public const SEALED_BY_PUBLISHER_STOP = 'publisher_stop';

    public const SEALED_BY_NODE_DRAIN = 'node_drain';

    /**
     * A held session (stream.input_lost) whose publisher never came back inside
     * `fallback.end_seconds`: the node ended the show itself. Preceded by a
     * stream.unpublished with reason `timeout`. Since Tmesis rc5.
     */
    public const SEALED_BY_FALLBACK_END = 'fallback_end';

    /**
     * The seal of a window a CRASHED process left on disk (a kill, an OOM, a power
     * cut), which the next boot recovered and held, whatever then ended the hold
     * (tmesis `v0.8.0`, TMESIS-44). Before `v0.8.0` that seal emitted nothing, so a
     * control plane now learns of replays it used to miss. A window a planned drain
     * held keeps the asker of whatever ends it.
     */
    public const SEALED_BY_CRASH_RECOVERED = 'crash_recovered';

    /**
     * `bucket`, `origin` or `both` (the `DELIVERY_MODE_*` constants). The URLs in
     * vod() are PATHS under `delivery.origin.url_prefix` (WEBHOOKS.md 8.6): the
     * roots are the control plane's to prefix. Map `both` to BOTH roots, bucket
     * first, with the box as the fallback; servesFromBucket() and
     * servesFromOrigin() say which roots apply without a switch on the value.
     */
    public function deliveryMode(): string
    {
        return $this->string('delivery_mode');
    }

    /**
     * Whether the bucket received this output (`bucket` or `both`): the root to
     * hand a player first.
     */
    public function servesFromBucket(): bool
    {
        return in_array($this->deliveryMode(), [self::DELIVERY_MODE_BUCKET, self::DELIVERY_MODE_BOTH], true);
    }

    /**
     * Whether the box's own delivery listener serves this output (`origin` or
     * `both`): on a `both` stream, the fallback root.
     */
    public function servesFromOrigin(): bool
    {
        return in_array($this->deliveryMode(), [self::DELIVERY_MODE_ORIGIN, self::DELIVERY_MODE_BOTH], true);
    }

    public function vod(): StreamPlayback
    {
        return StreamPlayback::fromArray($this->object('vod'));
    }

    public function durationSeconds(): float
    {
        return $this->float('duration_seconds');
    }

    public function segmentCount(): int
    {
        return $this->int('segment_count');
    }

    /**
     * The number of `EXT-X-DISCONTINUITY` tags the sealed replay carries
     * (WEBHOOKS.md 8.9), which since tmesis `v0.2.0` step 1 is what the node counts:
     * before, it was encoder gaps plus splices, which missed a crash join, a
     * restored window's own tags and a sink-failure hole. Same field, same type;
     * the value is now the documented one.
     */
    public function discontinuityCount(): int
    {
        return $this->int('discontinuity_count');
    }

    public function droppedSegments(): int
    {
        return $this->int('dropped_segments');
    }

    /**
     * How many publisher sessions the sealed replay covers
     */
    public function sessions(): int
    {
        return $this->int('sessions');
    }

    public function firstProgramDateTimeRaw(): ?string
    {
        return $this->nullableString('first_program_date_time');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function firstProgramDateTime(): ?DateTimeImmutable
    {
        return $this->nullableTimestamp('first_program_date_time');
    }

    public function sealedBy(): string
    {
        return $this->string('sealed_by');
    }
}
