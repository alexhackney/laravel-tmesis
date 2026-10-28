<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\RelayDestination;

/**
 * The shared payload of the five `relay.*` events.
 *
 * One shape for five events, because they answer the same question about the same
 * object and differ only in which transition fired. The five schemas share one
 * `$defs.RelayEvent` upstream for the same reason.
 *
 * NONE of these is a stream event in disguise, and that is the receiver rule that
 * matters. A relay is a copy of the show going somewhere else. A destination failing,
 * reconnecting, or being removed never restarts the encoder, never touches the
 * manifest, and never rejects a publisher. A receiver that pages on `relay.failed`
 * the way it pages on `stream.unpublished` will page for a problem the audience
 * cannot see.
 */
abstract class RelayEvent extends TmesisWebhookReceived
{
    /**
     * The destination this transition is about. Its URL is redacted to scheme, host
     * and port, because a destination URL is a credential.
     */
    public function destination(): RelayDestination
    {
        return RelayDestination::fromArray($this->object('destination'));
    }

    /**
     * The human sentence for a degraded or failed transition, empty otherwise.
     *
     * Prose, not a closed set. Do not branch on it.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }
}
