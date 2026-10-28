<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Data\Fleet\HeartbeatRequest;
use AlexHackney\Tmesis\Data\Fleet\JobsPauseState;
use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use Illuminate\Support\Facades\Date;

/*
 * The heartbeat's jobs_pause (tmesis v0.9.0, handover 3.5): REQUIRED and nullable, the
 * source a closed set, every failure naming its path.
 */

/**
 * @return array<string, mixed>
 */
function pauseBeat(array $overrides = []): array
{
    return array_merge([
        'node' => 'site-7',
        'role' => 'edge',
        'version' => '0.9.0',
        'started_at' => '2026-10-13T10:00:00.000Z',
        'observed_at' => '2026-10-13T12:00:00.000Z',
        'desired_revision_applied' => 0,
        'admission' => 'open',
        'revocations_digest' => RevocationsDigest::EMPTY,
        'desired_revision_rejected' => null,
        'stats' => ['uptime_seconds' => 1, 'sessions_active' => 0, 'disk_free_bytes' => 1, 'disk_floor_breached' => false, 'outbox_depth' => 0, 'recordings_unregistered' => 0],
        'streams' => [],
        'events' => [],
        'acked_command_ids' => [],
        'jobs_pause' => null,
    ], $overrides);
}

function parsePauseBeat(array $body): HeartbeatRequest
{
    return HeartbeatRequest::fromRaw((string) json_encode($body, JSON_UNESCAPED_SLASHES));
}

it('decodes a null jobs_pause as none', function (): void {
    expect(parsePauseBeat(pauseBeat())->jobsPause)->toBeNull();
});

it('decodes a pre-0.9.0 heartbeat without jobs_pause as none, so a v0.8.x node keeps beating mid-upgrade', function (): void {
    $request = parsePauseBeat(array_diff_key(pauseBeat(), ['jobs_pause' => 1]));

    expect($request->jobsPause)->toBeNull()
        ->and($request->node)->toBe('site-7');
});

it('decodes an active pause, either source, until a time or until cleared', function (?string $until, string $source): void {
    $pause = parsePauseBeat(pauseBeat(['jobs_pause' => ['until' => $until, 'reason' => 'show: fight night 12', 'source' => $source]]))->jobsPause;

    expect($pause)->toBeInstanceOf(JobsPauseState::class)
        ->and($pause->until)->toBe($until)
        ->and($pause->reason)->toBe('show: fight night 12')
        ->and($pause->source)->toBe($source)
        ->and($pause->isFleet())->toBe($source === 'fleet')
        ->and($pause->toArray())->toBe(['until' => $until, 'reason' => 'show: fight night 12', 'source' => $source]);
})->with([
    'fleet, until a time' => ['2026-08-28T19:00:00Z', 'fleet'],
    'api, until cleared' => [null, 'api'],
]);

it('ignores a field a newer node adds to the pause', function (): void {
    $pause = parsePauseBeat(pauseBeat(['jobs_pause' => ['until' => null, 'reason' => 'r', 'source' => 'api', 'set_at' => 'x']]))->jobsPause;

    expect($pause?->source)->toBe('api');
});

it('refuses a heartbeat with a malformed jobs_pause, naming the path', function (array $body, string $path): void {
    try {
        parsePauseBeat($body);
        $this->fail('The heartbeat was accepted.');
    } catch (FleetPayloadException $e) {
        expect($e->getMessage())->toStartWith($path);
    }
})->with([
    'a string' => [pauseBeat(['jobs_pause' => 'paused']), '$.jobs_pause'],
    'a list' => [pauseBeat(['jobs_pause' => []]), '$.jobs_pause'],
    'no until' => [pauseBeat(['jobs_pause' => ['reason' => 'r', 'source' => 'fleet']]), '$.jobs_pause.until'],
    'a numeric until' => [pauseBeat(['jobs_pause' => ['until' => 1, 'reason' => 'r', 'source' => 'fleet']]), '$.jobs_pause.until'],
    'no reason' => [pauseBeat(['jobs_pause' => ['until' => null, 'source' => 'fleet']]), '$.jobs_pause.reason'],
    'no source' => [pauseBeat(['jobs_pause' => ['until' => null, 'reason' => 'r']]), '$.jobs_pause.source'],
    'a source outside the closed set' => [pauseBeat(['jobs_pause' => ['until' => null, 'reason' => 'r', 'source' => 'operator']]), '$.jobs_pause.source'],
]);

it('holds the source set equal to the schema enum, both ways', function (): void {
    $schema = json_decode((string) file_get_contents(ContractDir::schema('fleet.heartbeat.request')), true, 512, JSON_THROW_ON_ERROR);
    $enum = $schema['$defs']['JobsPauseState']['properties']['source']['enum'];

    expect(array_values(array_diff($enum, JobsPauseState::SOURCES)))->toBe([])
        ->and(array_values(array_diff(JobsPauseState::SOURCES, $enum)))->toBe([]);
});

it('reads a stored copy back, tolerating missing keys', function (): void {
    expect(JobsPauseState::fromStored(['until' => '2026-08-28T19:00:00Z', 'reason' => 'r', 'source' => 'fleet'])->toArray())
        ->toBe(['until' => '2026-08-28T19:00:00Z', 'reason' => 'r', 'source' => 'fleet'])
        ->and(JobsPauseState::fromStored([])->toArray())->toBe(['until' => null, 'reason' => '', 'source' => '']);
});

function pausedNode(?JobsPauseState $pause): FleetNode
{
    return new FleetNode(
        id: '01J8Z9P4R6K2NWX7VM5TQB3HD8', name: 'site-7', role: 'edge', version: '0.9.0',
        listen: [], capabilities: [], labels: [],
        enrolledAt: new DateTimeImmutable('2026-08-28T10:00:00Z'), leftAt: null,
        lastHeartbeatAt: new DateTimeImmutable('2026-08-28T11:59:00Z'), startedAt: null,
        admission: 'open', stats: null, streams: [], appliedRevision: 1, rejected: null,
        revocationsDigest: null, intendedRevision: 1, intendedRevocationsDigest: null,
        staleSince: null, jobsPause: $pause,
    );
}

it('applies the node\'s pause rule on Laravel\'s clock, not just the last beat (FleetNode::jobsPaused)', function (?string $until, bool $paused): void {
    Date::setTestNow('2026-08-28T12:00:00.409Z');

    try {
        $node = pausedNode(new JobsPauseState($until, 'show', JobsPauseState::SOURCE_FLEET));

        expect($node->jobsPaused())->toBe($paused)
            // The reported state is kept as reported either way.
            ->and($node->jobsPause?->until)->toBe($until);
    } finally {
        Date::setTestNow();
    }
})->with([
    'until cleared' => [null, true],
    'until in the future' => ['2026-08-28T19:00:00Z', true],
    'until later in the same second' => ['2026-08-28T12:00:00.9Z', true],
    'until in the past' => ['2026-08-28T11:00:00Z', false],
    'until exactly now' => ['2026-08-28T12:00:00.409Z', false],
    'an unreadable until fails safe' => ['next tuesday', true],
]);

it('reads no pause as not paused, and takes an explicit now', function (): void {
    $node = pausedNode(new JobsPauseState('2026-08-28T19:00:00Z', 'show', JobsPauseState::SOURCE_API));

    expect(pausedNode(null)->jobsPaused())->toBeFalse()
        ->and($node->jobsPaused(new DateTimeImmutable('2026-08-28T18:59:59.999999Z')))->toBeTrue()
        ->and($node->jobsPaused(new DateTimeImmutable('2026-08-28T19:00:00Z')))->toBeFalse();
});
