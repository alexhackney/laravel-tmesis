<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\PublishAuth;
use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * A publisher key verified and the session opened. Milestone M1 (edge form
 * M2). This is the first event of a show and the one that proves which key was
 * used: auth.dual_accept is the re-keying workflow data source, and the window
 * closes when it stops appearing rather than on a date.
 */
class StreamPublished extends TmesisWebhookReceived
{
    public const EVENT = 'stream.published';

    public const PROTOCOL_SRT = 'srt';

    public const PROTOCOL_RTMP = 'rtmp';

    /**
     * RTMP over TLS on the RTMP port (tmesis `v0.8.0`, `ingest.rtmp.encryption`): a
     * WIDENING, so a receiver that switched on the two old values must take this one.
     */
    public const PROTOCOL_RTMPS = 'rtmps';

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_BACKUP = 'backup';

    public const ROLE_REHEARSAL = 'rehearsal';

    public const ROLE_INTERNAL_TAP = 'internal-tap';

    /**
     * Session ULID. Correlates with stream.unpublished and the per-feed timeline.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    /**
     * The SESSION epoch: local publish generation for this stream. Not the key
     * epoch.
     */
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

    public function protocol(): string
    {
        return $this->string('protocol');
    }

    /**
     * Publisher address and port. Present for operators; it is not an
     * authorization input.
     */
    public function remote(): string
    {
        return $this->string('remote');
    }

    /**
     * From the structured stream id. Stream roles are attributes, never name
     * suffixes.
     */
    public function role(): string
    {
        return $this->string('role');
    }

    public function takeover(): bool
    {
        return $this->bool('takeover');
    }

    public function auth(): PublishAuth
    {
        return PublishAuth::fromArray($this->object('auth'));
    }
}
