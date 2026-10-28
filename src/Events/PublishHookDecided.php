<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;

/**
 * A `publish.verify` hook was answered.
 *
 * Dispatched AFTER the response has left, from the signature middleware's terminate(),
 * for the same reason the webhook events are: the node is holding a publish handshake
 * open under a 500 ms cap, and a listener that took 200 ms of it would turn a working
 * veto into a timeout. Nothing a listener does here can change the answer; the
 * decision it carries has already been sent.
 *
 * It is a record, not a decision point. Audit it, meter it, alert on a deny rate.
 * Log redactedDecision(), never `$decision->toArray()`: a patch can carry relay URLs,
 * and a relay URL is a credential.
 */
final class PublishHookDecided
{
    public function __construct(
        public readonly HookRequest $request,
        public readonly HookDecision $decision,
        /** How long the decider itself took, in milliseconds. */
        public readonly float $elapsedMs,
    ) {}

    public function allowed(): bool
    {
        return $this->decision->allowed;
    }

    /**
     * The body the node was answered with, every `patch.relays[*].url` redacted to
     * scheme, host and port. The form of the decision that may be logged or stored.
     *
     * @return array<string, mixed>
     */
    public function redactedDecision(): array
    {
        return $this->decision->toRedactedArray();
    }

    public function stream(): string
    {
        return $this->request->stream();
    }

    /**
     * The redacted key. This is the only form of a publish key that may be logged.
     */
    public function keyFragment(): string
    {
        return $this->request->keyFragment();
    }
}
