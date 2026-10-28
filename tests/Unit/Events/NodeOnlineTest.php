<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Webhooks\NodeCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeJobsPause;
use AlexHackney\Tmesis\Data\Webhooks\NodeVideoEngine;
use AlexHackney\Tmesis\Events\NodeOnline;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * The generated node.online example (tmesis v0.9.0) with $overlay laid over it, held
 * valid against the schema first.
 *
 * @param  array<string, mixed>  $overlay
 */
function nodeOnline(array $overlay = []): NodeOnline
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(ContractDir::example('node.online')), true, 512, JSON_THROW_ON_ERROR);
    $payload = array_replace($payload, $overlay);

    SchemaValidator::forSchema('node.online')->assertValid(
        json_decode((string) json_encode($payload), false),
        context: 'node.online with '.json_encode($overlay)
    );

    $event = EventMap::make($payload);
    expect($event)->toBeInstanceOf(NodeOnline::class);

    /** @var NodeOnline $event */
    return $event;
}

/**
 * @param  array<string, mixed>  $overlay
 * @return array<string, mixed>
 */
function nodeOnlineEngine(array $overlay = []): array
{
    return array_replace([
        'encoder' => 'h264_nvenc',
        'preset' => 'p2',
        'accelerator' => 'nvenc',
        'placement' => 'discrete',
        'fell_back_with_device_available' => false,
        'reason' => 'h264_nvenc is compiled in',
        'requested' => 'auto',
        'usable' => true,
    ], $overlay);
}

it('reads the v0.9.0 fields of the generated example', function (): void {
    $event = nodeOnline();

    expect($event->capacity()->admission())->toBe(NodeCapacity::ADMISSION_ON)
        ->and($event->capacity()->admissionOn())->toBeTrue()
        ->and($event->capacity()->encodeSlotsTotal())->toBe(6)
        ->and($event->videoEngine())->toBeNull()
        ->and($event->jobsVideoEngine())->toBeNull();

    $pause = $event->jobsPause();

    expect($pause)->toBeInstanceOf(NodeJobsPause::class)
        ->and($pause?->untilRaw())->toBe('2026-08-27T06:00:00Z')
        ->and($pause?->reason())->toBe('maintenance window')
        ->and($pause?->source())->toBe(NodeJobsPause::SOURCE_FLEET);
});

it('reads encode_slots_total 0 with admission off as unmeasured, never as no capacity', function (string $admission): void {
    $event = nodeOnline(['capacity' => [
        'encode_slots_total' => 0,
        'admission' => $admission,
        'uplink_mbps' => 1000,
        'disk_free_bytes' => 1,
    ]]);

    expect($event->capacity()->encodeSlotsTotal())->toBe(0)
        ->and($event->capacity()->admission())->toBe($admission)
        ->and($event->capacity()->admissionOn())->toBeFalse();
})->with([NodeCapacity::ADMISSION_OFF, NodeCapacity::ADMISSION_NOT_APPLICABLE]);

it('reads a transcoder: no live encoder, a jobs encoder, empty ingest listeners, no pause', function (): void {
    $event = nodeOnline([
        'node_role' => 'transcoder',
        'listeners' => ['rtmp' => '', 'srt' => '', 'api' => '127.0.0.1:7000'],
        'video_engine' => null,
        'jobs_video_engine' => nodeOnlineEngine(['requested' => 'h264_nvenc']),
        'jobs_pause' => null,
    ]);

    expect($event->videoEngine())->toBeNull()
        ->and($event->jobsVideoEngine())->toBeInstanceOf(NodeVideoEngine::class)
        ->and($event->jobsVideoEngine()?->isPinned())->toBeTrue()
        ->and($event->jobsPause())->toBeNull()
        ->and($event->listeners()->rtmp())->toBe('')
        ->and($event->listeners()->srt())->toBe('')
        ->and($event->listeners()->api())->toBe('127.0.0.1:7000');
});

it('reads a failed live pin', function (): void {
    $event = nodeOnline(['video_engine' => nodeOnlineEngine(['requested' => 'h264_nvenc', 'usable' => false])]);

    expect($event->videoEngine()?->usable())->toBeFalse()
        ->and($event->videoEngine()?->encoder())->toBe('h264_nvenc')
        ->and($event->videoEngine()?->accelerator())->toBe(NodeVideoEngine::ACCELERATOR_NVENC)
        ->and($event->videoEngine()?->placement())->toBe(NodeVideoEngine::PLACEMENT_DISCRETE);
});

it('refuses a node.online without the v0.9.0 required fields', function (string $field): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(ContractDir::example('node.online')), true, 512, JSON_THROW_ON_ERROR);
    unset($payload[$field]);

    expect(SchemaValidator::forSchema('node.online')->isValid(json_decode((string) json_encode($payload), false)))->toBeFalse();
})->with(['video_engine', 'jobs_video_engine', 'jobs_pause']);

it('reads a pre-v0.9.0 body: no engines, no pause, empty admission', function (): void {
    $event = NodeOnline::fromArray([
        'event' => 'node.online',
        'capacity' => ['encode_slots_total' => 4, 'uplink_mbps' => 1000, 'disk_free_bytes' => 1],
        'listeners' => ['rtmp' => ':1935', 'srt' => ':6001', 'api' => '127.0.0.1:7000'],
    ]);

    expect($event->videoEngine())->toBeNull()
        ->and($event->jobsVideoEngine())->toBeNull()
        ->and($event->jobsPause())->toBeNull()
        ->and($event->capacity()->admission())->toBe('')
        ->and($event->capacity()->admissionOn())->toBeFalse();
});
