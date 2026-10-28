<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * The node reported a higher `desired_revision_applied` than on its last beat.
 * `node->converged()` says whether it is also the intended revision with the intended
 * revocation list.
 */
final class FleetRevisionApplied extends FleetNodeEvent {}
