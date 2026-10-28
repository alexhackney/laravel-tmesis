<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * The node acked a command: it performed it OR found it moot (a kick of a stream with
 * no publisher; tmesis WP6). The ack does not say which: the next beat's `streams` and
 * `admission` are the outcome. `late` is true when the command had already expired
 * undelivered here and the node acked it anyway.
 */
final class FleetCommandAcked extends FleetCommandEvent
{
    public function __construct(string $commandId, string $nodeId, string $kind, ?string $stream, public readonly bool $late = false)
    {
        parent::__construct($commandId, $nodeId, $kind, $stream);
    }
}
