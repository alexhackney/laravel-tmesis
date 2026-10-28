<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A publish attempt was refused at the handshake. Milestone M1. stream() is
 * null when the key was too malformed to parse an id out of, and this event
 * never carries labels: there may be no stream to read them from. The reason
 * set is CLOSED and is what both ingest listeners refuse a publish with; it is
 * not the metrics label set, where internal and panic also appear.
 */
class StreamPublishRejected extends TmesisWebhookReceived
{
    public const EVENT = 'stream.publish_rejected';

    public const REASON_MALFORMED = 'malformed';

    public const REASON_BAD_SIGNATURE = 'bad_signature';

    public const REASON_BAD_APP = 'bad_app';

    public const REASON_EPOCH_EXPIRED = 'epoch_expired';

    public const REASON_REVOKED = 'revoked';

    public const REASON_UNKNOWN_STREAM = 'unknown_stream';

    public const REASON_NO_KEY = 'no_key';

    public const REASON_BAD_MODE = 'bad_mode';

    public const REASON_UNAUTHORIZED = 'unauthorized';

    public const REASON_HOOK_DENIED = 'hook_denied';

    /**
     * The stream already has a LIVE publisher, and a second one is refused rather
     * than taking the session over (tmesis final plan 11.30). A held session
     * (stream.input_lost) is not live for this purpose: a publish on it rejoins.
     * Since Tmesis rc5.
     */
    public const REASON_ALREADY_PUBLISHING = 'already_publishing';

    /**
     * The node is draining in place, admission closed (tmesis `v0.7.0`, the fleet
     * drain command). The publisher should try another node, not this one again.
     */
    public const REASON_CLOSED = 'closed';

    /**
     * The node's pinned LIVE encoder (`encode.video_encoder`) failed at boot, so every
     * publish is refused after the key is verified and before the hook (tmesis
     * `v0.9.0`, handover 3.4; SRT 1503, RTMP "Publish failed."). The key is fine; the
     * box is not until its pin is repaired and tmesis restarts. NEVER rate limited for
     * a proven key, so expect one event per reconnect attempt.
     */
    public const REASON_ENCODER_UNAVAILABLE = 'encoder_unavailable';

    /**
     * The box is FULL (tmesis `v0.9.0`, handover 3.1; SRT 1402, RTMP "Publish
     * failed."): its stored live unit limit with admission on, a told positive
     * `limits.max_streams`, or a known NVENC/QSV session ceiling has no room. It is
     * NOT a key problem and rotating the key does nothing; the remedy is another box,
     * a smaller ladder or a re-measure. Refused before the hook. NEVER rate limited
     * for a proven key (a signed key or a configured bare key), so a reconnecting
     * encoder sends one event per attempt: dedupe by delivery, never alert per event.
     */
    public const REASON_OVER_CAPACITY = 'over_capacity';

    public const PROTOCOL_SRT = 'srt';

    public const PROTOCOL_RTMP = 'rtmp';

    /**
     * RTMP over TLS on the RTMP port (tmesis `v0.8.0`, `ingest.rtmp.encryption`): a
     * WIDENING, so a receiver that switched on the two old values must take this one.
     */
    public const PROTOCOL_RTMPS = 'rtmps';

    /**
     * CLOSED set: every reason this event can carry, which is what both ingest
     * listeners refuse a publish with. It is NOT the tmesis_publish_rejected_total
     * label set: internal and panic are counted there and never emitted here.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    public function protocol(): string
    {
        return $this->string('protocol');
    }

    /**
     * Redacted: the stream id and epoch in full, then the first four and last four
     * hex characters of the MAC with three dots between.
     */
    public function keyFragment(): string
    {
        return $this->string('key_fragment');
    }

    public function remote(): string
    {
        return $this->string('remote');
    }
}
