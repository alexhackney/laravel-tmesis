<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Data\Fleet\FleetNodeStats;
use AlexHackney\Tmesis\Data\Fleet\FleetStreamState;
use AlexHackney\Tmesis\Data\Fleet\JobsPauseState;
use AlexHackney\Tmesis\Data\Fleet\RejectedRevision;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use stdClass;

/**
 * The fleet's tables, and the only code that touches them.
 *
 * Every write that the contract makes atomic is ONE statement or one transaction here:
 * the D12 token swap is one UPDATE of the node's single token column (so the previous
 * token is revoked by construction), the revision bump is a compare-and-set on
 * `(node_id, revision)`, and a command's delivery is stamped only where it is null.
 *
 * Tokens arrive here already hashed. Nothing in this class ever sees a plain token or
 * a desired document (which carries relay URLs, credentials this package never
 * persists).
 */
class FleetStore
{
    public const JOIN_TOKENS = 'tmesis_fleet_join_tokens';

    public const NODES = 'tmesis_fleet_nodes';

    public const DESIRED = 'tmesis_fleet_desired';

    public const COMMANDS = 'tmesis_fleet_commands';

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Run $fn in one transaction on the fleet's connection.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public function transaction(Closure $fn): mixed
    {
        return $this->db->transaction(fn (): mixed => $fn());
    }

    // ---------------------------------------------------------------- join tokens

    /**
     * @param  array<string, string>  $labels
     */
    public function insertJoinToken(string $tokenSha256, string $name, ?string $role, array $labels, DateTimeImmutable $expiresAt, DateTimeImmutable $now): void
    {
        $this->table(self::JOIN_TOKENS)->insert([
            'id' => (string) Str::ulid(),
            'token_sha256' => $tokenSha256,
            'name' => $name,
            'role' => $role,
            'labels' => json_encode((object) $labels, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'first_used_at' => null,
            'node_id' => null,
            'created_at' => $now,
        ]);
    }

    public function joinToken(string $tokenSha256): ?stdClass
    {
        /** @var stdClass|null */
        return $this->table(self::JOIN_TOKENS)->where('token_sha256', $tokenSha256)->first();
    }

    /**
     * Bind a join token to the node its first use created. A compare-and-set on
     * `first_used_at` being null, so two concurrent first uses cannot both win: the
     * loser's transaction rolls back and it is answered as a repeat.
     */
    public function markJoinTokenUsed(string $tokenSha256, string $nodeId, DateTimeImmutable $now): bool
    {
        return $this->table(self::JOIN_TOKENS)
            ->where('token_sha256', $tokenSha256)
            ->whereNull('first_used_at')
            ->update(['first_used_at' => $now, 'node_id' => $nodeId]) === 1;
    }

    // ---------------------------------------------------------------------- nodes

    /**
     * @param  array{rtmp?: string, srt?: string}  $listen
     * @param  array<string, bool>  $capabilities
     * @param  array<string, string>  $labels
     */
    public function createNode(
        string $id,
        string $name,
        string $role,
        string $version,
        array $listen,
        array $capabilities,
        array $labels,
        string $tokenSha256,
        DateTimeImmutable $now,
    ): void {
        $this->table(self::NODES)->insert([
            'id' => $id,
            'name' => $name,
            'role' => $role,
            'version' => $version,
            'listen' => json_encode((object) $listen, JSON_THROW_ON_ERROR),
            'capabilities' => json_encode((object) $capabilities, JSON_THROW_ON_ERROR),
            'labels' => json_encode((object) $labels, JSON_THROW_ON_ERROR),
            'token_sha256' => $tokenSha256,
            'enrolled_at' => $now,
            'applied_revision' => 0,
        ]);
    }

    /**
     * Replace the node's ONE live fleet token (the D12 repeat). The previous token
     * stops authenticating in the same statement.
     *
     * @param  array{rtmp?: string, srt?: string}  $listen
     * @param  array<string, bool>  $capabilities
     */
    public function replaceToken(string $nodeId, string $tokenSha256, string $version, array $listen, array $capabilities): void
    {
        $this->table(self::NODES)->where('id', $nodeId)->update([
            'token_sha256' => $tokenSha256,
            'version' => $version,
            'listen' => json_encode((object) $listen, JSON_THROW_ON_ERROR),
            'capabilities' => json_encode((object) $capabilities, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The enrolled node a fleet token authenticates, or null.
     */
    public function nodeRowByToken(string $tokenSha256): ?stdClass
    {
        /** @var stdClass|null */
        return $this->table(self::NODES)
            ->where('token_sha256', $tokenSha256)
            ->whereNull('left_at')
            ->first();
    }

    public function nodeRow(string $id): ?stdClass
    {
        /** @var stdClass|null */
        return $this->table(self::NODES)->where('id', $id)->first();
    }

    public function node(string $id): ?FleetNode
    {
        $row = $this->nodeRow($id);

        return $row === null ? null : $this->toNode($row);
    }

    /**
     * The ENROLLED node with this name, or null. A left node's name is free.
     */
    public function nodeNamed(string $name): ?FleetNode
    {
        /** @var stdClass|null $row */
        $row = $this->table(self::NODES)->where('name', $name)->whereNull('left_at')->orderByDesc('enrolled_at')->first();

        return $row === null ? null : $this->toNode($row);
    }

    /**
     * Every enrolled node, by name.
     *
     * @return list<FleetNode>
     */
    public function nodes(): array
    {
        $rows = $this->table(self::NODES)->whereNull('left_at')->orderBy('name')->get();

        $out = [];

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $out[] = $this->toNode($row);
        }

        return $out;
    }

    /**
     * Record one heartbeat's report. Clears `stale_since`: a node that reported is not
     * stale, whatever it was a minute ago.
     *
     * The jobs pause (`v0.9.0`, handover 3.5) is kept beside the box stats in the
     * `stats` column under `jobs_pause`, not in a column of its own: it is box state
     * from the same beat, and a new column would be a migration every app has to
     * publish before the stop-offering rule works. `FleetNodeStats::fromStored()`
     * ignores the key.
     *
     * @param  list<FleetStreamState>  $streams
     */
    public function recordHeartbeat(
        string $nodeId,
        DateTimeImmutable $now,
        string $version,
        string $startedAt,
        string $admission,
        FleetNodeStats $stats,
        array $streams,
        int $appliedRevision,
        ?RejectedRevision $rejected,
        string $revocationsDigest,
        ?JobsPauseState $jobsPause = null,
    ): void {
        $this->table(self::NODES)->where('id', $nodeId)->update([
            'last_heartbeat_at' => $now,
            'version' => $version,
            'started_at' => $startedAt,
            'admission' => $admission,
            'stats' => json_encode($stats->toArray() + ['jobs_pause' => $jobsPause?->toArray()], JSON_THROW_ON_ERROR),
            'streams' => json_encode(array_map(fn (FleetStreamState $s): array => $s->toArray(), $streams), JSON_THROW_ON_ERROR),
            'applied_revision' => $appliedRevision,
            'rejected_revision' => $rejected?->revision,
            'rejected_reason' => $rejected?->reason,
            'revocations_digest' => $revocationsDigest,
            'stale_since' => null,
        ]);
    }

    /**
     * Mark the node left: its token stops authenticating and its name is free.
     */
    public function markLeft(string $nodeId, DateTimeImmutable $now): bool
    {
        return $this->table(self::NODES)
            ->where('id', $nodeId)
            ->whereNull('left_at')
            ->update(['left_at' => $now, 'token_sha256' => null]) === 1;
    }

    /**
     * Enrolled nodes silent since before $cutoff and not already marked stale. Marks
     * them stale and returns their ids, one conditional UPDATE per node, so two
     * overlapping sweeps fire each streak once.
     *
     * @return list<string>
     */
    public function markStale(DateTimeImmutable $cutoff, DateTimeImmutable $now): array
    {
        $ids = $this->table(self::NODES)
            ->whereNull('left_at')
            ->whereNull('stale_since')
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('last_heartbeat_at', '<', $cutoff)
                    ->orWhere(fn (Builder $q) => $q->whereNull('last_heartbeat_at')->where('enrolled_at', '<', $cutoff));
            })
            ->pluck('id');

        $marked = [];

        foreach ($ids as $id) {
            $won = $this->table(self::NODES)->where('id', $id)->whereNull('stale_since')
                ->whereNull('left_at')
                ->where(function (Builder $q) use ($cutoff): void {
                    $q->where('last_heartbeat_at', '<', $cutoff)
                        ->orWhere(fn (Builder $q) => $q->whereNull('last_heartbeat_at')->where('enrolled_at', '<', $cutoff));
                })
                ->update(['stale_since' => $now]) === 1;

            if ($won) {
                $marked[] = (string) $id;
            }
        }

        return $marked;
    }

    // -------------------------------------------------------------------- desired

    public function desiredRow(string $nodeId): ?stdClass
    {
        /** @var stdClass|null */
        return $this->table(self::DESIRED)->where('node_id', $nodeId)->first();
    }

    /**
     * The revision for a document's hash: the stored one when the hash is unchanged,
     * otherwise the next one, stored by compare-and-set so two web workers answering
     * the same node at once cannot skip or reuse a number. Returns the revision in
     * force after the call.
     *
     * `$previousHashes` are the same document hashed under the app's PREVIOUS keys
     * (`app.previous_keys`). A stored hash equal to one of them is the same content
     * under a rotated key: it is rewritten under the current key at the SAME revision,
     * so an APP_KEY rotation done Laravel's way never bumps a node.
     *
     * @param  list<string>  $previousHashes
     */
    public function revisionFor(string $nodeId, string $documentHash, string $revocationsDigest, DateTimeImmutable $now, array $previousHashes = []): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = $this->desiredRow($nodeId);

            if ($row === null) {
                try {
                    $this->table(self::DESIRED)->insert([
                        'node_id' => $nodeId,
                        'revision' => 1,
                        'document_hash' => $documentHash,
                        'revocations_digest' => $revocationsDigest,
                        'updated_at' => $now,
                    ]);

                    return 1;
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            if (hash_equals((string) $row->document_hash, $documentHash)) {
                return (int) $row->revision;
            }

            foreach ($previousHashes as $previous) {
                if (hash_equals((string) $row->document_hash, $previous)) {
                    // Same content, rotated key: re-key in place, same revision. A
                    // concurrent writer that moved the row first wins; the loop reads
                    // again.
                    $rekeyed = $this->table(self::DESIRED)
                        ->where('node_id', $nodeId)
                        ->where('revision', (int) $row->revision)
                        ->where('document_hash', (string) $row->document_hash)
                        ->update(['document_hash' => $documentHash, 'updated_at' => $now]) === 1;

                    if ($rekeyed) {
                        return (int) $row->revision;
                    }

                    continue 2;
                }
            }

            $next = (int) $row->revision + 1;

            $won = $this->table(self::DESIRED)
                ->where('node_id', $nodeId)
                ->where('revision', (int) $row->revision)
                ->update([
                    'revision' => $next,
                    'document_hash' => $documentHash,
                    'revocations_digest' => $revocationsDigest,
                    'updated_at' => $now,
                ]) === 1;

            if ($won) {
                return $next;
            }
        }

        throw new \RuntimeException("The desired revision for fleet node [{$nodeId}] kept moving under five compare-and-set attempts.");
    }

    // ------------------------------------------------------------------- commands

    /**
     * @param  array<string, string>  $args
     */
    public function queueCommand(string $id, string $nodeId, string $kind, ?string $stream, array $args, DateTimeImmutable $now, DateTimeImmutable $expiresAt): void
    {
        $this->table(self::COMMANDS)->insert([
            'id' => $id,
            'node_id' => $nodeId,
            'kind' => $kind,
            'stream' => $stream,
            'args' => json_encode((object) $args, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * A query over one node's commands, for the fake's assertions.
     */
    public function commandsFor(string $nodeId): Builder
    {
        return $this->table(self::COMMANDS)->where('node_id', $nodeId);
    }

    public function commandRow(string $id): ?stdClass
    {
        /** @var stdClass|null */
        return $this->table(self::COMMANDS)->where('id', $id)->first();
    }

    /**
     * The node validates at most this many commands in one answer (tmesis WP6, the
     * coordinator's note of 2026-09-28): more would make the whole answer a
     * `bad_response`. The rest go in later answers, oldest first.
     */
    public const MAX_COMMANDS_PER_ANSWER = 100;

    /**
     * The commands to put in this node's answer, oldest first and at most
     * MAX_COMMANDS_PER_ANSWER: unacked, not expired, and either already DELIVERED
     * (re-sent until acked, however old, because the node may hold it) or still inside
     * its delivery TTL.
     *
     * @return list<stdClass>
     */
    public function deliverableCommands(string $nodeId, DateTimeImmutable $now): array
    {
        /** @var list<stdClass> */
        return $this->table(self::COMMANDS)
            ->where('node_id', $nodeId)
            ->whereNull('acked_at')
            ->whereNull('expired_at')
            ->where(function (Builder $q) use ($now): void {
                $q->whereNotNull('first_sent_at')->orWhere('expires_at', '>', $now);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_COMMANDS_PER_ANSWER)
            ->get()
            ->all();
    }

    /**
     * Stamp delivery on the commands of a 2xx answer, only where not yet stamped.
     *
     * @param  list<string>  $ids
     */
    public function markDelivered(array $ids, DateTimeImmutable $now): void
    {
        if ($ids === []) {
            return;
        }

        $this->table(self::COMMANDS)->whereIn('id', $ids)->whereNull('first_sent_at')->update(['first_sent_at' => $now]);
    }

    /**
     * Ack this node's commands by id. Returns the rows newly acked (an id acked twice,
     * or another node's id, is not in the list).
     *
     * @param  list<string>  $ids
     * @return list<stdClass>
     */
    public function ackCommands(string $nodeId, array $ids, DateTimeImmutable $now): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->table(self::COMMANDS)
            ->where('node_id', $nodeId)
            ->whereIn('id', $ids)
            ->whereNull('acked_at')
            ->get()
            ->all();

        $acked = [];

        foreach ($rows as $row) {
            $won = $this->table(self::COMMANDS)->where('id', $row->id)->whereNull('acked_at')
                ->update(['acked_at' => $now]) === 1;

            if ($won) {
                $acked[] = $row;
            }
        }

        return $acked;
    }

    /**
     * Expire every command that was NEVER delivered and is past its TTL. A delivered
     * command is never expired here (the node may hold it). Returns the expired rows.
     *
     * @return list<stdClass>
     */
    public function expireUndelivered(DateTimeImmutable $now, ?string $nodeId = null): array
    {
        $query = $this->table(self::COMMANDS)
            ->whereNull('acked_at')
            ->whereNull('expired_at')
            ->whereNull('first_sent_at')
            ->where('expires_at', '<=', $now);

        if ($nodeId !== null) {
            $query->where('node_id', $nodeId);
        }

        /** @var list<stdClass> $rows */
        $rows = $query->get()->all();

        $expired = [];

        foreach ($rows as $row) {
            $won = $this->table(self::COMMANDS)->where('id', $row->id)
                ->whereNull('first_sent_at')->whereNull('expired_at')->whereNull('acked_at')
                ->update(['expired_at' => $now]) === 1;

            if ($won) {
                $expired[] = $row;
            }
        }

        return $expired;
    }

    /**
     * Expire every open command of a node that left, delivered or not: there is no
     * heartbeat left to carry them.
     */
    public function expireAllFor(string $nodeId, DateTimeImmutable $now): int
    {
        return $this->table(self::COMMANDS)
            ->where('node_id', $nodeId)
            ->whereNull('acked_at')
            ->whereNull('expired_at')
            ->update(['expired_at' => $now]);
    }

    /**
     * Delete settled rows older than $before: acked or expired commands, and join
     * tokens that were used or expired. Returns the number of rows deleted.
     */
    public function prune(DateTimeImmutable $before): int
    {
        $commands = $this->table(self::COMMANDS)
            ->where(function (Builder $q) use ($before): void {
                $q->where('acked_at', '<', $before)->orWhere('expired_at', '<', $before);
            })
            ->delete();

        $tokens = $this->table(self::JOIN_TOKENS)
            ->where(function (Builder $q) use ($before): void {
                $q->where('first_used_at', '<', $before)
                    ->orWhere(fn (Builder $q) => $q->whereNull('first_used_at')->where('expires_at', '<', $before));
            })
            ->delete();

        return $commands + $tokens;
    }

    // -------------------------------------------------------------------- helpers

    public function toNode(stdClass $row): FleetNode
    {
        $desired = $this->desiredRow((string) $row->id);

        $stats = self::json($row->stats ?? null);
        $streams = self::json($row->streams ?? null) ?? [];

        /** @var array{rtmp?: string, srt?: string} $listen */
        $listen = self::json($row->listen) ?? [];
        /** @var array<string, bool> $capabilities */
        $capabilities = self::json($row->capabilities) ?? [];
        /** @var array<string, string> $labels */
        $labels = self::json($row->labels ?? null) ?? [];

        return new FleetNode(
            id: (string) $row->id,
            name: (string) $row->name,
            role: (string) $row->role,
            version: (string) $row->version,
            listen: $listen,
            capabilities: $capabilities,
            labels: $labels,
            enrolledAt: self::time($row->enrolled_at) ?? CarbonImmutable::now(),
            leftAt: self::time($row->left_at ?? null),
            lastHeartbeatAt: self::time($row->last_heartbeat_at ?? null),
            startedAt: is_string($row->started_at ?? null) ? $row->started_at : null,
            admission: is_string($row->admission ?? null) ? $row->admission : null,
            stats: is_array($stats) ? FleetNodeStats::fromStored($stats) : null,
            streams: array_values(array_map(fn (mixed $s): FleetStreamState => FleetStreamState::fromStored(is_array($s) ? $s : []), $streams)),
            appliedRevision: (int) $row->applied_revision,
            rejected: $row->rejected_revision === null ? null : new RejectedRevision((int) $row->rejected_revision, (string) $row->rejected_reason),
            revocationsDigest: is_string($row->revocations_digest ?? null) ? $row->revocations_digest : null,
            intendedRevision: $desired === null ? null : (int) $desired->revision,
            intendedRevocationsDigest: $desired === null ? null : (string) $desired->revocations_digest,
            staleSince: self::time($row->stale_since ?? null),
            jobsPause: is_array($stats['jobs_pause'] ?? null) ? JobsPauseState::fromStored($stats['jobs_pause']) : null,
        );
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function json(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return CarbonImmutable::instance($value);
        }

        return CarbonImmutable::parse((string) $value, 'UTC');
    }

    private function table(string $name): Builder
    {
        return $this->db->table($name);
    }
}
