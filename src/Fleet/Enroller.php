<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Fleet\EnrollRequest;
use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeEnrolled;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeLeft;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeReenrolled;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Enrollment and deregistration (plan F2 and F8), shared by the routes and the fake.
 *
 * First use of a join token creates the node and its one fleet token in ONE
 * transaction. A repeat inside `retry_window_minutes` of the first use (tmesis D12)
 * answers the SAME node id with a FRESH fleet token that replaces the stored hash, so
 * the previous token dies in the same statement: this is the node's recovery for a
 * response it never read or a local write that failed. Outside the window, and for an
 * unknown or expired token, the answer is one `401 unknown_token`.
 *
 * The node's `node` name is recorded, never adopted: the name is the one the join
 * token was minted for. A first use for a name that is already enrolled SUPERSEDES the
 * older node (it is marked left, reason `superseded`): two enrolled nodes under one
 * name would both be sent that name's desired state.
 */
class Enroller
{
    public const FLEET_TOKEN_PREFIX = 'tmf_';

    public function __construct(
        private readonly FleetStore $store,
        private readonly DesiredStates $desired,
        private readonly SecretMinter $minter,
    ) {}

    /**
     * @throws EnrollRefused
     */
    public function enroll(#[SensitiveParameter] string $joinToken, EnrollRequest $request): EnrollOutcome
    {
        $tokenSha = hash('sha256', $joinToken);
        $row = $this->store->joinToken($tokenSha);
        $now = now()->toImmutable();

        if ($row === null) {
            throw EnrollRefused::unknownToken();
        }

        if (is_string($row->role) && $row->role !== '' && $row->role !== $request->role) {
            throw new EnrollRefused(422, 'role_mismatch', "The join token was minted for the role [{$row->role}]; this node reports [{$request->role}].");
        }

        $fleetToken = self::FLEET_TOKEN_PREFIX.$this->minter->secret();
        $fleetSha = hash('sha256', $fleetToken);

        if ($row->first_used_at === null) {
            if ($now->greaterThanOrEqualTo(CarbonImmutable::parse((string) $row->expires_at, 'UTC'))) {
                throw EnrollRefused::unknownToken();
            }

            $nodeId = (string) Str::ulid();
            $name = (string) $row->name;
            /** @var array<string, string> $labels */
            $labels = json_decode((string) $row->labels, true) ?: [];

            [$won, $superseded] = $this->store->transaction(function () use ($tokenSha, $nodeId, $name, $request, $labels, $fleetSha, $now): array {
                if (! $this->store->markJoinTokenUsed($tokenSha, $nodeId, $now)) {
                    return [false, null];
                }

                $previous = $this->supersede($name, $now);

                $this->store->createNode($nodeId, $name, $request->role, $request->version, $request->listen, $request->capabilities, $labels, $fleetSha, $now);

                return [true, $previous?->id];
            });

            if ($won) {
                if (is_string($superseded) && ($old = $this->store->node($superseded)) !== null) {
                    Event::dispatch(new FleetNodeLeft($old, FleetNodeLeft::REASON_SUPERSEDED));
                }

                $node = $this->node($nodeId);
                $outcome = new EnrollOutcome($nodeId, $name, $fleetToken, $this->desired->resolve($node)?->toWire(), false);
                Event::dispatch(new FleetNodeEnrolled($this->node($nodeId)));

                return $outcome;
            }

            // Lost a race with a concurrent first use of the same token: that one
            // created the node, so this is a repeat.
            $row = $this->store->joinToken($tokenSha) ?? throw EnrollRefused::unknownToken();
        }

        $firstUsed = CarbonImmutable::parse((string) $row->first_used_at, 'UTC');

        if ($now->greaterThan($firstUsed->addMinutes(FleetConfig::retryWindowMinutes())) || ! is_string($row->node_id)) {
            throw EnrollRefused::unknownToken();
        }

        $existing = $this->store->nodeRow($row->node_id);

        if ($existing === null || $existing->left_at !== null) {
            throw EnrollRefused::unknownToken();
        }

        $this->store->replaceToken($row->node_id, $fleetSha, $request->version, $request->listen, $request->capabilities);

        $node = $this->node($row->node_id);
        $outcome = new EnrollOutcome($node->id, $node->name, $fleetToken, $this->desired->resolve($node)?->toWire(), true);
        Event::dispatch(new FleetNodeReenrolled($this->node($row->node_id)));

        return $outcome;
    }

    /**
     * Deregister the node a fleet token authenticates (DELETE fleet/enroll, tmesis
     * WP7). False for an unknown or already-left token, which the route answers 404:
     * the node treats 404 as gone, so a DELETE whose answer was lost is idempotent.
     */
    public function leave(#[SensitiveParameter] string $fleetToken): bool
    {
        $row = $this->store->nodeRowByToken(hash('sha256', $fleetToken));

        if ($row === null) {
            return false;
        }

        $now = now()->toImmutable();
        $left = $this->store->transaction(function () use ($row, $now): bool {
            if (! $this->store->markLeft((string) $row->id, $now)) {
                return false;
            }

            $this->store->expireAllFor((string) $row->id, $now);

            return true;
        });

        if ($left) {
            Event::dispatch(new FleetNodeLeft($this->node((string) $row->id), FleetNodeLeft::REASON_LEFT));
        }

        return $left;
    }

    /**
     * The ONE place the rule lives: a
     * first use for a name that is already enrolled SUPERSEDES the older node, which
     * is marked left (its token dies, its open commands expire), so exactly one node
     * answers to the name. To flip the decision, this method instead throws
     * `new EnrollRefused(409, 'name_enrolled', ...)` when a node is found; nothing
     * else changes. Returns the superseded node, or null when there was none.
     */
    private function supersede(string $name, \DateTimeImmutable $now): ?FleetNode
    {
        $previous = $this->store->nodeNamed($name);

        if ($previous !== null) {
            $this->store->markLeft($previous->id, $now);
            $this->store->expireAllFor($previous->id, $now);
        }

        return $previous;
    }

    private function node(string $id): FleetNode
    {
        return $this->store->node($id) ?? throw new \RuntimeException("Fleet node [{$id}] vanished mid-enrollment.");
    }
}
