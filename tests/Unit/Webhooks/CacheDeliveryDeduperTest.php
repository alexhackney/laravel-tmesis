<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Webhooks\CacheDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

function probeDelivery(string $id = 'D1'): ReceivedDelivery
{
    return new ReceivedDelivery(
        node: 'edge-venue-07',
        event: 'stream.published',
        deliveryId: $id,
        timestamp: (string) now()->getTimestamp(),
        attempt: 1,
        signature: 'sha256=x',
        rawBody: '{"event":"stream.published"}',
        headers: ['X-Tmesis-Event' => 'stream.published'],
        receivedAt: new DateTimeImmutable,
    );
}

afterEach(fn () => Carbon::setTestNow());

it('claims once, then reports a duplicate', function (): void {
    $deduper = app(DeliveryDeduper::class);

    expect($deduper->claim('D1', probeDelivery()))->toBeTrue()
        ->and($deduper->claim('D1', probeDelivery()))->toBeFalse();
});

it('claims again after forget', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', probeDelivery());
    $deduper->forget('D1');

    expect($deduper->claim('D1', probeDelivery()))->toBeTrue();
});

it('stores nothing but the id, for the retention window', function (): void {
    Carbon::setTestNow('2026-08-28 19:00:00');

    app(DeliveryDeduper::class)->claim('D1', probeDelivery());

    expect(Cache::store('array')->get('tmesis:delivery:D1'))->toBe(1);

    // Retention is 48h in the test environment.
    Carbon::setTestNow('2026-08-30 18:59:00');
    expect(Cache::store('array')->get('tmesis:delivery:D1'))->toBe(1);

    Carbon::setTestNow('2026-08-30 19:01:00');
    expect(Cache::store('array')->get('tmesis:delivery:D1'))->toBeNull();
});

it('is not durable and cannot answer the processed questions', function (): void {
    $deduper = app(DeliveryDeduper::class);

    expect($deduper)->toBeInstanceOf(CacheDeliveryDeduper::class)
        ->and($deduper->isDurable())->toBeFalse()
        ->and($deduper->unprocessedOlderThan(86400))->toBe(0)
        ->and($deduper->prune())->toBe(0)
        ->and($deduper->describe())->toContain('not durable');

    // markProcessed is a no-op here and must not throw.
    $deduper->markProcessed('D1');
});

it('keeps the claim on a failure and cannot retry', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', probeDelivery());
    $deduper->recordFailure('D1', new RuntimeException('listener exploded'));

    // Still claimed: a late duplicate is not dispatched a second time.
    expect($deduper->claim('D1', probeDelivery()))->toBeFalse()
        ->and($deduper->supportsRetry())->toBeFalse()
        ->and($deduper->lease('D1'))->toBeFalse()
        ->and($deduper->unprocessed(0, 100))->toBe([]);
});
