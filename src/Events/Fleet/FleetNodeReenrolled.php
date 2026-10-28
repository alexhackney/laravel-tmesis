<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * A node repeated its enrollment inside the retry window (tmesis D12): the same node id, a fresh fleet token, the previous one revoked. The node's recovery for a lost answer or a failed local write.
 */
final class FleetNodeReenrolled extends FleetNodeEvent {}
