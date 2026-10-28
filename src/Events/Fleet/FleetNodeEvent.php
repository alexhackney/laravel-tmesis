<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base class for the package's own fleet observations (plan F6).
 *
 * These are NOT node events: nothing here arrived as a webhook body. They are what the
 * control plane saw (an enrolment, a heartbeat, a node going quiet), so they do not
 * extend WebhookEvent, and a listener on WebhookEvent never sees them. The node's own
 * events, carried on a heartbeat, dispatch as the same WebhookEvent classes the
 * receiver dispatches.
 *
 * The node is the state AFTER the change, read from the store. It never carries a
 * token.
 */
abstract class FleetNodeEvent
{
    use Dispatchable;

    public function __construct(public readonly FleetNode $node) {}
}
