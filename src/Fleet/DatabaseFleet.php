<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Exceptions\FleetNodeNotEnrolledException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Keys\StreamId;
use Illuminate\Support\Str;

/**
 * The fleet control plane over the package's own tables.
 */
class DatabaseFleet implements FleetControlPlane
{
    /**
     * The range the node ACCEPTS for a seal's grace (API.md, the stream DELETE's
     * `grace_seconds`, 0 to 300). The node then CAPS the effective value at its own
     * drain budget (45 s at tmesis v0.7.0) and honours that; the budget is the node's
     * to set, so it is not encoded here.
     */
    public const MAX_GRACE_SECONDS = 300;

    public function __construct(
        private readonly FleetStore $store,
        private readonly SecretMinter $minter,
    ) {}

    public function joinToken(string $name, ?string $role = null, array $labels = []): JoinToken
    {
        $token = $this->draftJoinToken($name, $role);
        $this->storeJoinToken($token, $labels);

        return $token;
    }

    public function draftJoinToken(string $name, ?string $role = null): JoinToken
    {
        if (trim($name) === '') {
            throw InvalidConfigurationException::invalid('name', 'a join token is minted for a node name');
        }

        if ($role !== null && NodeRole::tryFrom($role) === null) {
            throw InvalidConfigurationException::invalid('role', 'it must be edge, origin, transcoder or standalone');
        }

        return JoinToken::mint($this->minter->secret(), $name, $role, now()->toImmutable()->addMinutes(FleetConfig::joinTokenTtlMinutes()));
    }

    public function storeJoinToken(JoinToken $token, array $labels = []): void
    {
        $this->store->insertJoinToken($token->sha256(), $token->name, $token->role, $labels, $token->expiresAt, now()->toImmutable());
    }

    public function nodes(): array
    {
        return $this->store->nodes();
    }

    public function node(string $id): ?FleetNode
    {
        return $this->store->node($id);
    }

    public function nodeNamed(string $name): ?FleetNode
    {
        return $this->store->nodeNamed($name);
    }

    public function drain(string $nodeId): string
    {
        return $this->queue($nodeId, 'drain', null, []);
    }

    public function kick(string $nodeId, string $stream): string
    {
        return $this->queue($nodeId, 'kick', self::streamId($stream), []);
    }

    public function seal(string $nodeId, string $stream, int $graceSeconds = 0): string
    {
        if ($graceSeconds < 0 || $graceSeconds > self::MAX_GRACE_SECONDS) {
            throw InvalidConfigurationException::invalid('grace_seconds', 'it must be 0 to '.self::MAX_GRACE_SECONDS.' (the node caps the effective grace at its drain budget)');
        }

        // The wire's args are a string map: grace_seconds is decimal digits (API.md 15.4).
        return $this->queue($nodeId, 'seal', self::streamId($stream), ['grace_seconds' => (string) $graceSeconds]);
    }

    public function update(string $nodeId, string $version): string
    {
        $bare = self::bareVersion($version);

        if (! self::plausibleVersion($bare)) {
            throw InvalidConfigurationException::invalid('version', "\"{$bare}\" is not a plausible version string");
        }

        return $this->queue($nodeId, 'update', null, ['version' => $bare]);
    }

    public function reloadEnv(string $nodeId): string
    {
        return $this->queue($nodeId, 'reload_env', null, []);
    }

    /**
     * A release version as the node compares it: one leading `v` before a digit
     * stripped (`cmd/tmesis/update.go` `bareVersion`, TMESIS-60), anything else as
     * given. Releases are bare (`0.8.1`); a heartbeat's `version` is bare on a release
     * build but `git describe` (`v0.8.2`) on a box built from a checkout, so compare
     * this of both sides, as the node does (`internal/node/fleetupdate.go`).
     */
    public static function bareVersion(string $version): string
    {
        return preg_match('/^v[0-9]/', $version) === 1 ? substr($version, 1) : $version;
    }

    /**
     * The node's version grammar (`internal/fleet/updaterequest.go`
     * `PlausibleVersion`): a version becomes a directory name and a URL path segment,
     * so it is non-empty, only `[A-Za-z0-9._-]`, not `.`, and has no `..`. Whether the
     * release EXISTS is the node's (a `moot` ack).
     */
    private static function plausibleVersion(string $version): bool
    {
        return $version !== '.'
            && ! str_contains($version, '..')
            && preg_match('/^[A-Za-z0-9._-]+\z/', $version) === 1;
    }

    /**
     * Queue one command for an ENROLLED node. It is sent on every heartbeat until the
     * node acks it; if no heartbeat carries it within `command_ttl_seconds` it expires
     * undelivered, and once one has, it never expires.
     *
     * @param  array<string, string>  $args
     */
    private function queue(string $nodeId, string $kind, ?string $stream, array $args): string
    {
        $node = $this->store->node($nodeId);

        if ($node === null || ! $node->enrolled()) {
            throw FleetNodeNotEnrolledException::for($nodeId);
        }

        $id = (string) Str::ulid();
        $now = now()->toImmutable();

        $this->store->queueCommand($id, $nodeId, $kind, $stream, $args, $now, $now->addSeconds(FleetConfig::commandTtlSeconds()));

        return $id;
    }

    private static function streamId(string $stream): string
    {
        if (! StreamId::isValid($stream)) {
            throw InvalidConfigurationException::invalid('stream', "[{$stream}] is not a stream id");
        }

        return $stream;
    }
}
