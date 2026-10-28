<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;

/**
 * The node's admission moved (`open`, `draining`, `shutting_down`; tmesis D3, D16).
 * `draining` is in place: the process is up and heartbeating and refuses new publishes
 * and jobs. `previous` is null on the first beat after enrolment.
 */
final class FleetAdmissionChanged extends FleetNodeEvent
{
    public function __construct(FleetNode $node, public readonly ?string $previous)
    {
        parent::__construct($node);
    }
}
