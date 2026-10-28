<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A destination is not receiving. THIS IS NOT AN OUTAGE and never a publish
 * rejection: viewers are unaffected, and the node is already retrying with
 * backoff, forever. A show can end with a destination still failed and nothing
 * about that is inconsistent. Milestone M1 (the copy relay was pulled forward
 * from M2). One payload shape for all five relay.* events, which is why the
 * accessors live on RelayEvent: they answer the same question about the same
 * object and differ only in which transition fired.
 */
class RelayFailed extends RelayEvent
{
    public const EVENT = 'relay.failed';
}
