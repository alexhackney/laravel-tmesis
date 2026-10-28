<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Fleet\RevocationsDigest;

/**
 * The scripted node behind FakeFleet: what the next heartbeat will report.
 *
 * By default it behaves like a healthy tmesis node: it APPLIES every desired state it
 * is sent (the next beat reports that revision and that list's digest) and ACKS every
 * command it is sent (the next beat lists the ids). `rejectNext()`, `withoutApplying()`
 * and `withoutAcking()` script the other cases. It never holds a fleet token.
 *
 * `jobsPause` is the pause the next beat reports (`v0.9.0`, handover 3.5): null by
 * default, set by `FakeFleet::withJobsPause()`, and replaced by every desired state the
 * node applies (absent or null clears it, a past `until` is held but inactive).
 */
final class FakeFleetNode
{
    public int $appliedRevision = 0;

    public string $revocationsDigest = RevocationsDigest::EMPTY;

    public string $admission = 'open';

    public string $startedAt;

    /** @var array{revision: int, reason: string}|null */
    public ?array $rejected = null;

    /** @var array{until: string|null, reason: string, source: string}|null */
    public ?array $jobsPause = null;

    /** @var list<array{stream: string, state: string, session: string, epoch: int, protocol: string, dual_accept: bool}> */
    public array $streams = [];

    /** @var list<array{id: string, event: string, occurred_at: string, payload: string}> */
    public array $pendingEvents = [];

    /** @var list<string> */
    public array $pendingAcks = [];

    /** @var list<string> every event id an answer has acked */
    public array $ackedEvents = [];

    /** @var list<array{id: string, kind: string, stream: string|null, args: array<string, string>}> every command an answer carried */
    public array $receivedCommands = [];

    public bool $applies = true;

    public bool $acks = true;

    public ?string $rejectNextWith = null;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $role,
    ) {
        $this->startedAt = now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
