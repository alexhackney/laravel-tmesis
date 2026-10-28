<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * Every heartbeat a node sent and the package recorded: every 10
 * seconds per node by default, so a listener that does work should be queued. `node`
 * is the status AFTER this beat.
 */
final class FleetHeartbeatReceived extends FleetNodeEvent {}
