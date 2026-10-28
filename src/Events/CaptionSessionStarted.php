<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * A caption session opened for one language of a publish session. Since
 * tmesis v0.3.0; resume semantics since tmesis `v0.9.1`, K7; provider failover
 * fields since v0.9.2.
 *
 * It fires ONCE PER CAPTION SESSION (since tmesis `v0.9.3`): the first open, a resume
 * after a degrade, a provider switch and the first open after a rejoin each fire it
 * again with the SAME `session`. A reconnect, a rotation and a re-anchor are sockets
 * INSIDE the caption session and fire nothing; before `v0.9.3` each fired this event
 * again with a new `provider_request_id`. `caption.session_ended` counts those sockets
 * (`sockets()`, `reconnects()` and `rotations()`), and the node's timeline carries one
 * `caption.socket_opened` row per socket with its id. A control plane that keys "the
 * show's captions started" on this event must key on (stream, session, lang), not on
 * the delivery, and one that counted starts as sockets counts
 * `CaptionSessionEnded::sockets()` instead.
 *
 * Since tmesis `v0.9.4` every `caption.session_ended` has this event for the same
 * caption session before it, on every path: a caption session that ends before its
 * start went out (refused at its first open, for one) fires this event just before
 * its end, with `providerRequestId()` null. An end without a start is no longer an
 * anomaly to tolerate; it does not happen.
 */
class CaptionSessionStarted extends TmesisWebhookReceived
{
    public const EVENT = 'caption.session_started';

    /**
     * The publish session this caption session belongs to.
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
     * The RFC 5646 tag this session captions in; one event per language.
     */
    public function lang(): string
    {
        return $this->string('lang');
    }

    /**
     * The STT provider, an open string: `deepgram` or `self_hosted` in tmesis v0.9.1.
     */
    public function provider(): string
    {
        return $this->string('provider');
    }

    /**
     * The provider's own id for the caption session's FIRST socket, for a support
     * ticket: later sockets' ids are on the stream's timeline (`caption.socket_opened`
     * rows). Since tmesis `v0.8.0` the event carries the socket's real id, and null
     * when the provider did not report one within the node's grace (TMESIS-24). Since
     * `v0.9.4` it is also null on the start sent just before the end of a caption
     * session that ended before its start went out, on either provider, so a
     * `self_hosted` start can carry null too.
     */
    public function providerRequestId(): ?string
    {
        return $this->nullableString('provider_request_id');
    }

    /**
     * The `caption.session_ended` reason of the degrade this start resumes from
     * (`CaptionSessionEnded::RESUMABLE_REASONS`), when the provider answered again and
     * captions came back by themselves; null on every other start (the first open, a
     * provider switch that was not a resume, the first open after a rejoin), and on a
     * start on the other provider after an `auth_error` or `quota_exceeded`, which
     * carries switchedFrom() only. `stalled` (since tmesis `v0.9.3`) is a resume after a
     * stall, which waits at least 15 s after the stall even when the provider answers
     * its readiness check at once. An open string: a value this build does not spell
     * stays a plain string. REQUIRED and nullable since tmesis `v0.9.1` (K7); null from
     * an older node.
     */
    public function resumedAfter(): ?string
    {
        return $this->nullableString('resumed_after');
    }

    /**
     * Whether this start is a resume after a degrade, not a new caption session.
     */
    public function isResume(): bool
    {
        return $this->resumedAfter() !== null;
    }

    /**
     * The provider this stream's captions just left, or null on an ordinary start. Set
     * on a failover to `transcription.fallback_provider` or a failback to the primary,
     * and on a start on the fallback because the primary was unavailable when captions
     * started (a publish, captions turned on, a language change) or, since tmesis
     * `v0.9.3`, because the box's captioned capacity was full (the self-hosted sidecar's
     * measured count less one, health `live_capacity.caption_sessions_total`), and,
     * since `v0.9.4`, on the first open after a rejoin while the stream is on the
     * fallback, which names the primary (with `isFallback()` true).
     */
    public function switchedFrom(): ?string
    {
        return $this->nullableString('switched_from');
    }

    /** Whether this session uses the configured fallback provider. */
    public function isFallback(): bool
    {
        $value = $this->payload['fallback'] ?? false;

        return is_bool($value) ? $value : false;
    }

    /** Whether this start follows a move from the other provider (`switchedFrom()` is set). */
    public function isSwitch(): bool
    {
        return $this->switchedFrom() !== null;
    }
}
