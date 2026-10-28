<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\NodeStats;
use AlexHackney\Tmesis\Data\Node\Stats\CapacitySnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\DiskSnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\VideoEngineSnapshot;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

/*
 * The node stats fields tmesis v0.9.0 added (handover 3.4, 3.6, 3.8): the filled
 * capacity block, the disk volume size and early-warning margin, and the jobs
 * encoder beside the live one with the pin's `requested` and `usable`. The shared
 * fixture is a benched origin with jobs on; these vary it.
 */

/**
 * @return array<string, mixed>
 */
function v090NodeStatsComponent(string $name): array
{
    /** @var array<string, mixed> */
    return ContractDir::readJson(ContractDir::openapi())['components']['schemas'][$name];
}

it('reads a measured capacity block', function (): void {
    $capacity = NodeStats::fromArray($this->fixtureJson('responses/getNodeStats.json'))->capacity();

    expect($capacity->admission())->toBe(CapacitySnapshot::ADMISSION_ON)
        ->and($capacity->admissionOn())->toBeTrue()
        ->and($capacity->source())->toBe(CapacitySnapshot::SOURCE_MEASURED)
        ->and($capacity->measured())->toBeTrue()
        ->and($capacity->unitLadder())->toBe('production')
        ->and($capacity->encodeSlotsTotal())->toBe(7)
        ->and($capacity->encodeSlotsUsed())->toBe(2)
        ->and($capacity->deviceSessionsTotal())->toBe(12)
        ->and($capacity->deviceSessionsUsed())->toBe(2)
        ->and($capacity->maxStreams())->toBe(0)
        ->and($capacity->pendingMilestone())->toBe('')
        ->and($capacity->benchmarkRanAtRaw())->toBe('2026-08-20T14:02:11Z')
        ->and($capacity->benchmarkRanAt()?->format('Y-m-d\TH:i:sP'))->toBe('2026-08-20T14:02:11+00:00');
});

it('reads 0 slots, not null, while admission is not on, and a null device ceiling', function (string $admission, string $source): void {
    $body = $this->fixtureJson('responses/getNodeStats.json');
    $body['capacity'] = [
        ...$body['capacity'],
        'measured' => false,
        'admission' => $admission,
        'source' => $source,
        'unit_ladder' => $admission === CapacitySnapshot::ADMISSION_NOT_APPLICABLE ? '' : 'production',
        'encode_slots_total' => 0,
        'encode_slots_used' => 1,
        'device_sessions_total' => null,
        'benchmark_ran_at' => null,
    ];

    // Still the component: stats says 0 where health's live_capacity says null.
    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/NodeSnapshot');

    $capacity = NodeStats::fromArray($body)->capacity();

    expect($capacity->admissionOn())->toBeFalse()
        ->and($capacity->encodeSlotsTotal())->toBe(0)
        ->and($capacity->encodeSlotsUsed())->toBe(1)
        ->and($capacity->deviceSessionsTotal())->toBeNull()
        ->and($capacity->measured())->toBeFalse()
        ->and($capacity->benchmarkRanAt())->toBeNull();
})->with([
    'unmeasured' => [CapacitySnapshot::ADMISSION_OFF, CapacitySnapshot::SOURCE_UNMEASURED],
    'stale' => [CapacitySnapshot::ADMISSION_OFF, CapacitySnapshot::SOURCE_STALE],
    'insufficient' => [CapacitySnapshot::ADMISSION_OFF, CapacitySnapshot::SOURCE_INSUFFICIENT],
    'not applicable' => [CapacitySnapshot::ADMISSION_NOT_APPLICABLE, CapacitySnapshot::SOURCE_NOT_APPLICABLE],
]);

it('reads a block from a node before v0.9.0 without throwing', function (): void {
    $capacity = CapacitySnapshot::fromArray([
        'measured' => false,
        'pending_milestone' => 'M3',
        'encode_slots_total' => 0,
        'encode_slots_used' => 0,
        'max_streams' => 0,
        'benchmark_ran_at' => null,
    ]);

    expect($capacity->admission())->toBe('')
        ->and($capacity->admissionOn())->toBeFalse()
        ->and($capacity->source())->toBe('')
        ->and($capacity->unitLadder())->toBe('')
        ->and($capacity->deviceSessionsTotal())->toBeNull()
        ->and($capacity->deviceSessionsUsed())->toBe(0)
        ->and($capacity->pendingMilestone())->toBe('M3');
});

it('holds the capacity admission and source sets equal to the component enums', function (): void {
    $properties = v090NodeStatsComponent('CapacitySnapshot')['properties'];

    expect(CapacitySnapshot::ADMISSIONS)->toEqualCanonicalizing($properties['admission']['enum'])
        ->and(CapacitySnapshot::SOURCES)->toEqualCanonicalizing($properties['source']['enum']);
});

it('reads the disk volume size and the early-warning margin', function (): void {
    $disk = NodeStats::fromArray($this->fixtureJson('responses/getNodeStats.json'))->disk();

    expect($disk->totalBytes())->toBe(1919850381312)
        ->and($disk->nearMarginBytes())->toBe(21474836480)
        ->and($disk->floorBytes())->toBe(10737418240);
});

it('reads 0 for an unknown volume and no margin, and a negative headroom once breached', function (): void {
    $disk = DiskSnapshot::fromArray([
        'data_dir' => '/var/lib/tmesis',
        'statfs_ok' => true,
        'free_bytes' => 4294967296,
        'floor_bytes' => 5368709120,
        'headroom_bytes' => -1073741824,
        'total_bytes' => 0,
        'near_margin_bytes' => 0,
        'floor_breached' => true,
    ]);

    expect($disk->totalBytes())->toBe(0)
        ->and($disk->nearMarginBytes())->toBe(0)
        ->and($disk->headroomBytes())->toBe(-1073741824)
        ->and($disk->floorBreached())->toBeTrue();

    // A node before v0.9.0 sends neither.
    expect(DiskSnapshot::fromArray([])->totalBytes())->toBe(0)
        ->and(DiskSnapshot::fromArray([])->nearMarginBytes())->toBe(0);
});

it('reads the live and the jobs encoder with their pins', function (): void {
    $stats = NodeStats::fromArray($this->fixtureJson('responses/getNodeStats.json'));

    expect($stats->videoEngine()?->requested())->toBe(VideoEngineSnapshot::REQUESTED_AUTO)
        ->and($stats->videoEngine()?->usable())->toBeTrue()
        ->and($stats->videoEngine()?->encoder())->toBe('h264_nvenc')
        ->and($stats->jobsVideoEngine())->toBeInstanceOf(VideoEngineSnapshot::class)
        ->and($stats->jobsVideoEngine()?->requested())->toBe(VideoEngineSnapshot::REQUESTED_LIBX264)
        ->and($stats->jobsVideoEngine()?->encoder())->toBe('libx264')
        ->and($stats->jobsVideoEngine()?->usable())->toBeTrue();
});

it('names the PIN, not a fallback, when a pin failed', function (): void {
    $body = $this->fixtureJson('responses/getNodeStats.json');
    $body['video_engine'] = [
        ...$body['video_engine'],
        'requested' => 'h264_qsv',
        'encoder' => 'h264_qsv',
        'accelerator' => 'qsv',
        'usable' => false,
        'reason' => 'pinned to h264_qsv by encode.video_encoder, but no /dev/dri render node is present',
    ];

    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/NodeSnapshot');

    $engine = NodeStats::fromArray($body)->videoEngine();

    expect($engine?->usable())->toBeFalse()
        ->and($engine?->requested())->toBe(VideoEngineSnapshot::REQUESTED_H264_QSV)
        ->and($engine?->encoder())->toBe($engine?->requested());
});

it('answers null for jobs off and for a box that encodes nothing live', function (): void {
    $body = $this->fixtureJson('responses/getNodeStats.json');
    $body['video_engine'] = null;
    $body['jobs_video_engine'] = null;

    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/NodeSnapshot');

    $stats = NodeStats::fromArray($body);

    expect($stats->videoEngine())->toBeNull()
        ->and($stats->jobsVideoEngine())->toBeNull();

    unset($body['jobs_video_engine']);

    expect(NodeStats::fromArray($body)->jobsVideoEngine())->toBeNull();
});

it('reads a block from a node before v0.9.0 as usable with no request', function (): void {
    $engine = VideoEngineSnapshot::fromArray(['encoder' => 'libx264']);

    expect($engine->usable())->toBeTrue()
        ->and($engine->requested())->toBe('');
});

it('holds the requested set equal to the enum the health block closes it to', function (): void {
    // The stats component types `requested` as a bare string; health and node.online
    // carry the enum, and the three are the same config value.
    expect(v090NodeStatsComponent('VideoEngineSnapshot')['properties']['requested'])->not->toHaveKey('enum')
        ->and(VideoEngineSnapshot::REQUESTED)->toEqualCanonicalizing(v090NodeStatsComponent('HealthVideoEngine')['properties']['requested']['enum']);
});
