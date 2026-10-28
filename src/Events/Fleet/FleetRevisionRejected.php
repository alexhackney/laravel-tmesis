<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Data\Fleet\RejectedRevision;

/**
 * The node refused a revision (tmesis D6), once per rejected revision. It keeps running
 * what it last applied. `rejected->reason` starts with the JSON path of the value it
 * refused; fix the document the provider returns and the next content is a new
 * revision.
 */
final class FleetRevisionRejected extends FleetNodeEvent
{
    public function __construct(FleetNode $node, public readonly RejectedRevision $rejected)
    {
        parent::__construct($node);
    }
}
