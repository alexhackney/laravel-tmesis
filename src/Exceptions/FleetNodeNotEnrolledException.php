<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A command was asked of a node id that is unknown or no longer enrolled: there is no
 * heartbeat left to carry it.
 */
class FleetNodeNotEnrolledException extends TmesisException
{
    public static function for(string $nodeId): self
    {
        return new self("The fleet node [{$nodeId}] is not enrolled.");
    }
}
