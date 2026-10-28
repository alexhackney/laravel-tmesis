<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\Stats\IntegrationSnapshot;
use AlexHackney\Tmesis\Data\Relays\RelayRecord;

/*
 * The admin API fields tmesis v0.7.0 added for fleet mode: admission on the health
 * body and the stats integration block, the applied revision and the last answered
 * heartbeat, and the `fleet` relay source. The shared fixtures carry a standalone
 * node (open, nulls); these carry a fleet node mid-drain.
 */

it('reads admission from the full health body', function (string $admission): void {
    $health = HealthFull::fromArray([...$this->fixtureJson('responses/getHealth.json'), 'admission' => $admission, 'draining' => $admission !== 'open']);

    expect($health->admission())->toBe($admission)
        ->and($health->draining())->toBe($admission !== HealthFull::ADMISSION_OPEN);
})->with([HealthFull::ADMISSION_OPEN, HealthFull::ADMISSION_DRAINING, HealthFull::ADMISSION_SHUTTING_DOWN]);

it('reads a fleet node\'s revision, admission and last heartbeat from the stats integration block', function (): void {
    $integration = IntegrationSnapshot::fromArray([
        'mode' => 'fleet',
        'webhook_outbox_depth' => 0,
        'control_plane_reachable' => true,
        'fleet_revision' => 43,
        'admission' => 'draining',
        'last_heartbeat_at' => '2026-09-28T01:02:03.456789Z',
        'leases' => [],
    ]);

    expect($integration->fleetRevision())->toBe(43)
        ->and($integration->admission())->toBe(HealthFull::ADMISSION_DRAINING)
        ->and($integration->lastHeartbeatAtRaw())->toBe('2026-09-28T01:02:03.456789Z')
        ->and($integration->lastHeartbeatAt()?->format('Y-m-d\TH:i:s.uP'))->toBe('2026-09-28T01:02:03.456789+00:00');
});

it('reads null revision and heartbeat outside fleet mode, never 0 or an epoch', function (): void {
    $integration = IntegrationSnapshot::fromArray($this->fixtureJson('responses/getNodeStats.json')['integration']);

    expect($integration->fleetRevision())->toBeNull()
        ->and($integration->lastHeartbeatAtRaw())->toBeNull()
        ->and($integration->lastHeartbeatAt())->toBeNull()
        ->and($integration->admission())->toBe(HealthFull::ADMISSION_OPEN);
});

it('names the fleet relay source', function (): void {
    $relay = RelayRecord::fromArray([...$this->fixtureJson('responses/getRelay.json'), 'source' => 'fleet']);

    expect($relay->source())->toBe(RelayRecord::SOURCE_FLEET);
});
