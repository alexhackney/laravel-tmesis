<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * A command was never DELIVERED in a heartbeat answer within `command_ttl_seconds` (the
 * node was not heartbeating), so it will never be sent. A command
 * that went out is never expired here: the node may hold it. Also fired for every open
 * command of a node that left.
 */
final class FleetCommandExpired extends FleetCommandEvent {}
