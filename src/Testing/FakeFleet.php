<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Fleet\EnrollRequest;
use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Data\Fleet\HeartbeatRequest;
use AlexHackney\Tmesis\Data\Fleet\JobsPauseState;
use AlexHackney\Tmesis\Fleet\DatabaseFleet;
use AlexHackney\Tmesis\Fleet\Enroller;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Fleet\HeartbeatHandler;
use AlexHackney\Tmesis\Fleet\JoinToken;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

/**
 * The fleet control plane for an app's tests (plan F9): `Tmesis::fake()->fleet()`.
 *
 * It is NOT a second implementation. Enrolment, the heartbeat and every command go
 * through the package's real Enroller, FleetStore and HeartbeatHandler, minus HTTP and
 * tokens; a second, in-memory copy of the receiver is exactly what this design rejects, and
 * the one thing a consumer's tests must never do is pass against semantics the real
 * receiver does not have. So it needs the package migrations on the test database
 * (RefreshDatabase with `tmesis-migrations` published, or loadMigrationsFrom), and it
 * calls the app's DesiredStateProvider exactly as a real heartbeat does.
 *
 * What it adds is a scripted node per enrolment (FakeFleetNode): `heartbeat()` builds a
 * valid `fleet.heartbeat.request` from that node's state, runs it through the handler
 * (including the after-response dispatch of claimed events and the observation
 * events), and returns the decoded answer; by default the node applies what it is sent
 * and acks what it received, like a healthy node. That includes the told jobs pause
 * (`v0.9.0`, handover 3.5): an applied desired state replaces the node's pause with its
 * `jobs_pause` (absent or null clears it, an API one included), and a pause whose
 * `until` has passed on Laravel's clock is reported as none, as the node's timer would.
 *
 * Relay URLs in a desired document stay in the answer the test receives (the node needs
 * them); no assertion message here prints one, and no token is held after enrolment.
 */
class FakeFleet implements FleetControlPlane
{
    /** @var array<string, FakeFleetNode> by node id */
    private array $nodes = [];

    public function __construct(
        private readonly DatabaseFleet $fleet,
        private readonly FleetStore $store,
        private readonly Enroller $enroller,
        private readonly HeartbeatHandler $handler,
    ) {}

    // ------------------------------------------------------------- the real surface

    public function joinToken(string $name, ?string $role = null, array $labels = []): JoinToken
    {
        return $this->fleet->joinToken($name, $role, $labels);
    }

    public function draftJoinToken(string $name, ?string $role = null): JoinToken
    {
        return $this->fleet->draftJoinToken($name, $role);
    }

    public function storeJoinToken(JoinToken $token, array $labels = []): void
    {
        $this->fleet->storeJoinToken($token, $labels);
    }

    public function nodes(): array
    {
        return $this->fleet->nodes();
    }

    public function node(string $id): ?FleetNode
    {
        return $this->fleet->node($id);
    }

    public function nodeNamed(string $name): ?FleetNode
    {
        return $this->fleet->nodeNamed($name);
    }

    public function drain(string $nodeId): string
    {
        return $this->fleet->drain($nodeId);
    }

    public function kick(string $nodeId, string $stream): string
    {
        return $this->fleet->kick($nodeId, $stream);
    }

    public function seal(string $nodeId, string $stream, int $graceSeconds = 0): string
    {
        return $this->fleet->seal($nodeId, $stream, $graceSeconds);
    }

    public function update(string $nodeId, string $version): string
    {
        return $this->fleet->update($nodeId, $version);
    }

    public function reloadEnv(string $nodeId): string
    {
        return $this->fleet->reloadEnv($nodeId);
    }

    // ----------------------------------------------------------------- the scripts

    /**
     * Enrol a node by name, through the real Enroller (join token minted and used at
     * once; the fleet token is discarded). Returns its scripted node.
     *
     * @param  array<string, bool>  $capabilities
     * @param  array<string, string>  $labels
     */
    public function enrol(string $name, string $role = 'edge', array $capabilities = [], array $labels = []): FakeFleetNode
    {
        $token = $this->fleet->joinToken($name, $role, $labels);

        $outcome = $this->enroller->enroll($token->reveal(), new EnrollRequest(
            node: $name,
            role: $role,
            version: 'fake',
            listen: ['rtmp' => ':1935', 'srt' => ':6001'],
            capabilities: $capabilities,
        ));

        $node = new FakeFleetNode($outcome->nodeId, $outcome->nodeName, $role);
        $this->nodes[$outcome->nodeId] = $node;

        if ($outcome->desiredState !== null) {
            $this->received($node, ['desired_state' => $outcome->desiredState, 'commands' => [], 'ack_event_ids' => []]);
        }

        return $node;
    }

    /**
     * Queue one node event for the next beat, as the node would carry it: the payload
     * becomes the body string, with `event`, `delivery`, `node` and `occurred_at` set
     * when absent. Returns the event id (its dedupe key).
     *
     * @param  array<string, mixed>  $payload
     */
    public function event(string $nodeId, string $event, array $payload = []): string
    {
        $node = $this->scripted($nodeId);
        $id = (string) ($payload['delivery'] ?? Str::ulid());
        $payload += ['event' => $event, 'delivery' => $id, 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'node' => $node->name, 'node_role' => $node->role, 'contract_version' => 1];

        $node->pendingEvents[] = [
            'id' => $id,
            'event' => $event,
            'occurred_at' => (string) $payload['occurred_at'],
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        return $id;
    }

    /**
     * One heartbeat from the scripted node. $overrides replace request fields for this
     * beat only (`admission`, `streams`, ...). Returns the decoded answer.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{ack_event_ids: list<string>, desired_state: array<string, mixed>|null, commands: list<array<string, mixed>>}
     */
    public function heartbeat(string $nodeId, array $overrides = []): array
    {
        $node = $this->scripted($nodeId);
        $row = $this->store->nodeRow($nodeId);

        if ($row === null || $row->left_at !== null) {
            throw new RuntimeException("The fake fleet node [{$nodeId}] is not enrolled: a real node would get 401.");
        }

        if ($node->jobsPause !== null && ! self::pauseActive($node->jobsPause['until'])) {
            $node->jobsPause = null;
        }

        $body = array_merge([
            'node' => $node->name,
            'role' => $node->role,
            'version' => 'fake',
            'started_at' => $node->startedAt,
            'observed_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'desired_revision_applied' => $node->appliedRevision,
            'admission' => $node->admission,
            'revocations_digest' => $node->revocationsDigest,
            'desired_revision_rejected' => $node->rejected,
            'stats' => ['uptime_seconds' => 1, 'sessions_active' => count(array_filter($node->streams, fn ($s): bool => $s['session'] !== '')), 'disk_free_bytes' => 1 << 40, 'disk_floor_breached' => false, 'outbox_depth' => 0, 'recordings_unregistered' => 0],
            'streams' => $node->streams,
            'events' => $node->pendingEvents,
            'acked_command_ids' => $node->pendingAcks,
            'jobs_pause' => $node->jobsPause,
        ], $overrides);

        $request = HeartbeatRequest::fromRaw(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $outcome = $this->handler->handle($row, $request);
        $this->handler->afterResponse($outcome);

        /** @var array{ack_event_ids: list<string>, desired_state: array<string, mixed>|null, commands: list<array<string, mixed>>} $answer */
        $answer = json_decode((string) json_encode($outcome->body, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $node->pendingEvents = array_values(array_filter($node->pendingEvents, fn (array $e): bool => ! in_array($e['id'], $answer['ack_event_ids'], true)));
        $node->pendingAcks = [];
        $this->received($node, $answer);

        return $answer;
    }

    /**
     * The node refuses the next desired state it is sent, reporting $reason.
     */
    public function rejectNext(string $nodeId, string $reason = 'streams[0]: refused by the fake node'): self
    {
        $this->scripted($nodeId)->rejectNextWith = $reason;

        return $this;
    }

    /**
     * The jobs pause the node reports from the next beat on, as if set on the box
     * (`source: api`, the pause route before it joined) or restored from its journal;
     * null for none. A desired state the node applies replaces it.
     */
    public function withJobsPause(string $nodeId, ?JobsPauseState $pause): self
    {
        $this->scripted($nodeId)->jobsPause = $pause?->toArray();

        return $this;
    }

    /**
     * The node stops applying what it is sent (it keeps reporting its old revision).
     */
    public function withoutApplying(string $nodeId): self
    {
        $this->scripted($nodeId)->applies = false;

        return $this;
    }

    /**
     * The node stops acking commands.
     */
    public function withoutAcking(string $nodeId): self
    {
        $this->scripted($nodeId)->acks = false;

        return $this;
    }

    public function scripted(string $nodeId): FakeFleetNode
    {
        return $this->nodes[$nodeId] ?? throw new RuntimeException("No fake fleet node [{$nodeId}]: enrol() it first.");
    }

    // ---------------------------------------------------------------- assertions

    public function assertCommandQueued(string $nodeId, string $kind, ?string $stream = null): self
    {
        $query = $this->store->commandsFor($nodeId)->where('kind', $kind)->whereNull('acked_at')->whereNull('expired_at');

        if ($stream !== null) {
            $query->where('stream', $stream);
        }

        PHPUnit::assertTrue($query->exists(), "No open [{$kind}] command for fleet node [{$nodeId}]".($stream === null ? '.' : " on stream [{$stream}]."));

        return $this;
    }

    public function assertNothingQueued(string $nodeId): self
    {
        $open = $this->store->commandsFor($nodeId)->whereNull('acked_at')->whereNull('expired_at')->pluck('kind')->all();

        PHPUnit::assertSame([], $open, "Fleet node [{$nodeId}] has open commands: ".implode(', ', $open).'.');

        return $this;
    }

    public function assertCommandAcked(string $commandId): self
    {
        $row = $this->store->commandRow($commandId);

        PHPUnit::assertNotNull($row, "No fleet command [{$commandId}].");
        PHPUnit::assertNotNull($row->acked_at, "The fleet command [{$commandId}] was not acked.");

        return $this;
    }

    public function assertDesiredRevision(string $nodeId, int $revision): self
    {
        PHPUnit::assertSame($revision, $this->fleet->node($nodeId)?->intendedRevision, "The fleet node [{$nodeId}] is not intended at revision {$revision}.");

        return $this;
    }

    public function assertConverged(string $nodeId): self
    {
        PHPUnit::assertTrue((bool) $this->fleet->node($nodeId)?->converged(), "The fleet node [{$nodeId}] has not converged on its intended revision and revocation list.");

        return $this;
    }

    public function assertEventAcked(string $nodeId, string $eventId): self
    {
        PHPUnit::assertContains($eventId, $this->scripted($nodeId)->ackedEvents, "The fleet node [{$nodeId}] never had event [{$eventId}] acked.");

        return $this;
    }

    /**
     * What the scripted node does with an answer: apply (or reject) the desired state,
     * remember the commands and queue their acks, record the event acks.
     *
     * @param  array<string, mixed>  $answer
     */
    private function received(FakeFleetNode $node, array $answer): void
    {
        /** @var list<string> $acked */
        $acked = $answer['ack_event_ids'] ?? [];
        array_push($node->ackedEvents, ...$acked);

        $desired = $answer['desired_state'] ?? null;

        if (is_array($desired)) {
            $revision = (int) $desired['revision'];

            if ($node->rejectNextWith !== null) {
                $node->rejected = ['revision' => $revision, 'reason' => $node->rejectNextWith];
                $node->rejectNextWith = null;
            } elseif ($node->applies) {
                $node->appliedRevision = $revision;
                /** @var list<string> $revocations */
                $revocations = $desired['revocations'] ?? [];
                $node->revocationsDigest = RevocationsDigest::of($revocations);
                $node->jobsPause = self::appliedPause($desired['jobs_pause'] ?? null);
            }
        }

        /** @var list<array{id: string, kind: string, stream: string|null, args: array<string, string>}> $commands */
        $commands = $answer['commands'] ?? [];

        foreach ($commands as $command) {
            $node->receivedCommands[] = $command;

            if ($node->acks) {
                $node->pendingAcks[] = $command['id'];

                if ($command['kind'] === 'drain') {
                    $node->admission = 'draining';
                }
            }
        }
    }

    /**
     * A desired `jobs_pause` as the node holds it: absent or null is none, and a past
     * `until` is stored but inactive, so it reports none.
     *
     * @return array{until: string|null, reason: string, source: string}|null
     */
    private static function appliedPause(mixed $desired): ?array
    {
        if (! is_array($desired)) {
            return null;
        }

        $until = is_string($desired['until'] ?? null) ? $desired['until'] : null;

        if (! self::pauseActive($until)) {
            return null;
        }

        return ['until' => $until, 'reason' => (string) ($desired['reason'] ?? ''), 'source' => JobsPauseState::SOURCE_FLEET];
    }

    private static function pauseActive(?string $until): bool
    {
        return $until === null || CarbonImmutable::parse($until)->isAfter(now());
    }
}
