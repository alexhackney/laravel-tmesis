<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A destination is connected and DROPPING. The show is unaffected: the copy is
 * lossy because that destination is slower than the feed, and the local
 * playback and the recording are untouched. This is not stream.input_lost.
 * Milestone M1 (the copy relay was pulled forward from M2). One payload shape
 * for all five relay.* events, which is why the accessors live on RelayEvent:
 * they answer the same question about the same object and differ only in which
 * transition fired.
 */
class RelayDegraded extends RelayEvent
{
    public const EVENT = 'relay.degraded';
}
