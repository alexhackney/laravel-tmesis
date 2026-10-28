<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetHeartbeatEvent;
use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Data\Fleet\HeartbeatRequest;
use AlexHackney\Tmesis\Events\Fleet\FleetAdmissionChanged;
use AlexHackney\Tmesis\Events\Fleet\FleetCommandAcked;
use AlexHackney\Tmesis\Events\Fleet\FleetCommandExpired;
use AlexHackney\Tmesis\Events\Fleet\FleetHeartbeatReceived;
use AlexHackney\Tmesis\Events\Fleet\FleetJobsPauseChanged;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeRecovered;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeRestarted;
use AlexHackney\Tmesis\Events\Fleet\FleetRevisionApplied;
use AlexHackney\Tmesis\Events\Fleet\FleetRevisionRejected;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDispatcher;
use AlexHackney\Tmesis\Webhooks\EventMap;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * One heartbeat, from a parsed request to the answer and the work that runs after it
 * (plan F3 and F5). The route and the fake both call it, so the fake cannot drift from
 * the receiver.
 *
 * The order is the durability line (tmesis D13): the node's report and its command acks
 * are recorded in one transaction, and EVERY event id is claimed durably through the
 * webhook deduper, before the answer lists it in `ack_event_ids`. A throw anywhere in
 * that half is the route's `503`, and the node re-sends the whole batch; an event
 * claimed before the throw is a duplicate on the re-send, which is acked and not
 * dispatched again.
 *
 * Nothing is DISPATCHED here. The claimed events, and the package's own observation
 * events, go out in `afterResponse()`, once the node has its answer (the webhook
 * receiver's precedent: a slow listener must never become a slow heartbeat).
 */
class HeartbeatHandler
{
    public function __construct(
        private readonly FleetStore $store,
        private readonly DesiredStates $desired,
        private readonly DeliveryDeduper $deduper,
        private readonly DeliveryDispatcher $dispatcher,
    ) {}

    /**
     * @throws Throwable when recording or claiming fails: nothing may be acked
     */
    public function handle(stdClass $nodeRow, HeartbeatRequest $request): HeartbeatOutcome
    {
        $now = CarbonImmutable::now('UTC');
        $nodeId = (string) $nodeRow->id;
        $before = $this->store->toNode($nodeRow);

        $acked = $this->store->transaction(function () use ($nodeId, $request, $now): array {
            $this->store->recordHeartbeat(
                $nodeId,
                $now,
                $request->version,
                $request->startedAt,
                $request->admission,
                $request->stats,
                $request->streams,
                $request->desiredRevisionApplied,
                $request->rejected,
                $request->revocationsDigest,
                $request->jobsPause,
            );

            return $this->store->ackCommands($nodeId, $request->ackedCommandIds, $now);
        });

        [$ackIds, $claimed] = $this->claimEvents($before, $request->events, $now);

        $after = $this->store->node($nodeId) ?? $before;
        $desired = $this->desiredFor($after, $request);

        $expired = $this->store->expireUndelivered($now, $nodeId);
        $commands = $this->store->deliverableCommands($nodeId, $now);
        $this->store->markDelivered(array_map(fn (stdClass $c): string => (string) $c->id, $commands), $now);

        return new HeartbeatOutcome(
            body: [
                'ack_event_ids' => $ackIds,
                'desired_state' => $desired,
                'commands' => array_map(self::commandWire(...), $commands),
            ],
            claimed: $claimed,
            observations: $this->observations($before, $this->store->node($nodeId) ?? $after, $acked, $expired),
        );
    }

    /**
     * Dispatch what the heartbeat claimed, in `occurred_at` order, then the package's
     * own observations. Never throws: a failing listener keeps its claim unprocessed
     * for `tmesis:webhooks:retry` (DeliveryDispatcher), and the node already has its
     * answer and never re-sends an acked event.
     */
    public function afterResponse(HeartbeatOutcome $outcome): void
    {
        foreach ($outcome->claimed as [$deliveryId, $event]) {
            $this->dispatcher->dispatch($deliveryId, $event);
        }

        foreach ($outcome->observations as $observation) {
            try {
                Event::dispatch($observation);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Claim every event through the webhook deduper, on the webhook's own key (the
     * body's `delivery`, equal to the fleet id by construction). A new claim and a
     * duplicate are both acked; a payload that is not a JSON object is acked and
     * logged (a signed webhook body that will not decode is a 204 today, for the same
     * reason: it will never decode, and re-sending it forever helps nobody).
     *
     * @param  list<FleetHeartbeatEvent>  $events
     * @return array{0: list<string>, 1: list<array{0: string, 1: TmesisWebhookReceived, 2: string}>}
     */
    private function claimEvents(FleetNode $node, array $events, CarbonImmutable $now): array
    {
        $ackIds = [];
        $claimed = [];

        foreach ($events as $event) {
            $ackIds[] = $event->id;

            // An OBJECT, checked on the bytes: an assoc decode cannot tell `{}` from `[]`.
            $payload = str_starts_with(ltrim($event->payload), '{') ? json_decode($event->payload, true) : null;

            if (! is_array($payload)) {
                Log::warning('Tmesis fleet event payload is not a JSON object; acked and dropped.', [
                    'node' => $node->name,
                    'delivery' => $event->id,
                    'event' => $event->event,
                ]);

                continue;
            }

            /** @var array<string, mixed> $payload */
            $delivery = ReceivedDelivery::fromFleet($node->name, $event->id, $event->event, $event->payload, $now);
            $key = $this->dedupeKey($node, $event, $payload);

            if (! $this->deduper->claim($key, $delivery, $payload)) {
                continue;
            }

            $claimed[] = [$key, EventMap::make($payload, $delivery), $event->occurredAt];
        }

        usort($claimed, fn (array $a, array $b): int => strcmp($a[2], $b[2]));

        return [$ackIds, $claimed];
    }

    /**
     * The body's `delivery` wins, as on the webhook route: it is the key a webhook
     * delivery of the same event was claimed under. A mismatch with the fleet id is a
     * node bug, logged and never refused.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dedupeKey(FleetNode $node, FleetHeartbeatEvent $event, array $payload): string
    {
        $fromBody = $payload['delivery'] ?? null;

        if (! is_string($fromBody) || $fromBody === '') {
            return $event->id;
        }

        if ($fromBody !== $event->id) {
            Log::warning('Tmesis fleet event id and body delivery differ; the body wins.', [
                'node' => $node->name,
                'id' => $event->id,
                'delivery' => $fromBody,
            ]);
        }

        return $fromBody;
    }

    /**
     * The full desired state when the node's applied revision OR its revocation list
     * differs from what the package intends (tmesis WP5, R16: an admin-API push moves
     * the list without a revision), otherwise null. Null too when the provider failed:
     * the node keeps what it last applied.
     *
     * @return array<string, mixed>|null
     */
    private function desiredFor(FleetNode $node, HeartbeatRequest $request): ?array
    {
        $resolved = $this->desired->resolve($node);

        if ($resolved === null) {
            return null;
        }

        if ($request->desiredRevisionApplied === $resolved->revision
            && $request->revocationsDigest === $resolved->revocationsDigest()) {
            return null;
        }

        return $resolved->toWire();
    }

    /**
     * @return array{id: string, kind: string, stream: string|null, args: object}
     */
    private static function commandWire(stdClass $row): array
    {
        /** @var array<string, string> $args */
        $args = json_decode((string) $row->args, true) ?: [];

        return [
            'id' => (string) $row->id,
            'kind' => (string) $row->kind,
            'stream' => is_string($row->stream) ? $row->stream : null,
            'args' => (object) $args,
        ];
    }

    /**
     * The package's own observations of this beat, in the order a listener would want
     * them: recovery, restart, admission, the jobs pause, revisions, commands, then the
     * beat itself.
     *
     * @param  list<stdClass>  $acked
     * @param  list<stdClass>  $expired
     * @return list<object>
     */
    private function observations(FleetNode $before, FleetNode $after, array $acked, array $expired): array
    {
        $out = [];

        if ($before->isStale()) {
            $out[] = new FleetNodeRecovered($after);
        }

        if ($before->startedAt !== null && $before->startedAt !== $after->startedAt) {
            $out[] = new FleetNodeRestarted($after);
        }

        if ($before->admission !== $after->admission) {
            $out[] = new FleetAdmissionChanged($after, $before->admission);
        }

        if ($before->jobsPause?->toArray() !== $after->jobsPause?->toArray()) {
            $out[] = new FleetJobsPauseChanged($after, $before->jobsPause);
        }

        if ($after->appliedRevision > $before->appliedRevision) {
            $out[] = new FleetRevisionApplied($after);
        }

        if ($after->rejected !== null && $after->rejected->revision !== $before->rejected?->revision) {
            $out[] = new FleetRevisionRejected($after, $after->rejected);
        }

        foreach ($acked as $row) {
            $out[] = new FleetCommandAcked((string) $row->id, $after->id, (string) $row->kind, is_string($row->stream) ? $row->stream : null, $row->expired_at !== null);
        }

        foreach ($expired as $row) {
            $out[] = new FleetCommandExpired((string) $row->id, $after->id, (string) $row->kind, is_string($row->stream) ? $row->stream : null);
        }

        $out[] = new FleetHeartbeatReceived($after);

        return $out;
    }
}
