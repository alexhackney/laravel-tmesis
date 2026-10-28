<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A destination came back after a failure, with no operator action. Milestone
 * M1 (the copy relay was pulled forward from M2). One payload shape for all
 * five relay.* events, which is why the accessors live on RelayEvent: they
 * answer the same question about the same object and differ only in which
 * transition fired.
 *
 * `destination()->state()` is `connecting` here as well, the same as
 * relay.started: `connected` is promoted on the first write that gets through,
 * with no event of its own. Until tmesis `v0.2.0` step 1 this event carried
 * `failed`, the prior state, because the emit preceded the state change (a bug
 * the rc7 DC test found); a consumer that read this event's `state` as the
 * outcome now gets `connecting`.
 */
class RelayReconnected extends RelayEvent
{
    public const EVENT = 'relay.reconnected';
}
