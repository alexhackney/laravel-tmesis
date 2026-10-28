<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Keys\SessionEpoch;

/**
 * A publisher session ended. Milestone M1. On an M2 edge there is no packager,
 * so an input drop mid-session surfaces here rather than as stream.input_lost.
 */
class StreamUnpublished extends TmesisWebhookReceived
{
    public const EVENT = 'stream.unpublished';

    public const REASON_PUBLISHER_CLOSED = 'publisher_closed';

    public const REASON_TIMEOUT = 'timeout';

    public const REASON_TAKEOVER = 'takeover';

    public const REASON_KICKED = 'kicked';

    public const REASON_NODE_DRAINING = 'node_draining';

    public const REASON_ERROR = 'error';

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

    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * Session wall-clock length
     */
    public function durationSeconds(): float
    {
        return $this->float('duration_seconds');
    }

    /**
     * Container bytes received on this session
     */
    public function bytesIn(): int
    {
        return $this->int('bytes_in');
    }
}
