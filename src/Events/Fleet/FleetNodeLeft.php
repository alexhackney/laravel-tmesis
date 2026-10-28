<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;

/**
 * A node stopped being enrolled: its fleet token no longer authenticates and its name
 * is free.
 *
 * `reason` is `left` when the node deregistered itself (`tmesis leave`, DELETE
 * fleet/enroll), or `superseded` when a new join token for the same name enrolled a
 * box (a reinstall, or `tmesis join --force` without a leave first): two enrolled
 * nodes under one name would both be sent that name's desired state.
 */
final class FleetNodeLeft extends FleetNodeEvent
{
    public const REASON_LEFT = 'left';

    public const REASON_SUPERSEDED = 'superseded';

    public function __construct(FleetNode $node, public readonly string $reason)
    {
        parent::__construct($node);
    }
}
