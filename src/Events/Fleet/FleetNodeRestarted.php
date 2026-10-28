<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * The node's process start (`started_at`) moved between two beats: it restarted, as
 * opposed to going quiet on the network. Not fired for the first beat after enrolment.
 */
final class FleetNodeRestarted extends FleetNodeEvent {}
