<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * A node's join token was used for the first time: a new node id and fleet token.
 */
final class FleetNodeEnrolled extends FleetNodeEvent {}
