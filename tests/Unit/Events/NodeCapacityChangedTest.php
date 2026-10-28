<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Webhooks\NodeJobsCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeLiveCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeVideoEngine;
use AlexHackney\Tmesis\Events\NodeCapacityChanged;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * The generated node.capacity_changed example (tmesis v0.9.0), read in place.
 *
 * @return array<string, mixed>
 */
function capacityChangedExample(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(ContractDir::example('node.capacity_changed')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The example with $overlay laid over it, held valid against the schema first so
 * every hand-built shape below is one the node can send.
 *
 * @param  array<string, mixed>  $overlay
 */
function capacityChanged(array $overlay = []): NodeCapacityChanged
{
    $payload = array_replace(capacityChangedExample(), $overlay);

    SchemaValidator::forSchema('node.capacity_changed')->assertValid(
        json_decode((string) json_encode($payload), false),
        context: 'node.capacity_changed with '.json_encode($overlay)
    );

    $event = EventMap::make($payload);
    expect($event)->toBeInstanceOf(NodeCapacityChanged::class);

    /** @var NodeCapacityChanged $event */
    return $event;
}

it('reads the generated admission example, a node-scoped event', function (): void {
    $event = capacityChanged();

    expect($event->isKnownEvent())->toBeTrue()
        ->and($event->stream())->toBeNull()
        ->and($event->labels())->toBe([])
        ->and($event->node())->toBe('origin-atl-01')
        ->and($event->reason())->toBe(NodeCapacityChanged::REASON_ADMISSION)
        ->and($event->full())->toBeTrue()
        ->and($event->toArray())->toBe(capacityChangedExample());

    $live = $event->liveCapacity();

    expect($live)->toBeInstanceOf(NodeLiveCapacity::class)
        ->and($live->admission())->toBe(NodeLiveCapacity::ADMISSION_ON)
        ->and($live->admissionOn())->toBeTrue()
        ->and($live->source())->toBe(NodeLiveCapacity::SOURCE_MEASURED)
        ->and($live->unitLadder())->toBe('production')
        ->and($live->encodeSlotsTotal())->toBe(7)
        ->and($live->encodeSlotsUsed())->toBe(7)
        ->and($live->deviceSessionsTotal())->toBeNull()
        ->and($live->deviceSessionsUsed())->toBe(0)
        ->and($live->measuredAtRaw())->toBe('2026-08-27T03:40:12Z')
        ->and($live->measuredAt()?->format('Y-m-d H:i:s'))->toBe('2026-08-27 03:40:12');

    expect($event->jobsCapacity())->toBeInstanceOf(NodeJobsCapacity::class)
        ->and($event->jobsCapacity()?->slots())->toBe(2)
        ->and($event->jobsCapacity()?->source())->toBe(NodeJobsCapacity::SOURCE_MEASURED);

    foreach ([$event->videoEngine(), $event->jobsVideoEngine()] as $engine) {
        expect($engine)->toBeInstanceOf(NodeVideoEngine::class)
            ->and($engine?->encoder())->toBe('libx264')
            ->and($engine?->requested())->toBe(NodeVideoEngine::REQUESTED_AUTO)
            ->and($engine?->usable())->toBeTrue()
            ->and($engine?->isPinned())->toBeFalse();
    }

    $previous = $event->previous();

    expect($previous?->changed())->toBe(['encode_slots_used'])
        ->and($previous?->encodeSlotsUsed())->toBe(6)
        ->and($previous?->encodeSlotsTotal())->toBeNull()
        ->and($previous?->admission())->toBeNull();
});

it('reads the boot benchmark: full and previous null, jobs off, no live encoder', function (): void {
    // A transcoder: no live ladder (video_engine null), admission not applicable,
    // and here jobs off too, so both job blocks are null.
    $event = capacityChanged([
        'reason' => 'benchmark',
        'full' => null,
        'previous' => null,
        'jobs_capacity' => null,
        'video_engine' => null,
        'jobs_video_engine' => null,
        'live_capacity' => [
            'admission' => 'not_applicable',
            'source' => 'not_applicable',
            'unit_ladder' => '',
            'encode_slots_total' => null,
            'encode_slots_used' => 0,
            'device_sessions_total' => null,
            'device_sessions_used' => 0,
            'measured_at' => null,
        ],
    ]);

    expect($event->reason())->toBe(NodeCapacityChanged::REASON_BENCHMARK)
        ->and($event->full())->toBeNull()
        ->and($event->previous())->toBeNull()
        ->and($event->jobsCapacity())->toBeNull()
        ->and($event->videoEngine())->toBeNull()
        ->and($event->jobsVideoEngine())->toBeNull()
        ->and($event->liveCapacity()->admissionOn())->toBeFalse()
        ->and($event->liveCapacity()->encodeSlotsTotal())->toBeNull()
        ->and($event->liveCapacity()->unitLadder())->toBe('')
        ->and($event->liveCapacity()->measuredAt())->toBeNull();
});

it('reads a failed jobs pin on the encoder reason', function (): void {
    $engine = capacityChangedExample()['jobs_video_engine'];
    $engine = array_replace($engine, ['encoder' => 'h264_nvenc', 'requested' => 'h264_nvenc', 'usable' => false]);

    $event = capacityChanged(['reason' => 'encoder', 'full' => null, 'previous' => null, 'jobs_video_engine' => $engine]);

    expect($event->reason())->toBe(NodeCapacityChanged::REASON_ENCODER)
        ->and($event->videoEngine()?->usable())->toBeTrue()
        ->and($event->jobsVideoEngine()?->usable())->toBeFalse()
        ->and($event->jobsVideoEngine()?->isPinned())->toBeTrue()
        ->and($event->jobsVideoEngine()?->encoder())->toBe('h264_nvenc');
});

it('reads a device clear with admission off and a known ceiling', function (): void {
    $live = array_replace(capacityChangedExample()['live_capacity'], [
        'admission' => 'off',
        'source' => 'stale',
        'encode_slots_total' => null,
        'device_sessions_total' => 3,
        'device_sessions_used' => 2,
    ]);

    $event = capacityChanged([
        'reason' => 'device',
        'full' => false,
        'live_capacity' => $live,
        'previous' => ['device_sessions_used' => 3],
    ]);

    expect($event->reason())->toBe(NodeCapacityChanged::REASON_DEVICE)
        ->and($event->full())->toBeFalse()
        ->and($event->liveCapacity()->admissionOn())->toBeFalse()
        ->and($event->liveCapacity()->source())->toBe(NodeLiveCapacity::SOURCE_STALE)
        ->and($event->liveCapacity()->deviceSessionsTotal())->toBe(3)
        ->and($event->liveCapacity()->deviceSessionsUsed())->toBe(2)
        ->and($event->previous()?->deviceSessionsUsed())->toBe(3)
        ->and($event->previous()?->changed())->toBe(['device_sessions_used']);
});

it('reads an empty previous as no changed field rather than null', function (): void {
    // Built without the schema check: PHP's [] encodes as a JSON array, not {}.
    /** @var NodeCapacityChanged $event */
    $event = EventMap::make(array_replace(capacityChangedExample(), ['previous' => []]));

    expect($event->previous())->not->toBeNull()
        ->and($event->previous()?->changed())->toBe([]);
});

it('refuses a reason outside the closed four, and an M3 reason that never shipped', function (string $reason): void {
    $payload = array_replace(capacityChangedExample(), ['reason' => $reason]);

    expect(SchemaValidator::forSchema('node.capacity_changed')->isValid(json_decode((string) json_encode($payload), false)))
        ->toBeFalse();
})->with(['backend_fallback', 'hardware_change', 'disk']);

it('reads nulls and empties for a body that lost its blocks, and throws on a missing required timestamp', function (): void {
    $event = NodeCapacityChanged::fromArray(['event' => 'node.capacity_changed', 'full' => 'yes']);

    expect($event->reason())->toBe('')
        ->and($event->full())->toBeNull()
        ->and($event->jobsCapacity())->toBeNull()
        ->and($event->videoEngine())->toBeNull()
        ->and($event->previous())->toBeNull()
        ->and($event->liveCapacity()->encodeSlotsTotal())->toBeNull()
        ->and($event->liveCapacity()->encodeSlotsUsed())->toBe(0)
        ->and($event->liveCapacity()->admission())->toBe('');

    expect(fn () => $event->occurredAt())->toThrow(RuntimeException::class, '[occurred_at]');
});
