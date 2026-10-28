<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Events\TmesisWebhookReceived;

/**
 * A handled heartbeat: the answer's body, and the work that runs after it has been
 * sent (HeartbeatHandler::afterResponse()).
 *
 * @internal
 */
final readonly class HeartbeatOutcome
{
    /**
     * @param  array{ack_event_ids: list<string>, desired_state: array<string, mixed>|null, commands: list<array<string, mixed>>}  $body
     * @param  list<array{0: string, 1: TmesisWebhookReceived, 2: string}>  $claimed  dedupe key, typed event, occurred_at
     * @param  list<object>  $observations
     */
    public function __construct(
        public array $body,
        public array $claimed,
        public array $observations,
    ) {}
}
