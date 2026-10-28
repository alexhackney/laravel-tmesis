<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Data\Fleet\JobsPauseState;

/**
 * The jobs pause a node's heartbeat reports moved (`v0.9.0`, handover 3.5): it began,
 * ended, or changed its `until`, `reason` or `source`. `$node->jobsPause` is the new
 * one (null: the node claims jobs again), `previous` the one before (null: there was
 * none, including on the first beat after enrolment).
 *
 * The observation of the HEARTBEAT, so it also sees a pause restored at boot, which
 * sends no `jobs.paused`. Drive "stop offering jobs to this node" from here or from
 * `FleetNode::jobsPaused()`.
 */
final class FleetJobsPauseChanged extends FleetNodeEvent
{
    public function __construct(FleetNode $node, public readonly ?JobsPauseState $previous)
    {
        parent::__construct($node);
    }
}
