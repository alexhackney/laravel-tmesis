<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

/**
 * A node has sent nothing for `stale_after_seconds`, once per streak
 * (`tmesis:fleet:check-stale`). Nothing is done to it: a silent node keeps running what
 * it last applied (principle 1). FleetNodeRecovered fires on its next beat.
 */
final class FleetNodeStale extends FleetNodeEvent {}
