<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Markers\MarkerPlacement;
use AlexHackney\Tmesis\Keys\SessionEpoch;
use DateTimeImmutable;

/**
 * A marker LANDED: its date-range tag is in the playlists (tmesis v0.6.0, final plan
 * 11.56, `docs/API.md` 9.8a). The `202` from `placeMarker()` only queued it; this is
 * the event to mark an ad break as aired on.
 *
 * `startDate()` is the same on every rendition, `complete()` is true when every
 * media rendition carries the tag (false when the session drained first), and
 * `placements()` has one entry per rendition that does. Correlate on marker(), the
 * id `MarkerRequest::id()` handed back, and session(): ids are unique per session.
 */
class MarkerPlaced extends TmesisWebhookReceived
{
    public const EVENT = 'marker.placed';

    public const KIND_OUT = 'out';

    public const KIND_IN = 'in';

    /**
     * The publish session whose playlists carry the tag.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    public function epoch(): int
    {
        return $this->int('epoch');
    }

    /**
     * The same value as epoch(), typed: the SESSION epoch, as on every stream event.
     */
    public function sessionEpoch(): SessionEpoch
    {
        return SessionEpoch::fromInt($this->epoch());
    }

    /**
     * The marker id: the tag's ID.
     */
    public function marker(): string
    {
        return $this->string('marker');
    }

    /**
     * `out` (the break starts) or `in` (it ends).
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
     * The DURATION the tag carries; null for none.
     */
    public function durationSeconds(): ?float
    {
        $value = $this->payload['duration_seconds'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The caller's splice_info_section as upper case hex without `0x`; null for none.
     */
    public function scte35(): ?string
    {
        return $this->nullableString('scte35');
    }

    public function startDateRaw(): string
    {
        return $this->string('start_date');
    }

    /**
     * The tag's START-DATE, the same on every rendition: the boundary the next cut
     * closed for a now marker, the told instant otherwise.
     */
    public function startDate(): DateTimeImmutable
    {
        return $this->timestamp('start_date');
    }

    /**
     * Every media rendition carries the tag. False when the session drained first.
     */
    public function complete(): bool
    {
        return $this->bool('complete');
    }

    /**
     * One per rendition carrying the tag, in rendition order.
     *
     * @return list<MarkerPlacement>
     */
    public function placements(): array
    {
        return MarkerPlacement::listFrom($this->payload['placements'] ?? null);
    }
}
