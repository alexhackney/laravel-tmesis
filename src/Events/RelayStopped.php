<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A destination was removed. Milestone M1 (the copy relay was pulled forward
 * from M2). One payload shape for all five relay.* events, which is why the
 * accessors live on RelayEvent: they answer the same question about the same
 * object and differ only in which transition fired.
 */
class RelayStopped extends RelayEvent
{
    public const EVENT = 'relay.stopped';
}
