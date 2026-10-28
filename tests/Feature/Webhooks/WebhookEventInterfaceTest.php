<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\StreamLive;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Events\WebhookEvent;
use AlexHackney\Tmesis\Tests\TestCase;
use AlexHackney\Tmesis\Webhooks\EventMap;
use Illuminate\Support\Facades\Event;

/*
 * The README says a listener on WebhookEvent sees every delivery and one on
 * TmesisWebhookReceived sees only unknown names. These pin both halves of that
 * sentence through the real receiver and the real dispatcher (no Event::fake(), which
 * would record by class and never exercise how Laravel matches listeners).
 */

/**
 * @return ArrayObject<string, list<string>> the classes each listener saw, in order
 */
function listenOnBoth(): ArrayObject
{
    $seen = new ArrayObject(['interface' => [], 'base' => []]);

    Event::listen(WebhookEvent::class, function (WebhookEvent $event) use ($seen): void {
        $seen['interface'] = [...$seen['interface'], $event::class];
    });
    Event::listen(TmesisWebhookReceived::class, function (TmesisWebhookReceived $event) use ($seen): void {
        $seen['base'] = [...$seen['base'], $event::class];
    });

    return $seen;
}

it('fires an interface listener and not a base-class listener for a typed event', function (): void {
    $seen = listenOnBoth();

    $this->signedPost('stream.live', $this->exampleAsTestNode('stream.live'))->assertNoContent();

    expect($seen['interface'])->toBe([StreamLive::class])
        ->and($seen['base'])->toBe([]);
});

it('fires both listeners for an unknown event name', function (): void {
    $seen = listenOnBoth();

    $this->signedPost('node.never_registered', [
        'event' => 'node.never_registered',
        'delivery' => '01J8ZCF2N8R4VKX7QM3WB5TDH9',
        'occurred_at' => '2026-08-28T20:03:11.550Z',
        'node' => TestCase::NODE,
    ])->assertNoContent();

    expect($seen['interface'])->toBe([TmesisWebhookReceived::class])
        ->and($seen['base'])->toBe([TmesisWebhookReceived::class]);
});

it('is implemented by every mapped event class', function (): void {
    foreach (EventMap::CLASSES as $name => $class) {
        expect(is_subclass_of($class, WebhookEvent::class))->toBeTrue("{$name} does not implement WebhookEvent");
    }

    // 34 at tmesis v0.6.0: the five job.* events and marker.placed joined. 38 at
    // v0.9.0: node.capacity_changed, node.disk_floor_near, jobs.paused, jobs.resumed.
    expect(EventMap::CLASSES)->toHaveCount(38);
});

it('answers the envelope accessors through the interface', function (): void {
    $payload = $this->exampleAsTestNode('stream.live');

    /** @var WebhookEvent $event */
    $event = EventMap::make($payload);

    expect($event->event())->toBe('stream.live')
        ->and($event->deliveryId())->toBe($payload['delivery'])
        ->and($event->occurredAtRaw())->toBe($payload['occurred_at'])
        ->and($event->occurredAt()->format('Y'))->toBe(substr($payload['occurred_at'], 0, 4))
        ->and($event->node())->toBe(TestCase::NODE)
        ->and($event->nodeRole())->toBe($payload['node_role'])
        ->and($event->contractVersion())->toBe($payload['contract_version'])
        ->and($event->stream())->toBe($payload['stream'])
        ->and($event->labels())->toBeArray()
        ->and($event->delivery())->toBeNull()
        ->and($event->isKnownEvent())->toBeTrue()
        ->and($event->toArray())->toBe($payload);
});
