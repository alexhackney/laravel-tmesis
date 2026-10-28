<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\NodeDiskFloorNear;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * The generated node.disk_floor_near example (tmesis v0.9.0) with $overlay laid over
 * it, held valid against the schema first.
 *
 * @param  array<string, mixed>  $overlay
 */
function diskFloorNear(array $overlay = []): NodeDiskFloorNear
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(ContractDir::example('node.disk_floor_near')), true, 512, JSON_THROW_ON_ERROR);
    $payload = array_replace($payload, $overlay);

    SchemaValidator::forSchema('node.disk_floor_near')->assertValid(
        json_decode((string) json_encode($payload), false),
        context: 'node.disk_floor_near with '.json_encode($overlay)
    );

    $event = EventMap::make($payload);
    expect($event)->toBeInstanceOf(NodeDiskFloorNear::class);

    /** @var NodeDiskFloorNear $event */
    return $event;
}

it('reads the generated example, a node-scoped event', function (): void {
    $event = diskFloorNear();

    expect($event->stream())->toBeNull()
        ->and($event->labels())->toBe([])
        ->and($event->dataDir())->toBe('/var/lib/tmesis')
        ->and($event->freeBytes())->toBe(6549825126)
        ->and($event->totalBytes())->toBe(64424509440)
        ->and($event->floorBytes())->toBe(5368709120)
        ->and($event->headroomBytes())->toBe(1181116006)
        ->and($event->marginBytes())->toBe(1288490188)
        ->and($event->isBreached())->toBeFalse()
        ->and($event->headroomBytes())->toBe($event->freeBytes() - $event->floorBytes());
});

it('reads a NEGATIVE headroom once the floor is breached', function (): void {
    $event = diskFloorNear(['free_bytes' => 4294967296, 'headroom_bytes' => -1073741824]);

    expect($event->headroomBytes())->toBe(-1073741824)
        ->and($event->isBreached())->toBeTrue();
});

it('reads an unknown volume as total 0 and margin 0, fired at the floor itself', function (): void {
    $event = diskFloorNear(['total_bytes' => 0, 'margin_bytes' => 0, 'free_bytes' => 5368709120, 'headroom_bytes' => 0]);

    expect($event->totalBytes())->toBe(0)
        ->and($event->marginBytes())->toBe(0)
        ->and($event->headroomBytes())->toBe(0)
        ->and($event->isBreached())->toBeFalse();
});

it('reads zeros for a body with no fields', function (): void {
    $event = NodeDiskFloorNear::fromArray(['event' => 'node.disk_floor_near']);

    expect($event->dataDir())->toBe('')
        ->and($event->freeBytes())->toBe(0)
        ->and($event->headroomBytes())->toBe(0)
        ->and($event->isBreached())->toBeFalse();
});
