<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\JobsPaused;
use AlexHackney\Tmesis\Events\JobsResumed;
use AlexHackney\Tmesis\Events\NodeCapacityChanged;
use AlexHackney\Tmesis\Events\NodeDiskFloorNear;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Webhooks\EventMap;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;

pest()->group('schemas-events');

it('maps exactly the event schemas of the pin, in both directions', function (): void {
    $schemas = ContractDir::eventSchemaFiles();

    // 34 at v0.6.0: the five job.* events and marker.placed joined. 38 at v0.9.0:
    // node.capacity_changed, node.disk_floor_near, jobs.paused and jobs.resumed.
    expect(EventMap::events())->toBe($schemas)->toHaveCount(38);
});

it('types the four node-scoped events tmesis v0.9.0 added', function (string $event, string $class): void {
    // Before v0.9.0 every one of these fell back to the base class (handover 1).
    expect(EventMap::classFor($event))->toBe($class)
        ->and($class::EVENT)->toBe($event)
        ->and(get_parent_class($class))->toBe(TmesisWebhookReceived::class);
})->with([
    ['node.capacity_changed', NodeCapacityChanged::class],
    ['node.disk_floor_near', NodeDiskFloorNear::class],
    ['jobs.paused', JobsPaused::class],
    ['jobs.resumed', JobsResumed::class],
]);

it('gives every class an EVENT const equal to its key', function (): void {
    foreach (EventMap::CLASSES as $event => $class) {
        expect($class::EVENT)->toBe($event);
    }
});

it('falls back to the base class for a name this build has no schema for', function (): void {
    // node.never_registered is a name no milestone will ever claim, so this never has
    // to move again (v0.6.0). node.capacity_changed played the part until
    // then, and stream.input_lost until rc5 pulled it forward with the hold.
    expect(EventMap::classFor('node.never_registered'))->toBe(TmesisWebhookReceived::class);

    $event = EventMap::make(['event' => 'node.never_registered', 'stream' => 'show-2041-main']);

    expect($event)->toBeInstanceOf(TmesisWebhookReceived::class)
        ->and($event->isKnownEvent())->toBeFalse()
        ->and($event->event())->toBe('node.never_registered')
        ->and($event->stream())->toBe('show-2041-main');
});

it('reads the event name from the header when the body carries none', function (): void {
    $delivery = new ReceivedDelivery(
        node: 'edge-venue-07',
        event: 'stream.published',
        deliveryId: '01J8ZC9GQ2M4YE7XW1RN0P6VTB',
        timestamp: '1787944321',
        attempt: 1,
        signature: 'sha256=x',
        rawBody: '{}',
        headers: [],
        receivedAt: new DateTimeImmutable,
    );

    expect(EventMap::make([], $delivery))->toBeInstanceOf(StreamPublished::class);
});
