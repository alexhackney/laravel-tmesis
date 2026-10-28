<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;

/**
 * The host app's DesiredStateProvider threw for a node. The heartbeat was still
 * recorded and acked, and answered `desired_state: null`: the node keeps what it last
 * applied. `exception` is the class only; the message can carry anything.
 */
final class FleetDesiredStateFailed extends FleetNodeEvent
{
    public function __construct(FleetNode $node, public readonly string $exception)
    {
        parent::__construct($node);
    }
}
