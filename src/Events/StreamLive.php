<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\FirstSegment;
use AlexHackney\Tmesis\Data\Webhooks\LadderResolved;
use AlexHackney\Tmesis\Data\Webhooks\StreamPlayback;
use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * The first segment is delivered and viewers can watch. Milestone M1. Arrival
 * order means nothing: this can reach a receiver after the stream.sealed for
 * the same show, which is why events are ordered by occurredAt().
 */
class StreamLive extends TmesisWebhookReceived
{
    public const EVENT = 'stream.live';

    public const DELIVERY_MODE_BUCKET = 'bucket';

    public const DELIVERY_MODE_ORIGIN = 'origin';

    /**
     * The box serves AND the bucket receives the same output (tmesis v0.6.0, final
     * plan 11.57): a WIDENING, so a receiver that switched on the two old values
     * must take this third one.
     */
    public const DELIVERY_MODE_BOTH = 'both';

    public function session(): string
    {
        return $this->string('session');
    }

    public function epoch(): int
    {
        return $this->int('epoch');
    }

    /**
     * The same value as epoch(), typed.
     *
     * docs/API.md 8.3 names three things "epoch". This is the SESSION epoch, the
     * node's local publish generation. It is not the key epoch (which is in
     * auth.key_epoch and in a publish key's second field) and it is not the fencing
     * epoch (which decides who may write an output prefix).
     */
    public function sessionEpoch(): SessionEpoch
    {
        return SessionEpoch::fromInt($this->epoch());
    }

    /**
     * `bucket`, `origin` or `both` (the `DELIVERY_MODE_*` constants). The URLs in
     * playback() are PATHS under `delivery.origin.url_prefix` (WEBHOOKS.md 8.6): the
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

    public function playback(): StreamPlayback
    {
        return StreamPlayback::fromArray($this->object('playback'));
    }

    public function firstSegment(): FirstSegment
    {
        return FirstSegment::fromArray($this->object('first_segment'));
    }

    /**
     * The ladder the stream was provisioned with, by name. What the encoder actually
     * ran is ladderResolved().
     */
    public function ladder(): string
    {
        return $this->string('ladder');
    }

    /**
     * How the node resolved that ladder against the probed source: the rungs as
     * ceilings the source could not fill were capped, pinned for the session. Since
     * Tmesis rc5 (2026-09-14).
     */
    public function ladderResolved(): LadderResolved
    {
        return LadderResolved::fromArray($this->object('ladder_resolved'));
    }
}
