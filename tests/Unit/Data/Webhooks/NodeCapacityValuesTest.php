<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Webhooks\NodeCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeJobsCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeJobsPause;
use AlexHackney\Tmesis\Data\Webhooks\NodeListeners;
use AlexHackney\Tmesis\Data\Webhooks\NodeLiveCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeLiveCapacityPrevious;
use AlexHackney\Tmesis\Data\Webhooks\NodeVideoEngine;

/*
 * The node-scoped value objects tmesis v0.9.0 added or widened (handover 3.2 to 3.5),
 * on hand-built arrays: the edges the generated examples do not show. The examples
 * themselves are read in tests/Unit/Events.
 */

it('reads NodeCapacity admission and treats only on as measured', function (string $admission, bool $measured): void {
    $capacity = NodeCapacity::fromArray(['encode_slots_total' => $measured ? 6 : 0, 'admission' => $admission, 'uplink_mbps' => 1000, 'disk_free_bytes' => 9]);

    expect($capacity->admission())->toBe($admission)
        ->and($capacity->admissionOn())->toBe($measured)
        ->and($capacity->encodeSlotsTotal())->toBe($measured ? 6 : 0)
        ->and($capacity->uplinkMbps())->toBe(1000)
        ->and($capacity->diskFreeBytes())->toBe(9);
})->with([
    'on' => [NodeCapacity::ADMISSION_ON, true],
    'off' => [NodeCapacity::ADMISSION_OFF, false],
    'not_applicable' => [NodeCapacity::ADMISSION_NOT_APPLICABLE, false],
]);

it('reads an empty NodeCapacity as unmeasured zeros', function (): void {
    $capacity = NodeCapacity::fromArray([]);

    expect($capacity->admission())->toBe('')
        ->and($capacity->admissionOn())->toBeFalse()
        ->and($capacity->encodeSlotsTotal())->toBe(0)
        ->and($capacity->toArray())->toBe([]);
});

it('tolerates empty listener strings', function (): void {
    $listeners = NodeListeners::fromArray(['rtmp' => '', 'srt' => '', 'api' => '127.0.0.1:7000']);

    expect($listeners->rtmp())->toBe('')
        ->and($listeners->srt())->toBe('')
        ->and($listeners->api())->toBe('127.0.0.1:7000')
        ->and(NodeListeners::fromArray([])->rtmp())->toBe('');
});

it('reads every NodeVideoEngine field and the pin', function (): void {
    $raw = [
        'encoder' => 'libx264',
        'preset' => 'veryfast',
        'accelerator' => 'none',
        'placement' => 'none',
        'fell_back_with_device_available' => true,
        'reason' => 'pinned to libx264 by jobs.video_encoder',
        'requested' => 'libx264',
        'usable' => true,
    ];

    $engine = NodeVideoEngine::fromArray($raw);

    expect($engine->encoder())->toBe('libx264')
        ->and($engine->preset())->toBe('veryfast')
        ->and($engine->accelerator())->toBe(NodeVideoEngine::ACCELERATOR_NONE)
        ->and($engine->placement())->toBe(NodeVideoEngine::PLACEMENT_NONE)
        ->and($engine->fellBackWithDeviceAvailable())->toBeTrue()
        ->and($engine->reason())->toBe('pinned to libx264 by jobs.video_encoder')
        ->and($engine->requested())->toBe(NodeVideoEngine::REQUESTED_LIBX264)
        ->and($engine->isPinned())->toBeTrue()
        ->and($engine->usable())->toBeTrue()
        ->and($engine->toArray())->toBe($raw);
});

it('reads a failed pin as not usable, and an absent usable as usable', function (): void {
    expect(NodeVideoEngine::fromArray(['requested' => 'h264_qsv', 'usable' => false])->usable())->toBeFalse()
        ->and(NodeVideoEngine::fromArray([])->usable())->toBeTrue()
        ->and(NodeVideoEngine::fromArray(['usable' => 'no'])->usable())->toBeTrue()
        ->and(NodeVideoEngine::fromArray([])->requested())->toBe('')
        ->and(NodeVideoEngine::fromArray(['requested' => 'auto'])->isPinned())->toBeFalse();
});

it('reads NodeJobsPause with and without until', function (): void {
    $pause = NodeJobsPause::fromArray(['until' => '2026-08-28T19:00:00Z', 'reason' => 'show', 'source' => 'api']);

    expect($pause->untilRaw())->toBe('2026-08-28T19:00:00Z')
        ->and($pause->until()?->format(DATE_ATOM))->toBe('2026-08-28T19:00:00+00:00')
        ->and($pause->reason())->toBe('show')
        ->and($pause->source())->toBe(NodeJobsPause::SOURCE_API);

    $open = NodeJobsPause::fromArray(['until' => null, 'reason' => 'show', 'source' => 'fleet']);

    expect($open->untilRaw())->toBeNull()
        ->and($open->until())->toBeNull()
        ->and($open->source())->toBe(NodeJobsPause::SOURCE_FLEET)
        ->and(NodeJobsPause::fromArray([])->reason())->toBe('')
        ->and(NodeJobsPause::fromArray(['until' => ''])->until())->toBeNull();
});

it('throws on an until that is not RFC3339', function (): void {
    expect(fn () => NodeJobsPause::fromArray(['until' => 'tomorrow'])->until())->toThrow(InvalidArgumentException::class, 'tomorrow');
});

it('reads NodeJobsCapacity, and zeros for a missing block', function (): void {
    $jobs = NodeJobsCapacity::fromArray(['slots' => 1, 'source' => 'unmeasured']);

    expect($jobs->slots())->toBe(1)
        ->and($jobs->source())->toBe(NodeJobsCapacity::SOURCE_UNMEASURED)
        ->and(NodeJobsCapacity::fromArray([])->slots())->toBe(0)
        ->and(NodeJobsCapacity::fromArray([])->source())->toBe('');
});

it('reads NodeLiveCapacity with admission off as a null limit that still counts', function (): void {
    $live = NodeLiveCapacity::fromArray([
        'admission' => 'off',
        'source' => 'insufficient',
        'unit_ladder' => 'production',
        'encode_slots_total' => null,
        'encode_slots_used' => 3,
        'device_sessions_total' => 5,
        'device_sessions_used' => 4,
        'measured_at' => '2026-08-27T03:40:12Z',
    ]);

    expect($live->admissionOn())->toBeFalse()
        ->and($live->source())->toBe(NodeLiveCapacity::SOURCE_INSUFFICIENT)
        ->and($live->encodeSlotsTotal())->toBeNull()
        ->and($live->encodeSlotsUsed())->toBe(3)
        ->and($live->deviceSessionsTotal())->toBe(5)
        ->and($live->deviceSessionsUsed())->toBe(4)
        ->and($live->measuredAt())->not->toBeNull();
});

it('reads every field of a NodeLiveCapacityPrevious, and null for one that did not change', function (): void {
    $raw = [
        'admission' => 'off',
        'source' => 'unmeasured',
        'unit_ladder' => 'standard',
        'encode_slots_total' => 4,
        'encode_slots_used' => 2,
        'device_sessions_total' => 3,
        'device_sessions_used' => 1,
        'measured_at' => '2026-08-26T03:40:12Z',
    ];

    $previous = NodeLiveCapacityPrevious::fromArray($raw);

    expect($previous->changed())->toBe(array_keys($raw))
        ->and($previous->admission())->toBe('off')
        ->and($previous->source())->toBe('unmeasured')
        ->and($previous->unitLadder())->toBe('standard')
        ->and($previous->encodeSlotsTotal())->toBe(4)
        ->and($previous->encodeSlotsUsed())->toBe(2)
        ->and($previous->deviceSessionsTotal())->toBe(3)
        ->and($previous->deviceSessionsUsed())->toBe(1)
        ->and($previous->measuredAtRaw())->toBe('2026-08-26T03:40:12Z')
        ->and($previous->measuredAt()?->format('Y-m-d'))->toBe('2026-08-26')
        ->and($previous->toArray())->toBe($raw);

    $none = NodeLiveCapacityPrevious::fromArray([]);

    expect($none->changed())->toBe([])
        ->and($none->encodeSlotsUsed())->toBeNull()
        ->and($none->unitLadder())->toBeNull()
        ->and($none->measuredAt())->toBeNull();
});
