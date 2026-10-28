<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\CaptionPlayback;
use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * A caption session closed for one language of a publish session. Since tmesis
 * v0.3.0.
 *
 * Captions fail soft and never touch the show: this event is how a receiver learns the
 * provider is gone while the show is fine. Since tmesis `v0.9.1`, K8, `gave_up`,
 * `rate_limited`, `provider_closed` and `unreachable` resume by themselves when the
 * provider answers again, and since `v0.9.3` so does `stalled` (a connected provider
 * not keeping up with flowing audio; never an outage since `v0.9.4`), so a `caption.session_started` with
 * `resumed_after` may follow in the same publish session. `gave_up` is no longer the
 * end of captions for the show. `auth_error` and `quota_exceeded` are never retried and
 * need an operator. A node with fallback may also continue after either refusal.
 * On a clean close after the seal, `artifacts` carries this
 * language's transcript sidecars.
 *
 * The socket counters are cumulative over the stream's captioner, and since tmesis
 * `v0.9.3` a reconnect, a rotation or a re-anchor fires NO `caption.session_started`:
 * sockets(), reconnects() and rotations() are where a receiver counts them.
 *
 * Since tmesis `v0.9.4` every end has a `caption.session_started` for the same caption
 * session before it, on every path, so a receiver matching ends to starts always finds
 * the start (`CaptionSessionStarted::providerRequestId()` may be null on it).
 */
class CaptionSessionEnded extends TmesisWebhookReceived
{
    public const EVENT = 'caption.session_ended';

    /** The publish ended; the node's own close. */
    public const REASON_STREAM_ENDED = 'stream_ended';

    /** Captions were turned off (a PATCH of `captions.enabled`); the node's own close. */
    public const REASON_DISABLED = 'disabled';

    /** The provider refused the key. Never retried. */
    public const REASON_AUTH_ERROR = 'auth_error';

    /** The provider account is out of quota. Never retried. */
    public const REASON_QUOTA_EXCEEDED = 'quota_exceeded';

    /** Retried on a capped backoff. */
    public const REASON_RATE_LIMITED = 'rate_limited';

    /** Retried on a capped backoff. */
    public const REASON_PROVIDER_CLOSED = 'provider_closed';

    /** Retried on a capped backoff. */
    public const REASON_UNREACHABLE = 'unreachable';

    /**
     * The retry cap was reached. Since tmesis `v0.9.4` this is also how a long outage
     * ends (the provider unreachable, refusing or dropping connections for about 60 s
     * accumulated); a short outage rides through on the same session with no end.
     */
    public const REASON_GAVE_UP = 'gave_up';

    /** The node closed this socket to move captions to its other provider. */
    public const REASON_PROVIDER_SWITCH = 'provider_switch';

    /**
     * The provider was connected while audio was flowing and answered none of it for
     * 60 s, answered it too late or lost too much of it (`detail()` says what the node
     * measured). The node ends the session and retries by itself, no sooner than 15 s
     * after the stall. Since tmesis `v0.9.3`. Since `v0.9.4` an outage (the provider
     * unreachable, refusing or dropping connections) is never `stalled`: read
     * `REASON_GAVE_UP` as "the provider is down", not this.
     */
    public const REASON_STALLED = 'stalled';

    /**
     * CLOSED set, additive inside v1, in the schema's enum order.
     */
    public const ENDED_REASONS = [
        self::REASON_STREAM_ENDED,
        self::REASON_DISABLED,
        self::REASON_AUTH_ERROR,
        self::REASON_QUOTA_EXCEEDED,
        self::REASON_RATE_LIMITED,
        self::REASON_PROVIDER_CLOSED,
        self::REASON_UNREACHABLE,
        self::REASON_GAVE_UP,
        self::REASON_PROVIDER_SWITCH,
        self::REASON_STALLED,
    ];

    /**
     * The reasons captions come back from by themselves, in the same publish session,
     * once the provider answers again (tmesis `v0.9.1`, K8; `stalled` since `v0.9.3`).
     * Held equal to `caption.session_started`'s `resumed_after` enum by its test.
     *
     * @var list<string>
     */
    public const RESUMABLE_REASONS = [
        self::REASON_GAVE_UP,
        self::REASON_RATE_LIMITED,
        self::REASON_PROVIDER_CLOSED,
        self::REASON_UNREACHABLE,
        self::REASON_STALLED,
    ];

    /**
     * The publish session this caption session belonged to.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    /**
     * The SESSION epoch, as on every stream event.
     */
    public function epoch(): int
    {
        return $this->int('epoch');
    }

    /**
     * The same value as epoch(), typed. The SESSION epoch: not the key epoch and not
     * the fencing epoch (docs/API.md 8.3).
     */
    public function sessionEpoch(): SessionEpoch
    {
        return SessionEpoch::fromInt($this->epoch());
    }

    /**
     * The RFC 5646 tag this session captioned in.
     */
    public function lang(): string
    {
        return $this->string('lang');
    }

    /** The open-string STT provider: `deepgram` or `self_hosted` in tmesis v0.9.1. */
    public function provider(): string
    {
        return $this->string('provider');
    }

    /**
     * The provider's id for the socket that closed. Null when it never reported one.
     */
    public function providerRequestId(): ?string
    {
        return $this->nullableString('provider_request_id');
    }

    /**
     * One of ENDED_REASONS.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * Whether this close was a provider degrade the node resumes by itself (tmesis
     * `v0.9.1`, K8): a `caption.session_started` with `resumed_after` may follow in the
     * same publish session. `stalled` (tmesis `v0.9.3`) resumes like the four before
     * it, no sooner than 15 s after the stall. False is NOT "captions are over": a
     * hold's `stream_ended` close is sent at the rejoin with `artifacts` null and a new
     * start follows in the same session (WEBHOOKS.md 8.15, D45), and a `disabled` close
     * comes back on a later enable. Captions end for the show with the session:
     * `stream.sealed`, or a `stream_ended` close that carries `artifacts`.
     */
    public function mayResume(): bool
    {
        return in_array($this->reason(), self::RESUMABLE_REASONS, true);
    }

    public function isProviderSwitch(): bool
    {
        return $this->reason() === self::REASON_PROVIDER_SWITCH;
    }

    /**
     * Whether a start may follow in this publish session: true for every
     * RESUMABLE_REASONS close, `stalled` included (tmesis `v0.9.3`), and for
     * `provider_switch`. Auth and quota failures may also be followed by a fallback
     * start, which depends on node config this event does not carry.
     */
    public function captionsContinue(): bool
    {
        return $this->mayResume() || $this->isProviderSwitch();
    }

    /**
     * The provider's own message for the close, verbatim (a refused handshake's body,
     * a close reason, an error string), never the key. Null when the close had no
     * message, which is every close the node made itself except provider_switch,
     * whose detail says why the node moved the stream, and stalled (tmesis `v0.9.3`),
     * whose detail says what the node measured.
     */
    public function detail(): ?string
    {
        return $this->nullableString('detail');
    }

    /**
     * Final results journaled over the session.
     */
    public function finals(): int
    {
        return $this->int('finals');
    }

    /**
     * Audio dropped on the tap's ring or during a reconnect, in milliseconds.
     */
    public function droppedPcmMs(): int
    {
        return $this->int('dropped_pcm_ms');
    }

    /**
     * Sockets re-opened after a failure, cumulative over the stream's captioner (a later
     * `caption.session_ended` in the same publish session includes the earlier ones).
     * None of them fires `caption.session_started` (since tmesis `v0.9.3`; before that
     * each one did). A rotation or a re-anchor is not counted here: see sockets().
     */
    public function reconnects(): int
    {
        return $this->int('reconnects');
    }

    /**
     * Provider sockets opened, cumulative over the stream's captioner like reconnects():
     * every first open, reconnect, rotation and re-anchor opens one, and only the first
     * open of a caption session fires `caption.session_started`. A `self_hosted` socket
     * counts once the sidecar has answered on it.
     *
     * REQUIRED since tmesis `v0.9.3`, and read 0 from an older node that sends no such
     * key.
     */
    public function sockets(): int
    {
        return $this->int('sockets');
    }

    /**
     * Sockets replaced on purpose because the feed queue shed audio and the byte stream
     * on a socket must stay contiguous, cumulative over the stream's captioner like
     * reconnects(); `deepgram` only.
     *
     * REQUIRED since tmesis `v0.9.3`, and read 0 from an older node that sends no such
     * key.
     */
    public function rotations(): int
    {
        return $this->int('rotations');
    }

    /**
     * The seal's output for THIS language: the same object `stream.sealed`'s
     * `vod.captions` carries under `lang()`. Present only on a `stream_ended` close
     * after the seal wrote them; null on every other reason and on a live close.
     *
     * One entry, not a map: the event is already per language.
     */
    public function artifacts(): ?CaptionPlayback
    {
        $value = $this->payload['artifacts'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return CaptionPlayback::fromArray($value);
    }
}
