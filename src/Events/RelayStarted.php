<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A told forward destination's transport opened and the copy is being sent.
 * Milestone M1 (the copy relay was pulled forward from M2). One payload shape for
 * all five relay.* events, which is why the accessors live on RelayEvent: they
 * answer the same question about the same object and differ only in which
 * transition fired.
 *
 * `destination()->state()` is `connecting` here, until the first write gets
 * through; `connected` is promoted then, with no event of its own (WEBHOOKS.md
 * 8.27; `internal/relay/relay.go` `leg.run`, since tmesis `v0.2.0` step 1). A
 * dial that returned proves only that a transport opened, so a destination that
 * refuses every byte never reads as connected.
 */
class RelayStarted extends RelayEvent
{
    public const EVENT = 'relay.started';
}
