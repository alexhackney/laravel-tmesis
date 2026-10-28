<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\FleetNodeStats;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;
use Carbon\CarbonImmutable;

/*
 * F1: the fleet store's compare-and-set writes. These are the places the contract
 * makes atomic: the D12 token swap, the monotonic revision, command delivery and
 * expiry.
 */

beforeEach(function (): void {
    $this->withFleet();
    $this->store = app(FleetStore::class);
    $this->now = CarbonImmutable::parse('2026-09-28T12:00:00Z');
    $this->store->createNode('01J8ZCP4M2V8QNX4RW7TKB3PD9', 'edge-venue-07', 'edge', '0.7.0', ['rtmp' => ':1935'], ['fleet.relays' => true], [], str_repeat('a', 64), $this->now);
});

it('reads an enrolled node back, and finds it by its token hash alone', function (): void {
    $node = $this->store->node('01J8ZCP4M2V8QNX4RW7TKB3PD9');

    expect($node->name)->toBe('edge-venue-07')
        ->and($node->enrolled())->toBeTrue()
        ->and($node->appliedRevision)->toBe(0)
        ->and($node->intendedRevision)->toBeNull()
        ->and($node->converged())->toBeFalse()
        ->and($node->hasCapability('fleet.relays'))->toBeTrue()
        ->and($this->store->nodeRowByToken(str_repeat('a', 64))?->id)->toBe('01J8ZCP4M2V8QNX4RW7TKB3PD9')
        ->and($this->store->nodeRowByToken(str_repeat('b', 64)))->toBeNull();
});

it('revokes the previous token in the same statement that installs the next', function (): void {
    $this->store->replaceToken('01J8ZCP4M2V8QNX4RW7TKB3PD9', str_repeat('c', 64), '0.7.1', [], []);

    expect($this->store->nodeRowByToken(str_repeat('a', 64)))->toBeNull()
        ->and($this->store->nodeRowByToken(str_repeat('c', 64))?->version)->toBe('0.7.1');
});

it('moves the revision only when the document hash moves, starting at 1', function (): void {
    $id = '01J8ZCP4M2V8QNX4RW7TKB3PD9';

    expect($this->store->revisionFor($id, str_repeat('1', 64), 'd1', $this->now))->toBe(1)
        ->and($this->store->revisionFor($id, str_repeat('1', 64), 'd1', $this->now))->toBe(1)
        ->and($this->store->revisionFor($id, str_repeat('2', 64), 'd2', $this->now))->toBe(2)
        ->and($this->store->revisionFor($id, str_repeat('2', 64), 'd2', $this->now))->toBe(2)
        // Back to an earlier content is still a NEW revision: the node only moves up.
        ->and($this->store->revisionFor($id, str_repeat('1', 64), 'd1', $this->now))->toBe(3);

    $node = $this->store->node($id);
    expect($node->intendedRevision)->toBe(3)
        ->and($node->intendedRevocationsDigest)->toBe('d1');
});

it('never expires a delivered command, and expires an undelivered one past its TTL', function (): void {
    $id = '01J8ZCP4M2V8QNX4RW7TKB3PD9';
    $this->store->queueCommand('01CMDDELIVERED00000000000A', $id, 'kick', 'show', [], $this->now, $this->now->addMinutes(15));
    $this->store->queueCommand('01CMDNEVERSENT00000000000B', $id, 'kick', 'show', [], $this->now, $this->now->addMinutes(15));

    $this->store->markDelivered(['01CMDDELIVERED00000000000A'], $this->now->addMinute());

    $later = $this->now->addHours(2);
    $expired = $this->store->expireUndelivered($later);

    expect(array_map(fn ($r) => $r->id, $expired))->toBe(['01CMDNEVERSENT00000000000B'])
        ->and(array_map(fn ($r) => $r->id, $this->store->deliverableCommands($id, $later)))->toBe(['01CMDDELIVERED00000000000A']);
});

it('stamps delivery once, and acks only this node\'s commands, once', function (): void {
    $id = '01J8ZCP4M2V8QNX4RW7TKB3PD9';
    $this->store->queueCommand('01CMD0000000000000000000AA', $id, 'drain', null, [], $this->now, $this->now->addMinutes(15));

    $this->store->markDelivered(['01CMD0000000000000000000AA'], $this->now);
    $this->store->markDelivered(['01CMD0000000000000000000AA'], $this->now->addHour());

    expect(CarbonImmutable::parse($this->store->commandRow('01CMD0000000000000000000AA')->first_sent_at)->equalTo($this->now))->toBeTrue()
        ->and($this->store->ackCommands('01OTHERNODE000000000000000', ['01CMD0000000000000000000AA'], $this->now))->toBe([])
        ->and(count($this->store->ackCommands($id, ['01CMD0000000000000000000AA'], $this->now)))->toBe(1)
        ->and($this->store->ackCommands($id, ['01CMD0000000000000000000AA'], $this->now))->toBe([]);
});

it('marks a silent node stale once per streak, and a heartbeat clears it', function (): void {
    $id = '01J8ZCP4M2V8QNX4RW7TKB3PD9';
    $later = $this->now->addMinutes(5);

    expect($this->store->markStale($later->subSeconds(60), $later))->toBe([$id])
        ->and($this->store->markStale($later->subSeconds(60), $later))->toBe([])
        ->and($this->store->node($id)->isStale())->toBeTrue();

    $this->store->recordHeartbeat($id, $later, '0.7.0', '2026-09-28T11:00:00Z', 'open',
        new FleetNodeStats(1, 0, 1, false, 0, 0), [], 0, null, RevocationsDigest::EMPTY);

    expect($this->store->node($id)->isStale())->toBeFalse();
});

it('frees a node\'s token and name when it leaves', function (): void {
    $id = '01J8ZCP4M2V8QNX4RW7TKB3PD9';

    expect($this->store->markLeft($id, $this->now))->toBeTrue()
        ->and($this->store->markLeft($id, $this->now))->toBeFalse()
        ->and($this->store->nodeRowByToken(str_repeat('a', 64)))->toBeNull()
        ->and($this->store->nodeNamed('edge-venue-07'))->toBeNull()
        ->and($this->store->nodes())->toBe([]);
});
