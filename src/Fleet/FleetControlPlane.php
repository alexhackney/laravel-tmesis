<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Exceptions\FleetNodeNotEnrolledException;

/**
 * The host app's handle on the fleet: join tokens, node status, commands
 * (`Tmesis::fleet()`). `FakeTmesis::fleet()` swaps in an in-memory one.
 */
interface FleetControlPlane
{
    /**
     * Mint a one-time join token for the box that will be known as $name. The plain
     * value is on the returned object, shown once; only its sha256 is stored.
     *
     * @param  string|null  $role  when set, an enroll reporting another role is refused
     * @param  array<string, string>  $labels  kept on the node, for the host app
     */
    public function joinToken(string $name, ?string $role = null, array $labels = []): JoinToken;

    /**
     * Mint a join token WITHOUT storing it (storeJoinToken() makes it live). For a
     * caller that must render with the token before it knows the render succeeded.
     */
    public function draftJoinToken(string $name, ?string $role = null): JoinToken;

    /**
     * Make a drafted join token live.
     *
     * @param  array<string, string>  $labels
     */
    public function storeJoinToken(JoinToken $token, array $labels = []): void;

    /**
     * @return list<FleetNode> every enrolled node, by name
     */
    public function nodes(): array;

    public function node(string $id): ?FleetNode;

    public function nodeNamed(string $name): ?FleetNode;

    /**
     * Queue a drain: the node closes admission for publishes and jobs IN PLACE
     * (tmesis D3), live sessions end, running jobs finish, and it keeps heartbeating
     * with `admission: draining`. Returns the command id.
     *
     * @throws FleetNodeNotEnrolledException
     */
    public function drain(string $nodeId): string;

    /**
     * Queue a kick: end the session attached to $stream at the moment the node
     * RECEIVES it. A revocation never disconnects an attached publisher; this does.
     * Returns the command id.
     *
     * @throws FleetNodeNotEnrolledException
     */
    public function kick(string $nodeId, string $stream): string;

    /**
     * Queue a seal of $stream after $graceSeconds (0 to 300; the node caps the
     * effective grace at its drain budget, 45 s at tmesis v0.7.0), the same act as
     * `DELETE /api/v1/streams/{id}`; on a fleet-owned stream the node keeps its
     * provisioning (tmesis D14). Returns the command id.
     *
     * @throws FleetNodeNotEnrolledException
     */
    public function seal(string $nodeId, string $stream, int $graceSeconds = 0): string;

    /**
     * Queue an update to $version from the node's `update.base_url` (tmesis `v0.8.0`,
     * `docs/API.md` 15): verify, carry the engines, the target's doctor, one swap and
     * a HOLDING restart, so live publishers drop briefly and rejoin the same session.
     * The version goes out BARE (`0.8.1`, `0.8.1-rc1`): one leading `v` before a digit
     * is stripped here, as the node does. Returns the command id.
     *
     * The command stays unacked while in flight and the NEW process acks it `done`;
     * it is acked `moot` when the update fails, another is in flight, the version is
     * not plausible, or 30 minutes pass. THE ACK CARRIES NO OUTCOME (D44): read the
     * result from the next heartbeat's `version` (`FleetNode`), with both sides through
     * DatabaseFleet::bareVersion(): a box built from a checkout reports `v0.8.2`.
     *
     * @throws FleetNodeNotEnrolledException
     */
    public function update(string $nodeId, string $version): string;

    /**
     * Queue a `reload_env` (tmesis `v0.8.0`): a holding drain and restart that rereads
     * `/etc/tmesis/.env`, say after a secret rotation. The new process acks `done`; it
     * is `moot` while an update is in flight. Returns the command id.
     *
     * @throws FleetNodeNotEnrolledException
     */
    public function reloadEnv(string $nodeId): string;
}
