<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * A publisher REJOINED a held session (stream.input_lost). Milestone M1 (Tmesis
 * rc5, 2026-09-14; it was M4).
 *
 * It is the rejoin's only event: the session id and epoch are the ones the show
 * started with, no stream.published is emitted for the rejoin, and the replay is
 * one manifest with one discontinuity where the gap was. A control plane that keyed
 * "a new session started" on stream.published keeps working; one that keyed it on
 * "the publisher connected" must read this instead. The ingest recording is NOT
 * one file across it: a new part opens (recording.started with `started_by:
 * resume`, a new `recording_id`).
 */
class StreamInputReturned extends TmesisWebhookReceived
{
    public const EVENT = 'stream.input_returned';

    public const FROM_LAYER_BACKUP = 'backup';

    public const FROM_LAYER_FILLER = 'filler';

    public const FROM_LAYER_SLATE = 'slate';

    public const FROM_LAYER_CARD = 'card';

    public const FROM_LAYER_NONE = 'none';

    public function session(): string
    {
        return $this->string('session');
    }

    public function epoch(): int
    {
        return $this->int('epoch');
    }

    /**
     * The same value as epoch(), typed. The SESSION epoch, and for a rejoin it is
     * the value the held session already had.
     */
    public function sessionEpoch(): SessionEpoch
    {
        return SessionEpoch::fromInt($this->epoch());
    }

    /**
     * Wall-clock seconds from the input loss to this return.
     */
    public function gapSeconds(): float
    {
        return $this->float('gap_seconds');
    }

    /**
     * Null in the rc5 build: the discontinuity lands on the next announced segment
     * rather than at a recorded splice.
     */
    public function splicedAtMediaSequence(): ?int
    {
        $value = $this->payload['spliced_at_media_sequence'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * The fallback layer the show is coming back from. Always `none` in the rc5
     * build, for the reason stream.input_lost gives.
     */
    public function fromLayer(): string
    {
        return $this->string('from_layer');
    }
}
