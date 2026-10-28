<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * The publisher left mid-show and the node is HOLDING the session: the encoder is
 * quiesced, the packager keeps the live window on its last segment, and the same
 * session id and epoch wait for the publisher to come back. Milestone M1 (Tmesis
 * rc5, 2026-09-14; it was M4).
 *
 * A publisher back inside `fallback.end_seconds` is stream.input_returned with the
 * SAME session and epoch, and there is no stream.published for it. The timer running
 * out is stream.unpublished `timeout` followed by stream.sealed `fallback_end`. An
 * edge never emits this: with no packager there is nothing to hold, and its publisher
 * leaving is stream.unpublished. If the stream's ingest recording is on, the open
 * part closes here (recording.completed with `reason: input_lost`), before any
 * rejoin.
 */
class StreamInputLost extends TmesisWebhookReceived
{
    public const EVENT = 'stream.input_lost';

    public const FALLBACK_LAYER_BACKUP = 'backup';

    public const FALLBACK_LAYER_FILLER = 'filler';

    public const FALLBACK_LAYER_SLATE = 'slate';

    public const FALLBACK_LAYER_CARD = 'card';

    public const FALLBACK_LAYER_NONE = 'none';

    /**
     * An end of stream whose last packet is older than the transport's peer-idle
     * window: the encoder went silent (a pulled cable, a dead uplink).
     */
    public const REASON_PUBLISHER_TIMEOUT = 'publisher_timeout';

    /**
     * Any other end of stream: the publisher pressed Stop Streaming.
     */
    public const REASON_PUBLISHER_CLOSED = 'publisher_closed';

    /**
     * In the schema's closed set but never emitted by the rc5 build: a dead encoder
     * is respawned inside the session, and an exhausted respawn budget ENDS the
     * session as stream.unpublished `error` rather than holding it.
     */
    public const REASON_ENCODER_DEATH = 'encoder_death';

    /**
     * A socket read error rather than an end of stream.
     */
    public const REASON_SOURCE_ERROR = 'source_error';

    /**
     * The node's OWN drain (a restart, a deploy, `tmesis update`, a fleet `update` or
     * `reload_env`) holding a session that was live (tmesis `v0.8.0`, WEBHOOKS.md
     * 8.7). Not the end of the show: the next start restores the window, and the
     * publisher's return is stream.input_returned with the same session, after any
     * number of restarts. No stream.unpublished, no stream.sealed, no replay. Only a
     * SEALING drain (`tmesis drain --seal`, a decommission) still seals.
     */
    public const REASON_NODE_DRAINING = 'node_draining';

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
     * Which layer of the fallback chain took over. Always `none` in the rc5 build:
     * the manifest holds on its last segment and the chain itself is M4.
     */
    public function fallbackLayer(): string
    {
        return $this->string('fallback_layer');
    }

    /**
     * The media sequence a fallback layer was spliced in at. Null when nothing was
     * spliced at loss, which is every build with fallback_layer `none`: the
     * discontinuity lands at the rejoin instead.
     */
    public function splicedAtMediaSequence(): ?int
    {
        $value = $this->payload['spliced_at_media_sequence'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function reason(): string
    {
        return $this->string('reason');
    }
}
