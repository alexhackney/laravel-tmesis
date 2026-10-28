<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Webhooks\DatabaseDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use AlexHackney\Tmesis\Webhooks\StoredDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->usesDatabaseDeduper();
});

afterEach(fn () => Carbon::setTestNow());

function dbDelivery(string $id = 'D1', string $body = '{"event":"stream.published","occurred_at":"2026-08-28T19:12:01.482Z"}'): ReceivedDelivery
{
    return new ReceivedDelivery(
        node: 'edge-venue-07',
        event: 'stream.published',
        deliveryId: $id,
        timestamp: (string) now()->getTimestamp(),
        attempt: 2,
        signature: 'sha256=x',
        rawBody: $body,
        headers: ['X-Tmesis-Event' => 'stream.published'],
        receivedAt: new DateTimeImmutable,
    );
}

it('inserts the row verbatim on the first claim', function (): void {
    $body = '{"event":"stream.published","delivery":"D1","occurred_at":"2026-08-28T19:12:01.482Z"}';

    expect(app(DeliveryDeduper::class)->claim('D1', dbDelivery('D1', $body), json_decode($body, true)))->toBeTrue();

    $row = DB::table('tmesis_webhook_deliveries')->first();

    expect($row->delivery)->toBe('D1')
        // Byte identical: what was signed is what is stored.
        ->and($row->body)->toBe($body)
        ->and($row->event)->toBe('stream.published')
        ->and($row->node)->toBe('edge-venue-07')
        ->and($row->attempt)->toBe(2)
        ->and($row->occurred_at)->toBe('2026-08-28 19:12:01')
        ->and($row->processed_at)->toBeNull();
});

it('reports a duplicate and inserts no second row', function (): void {
    $deduper = app(DeliveryDeduper::class);

    expect($deduper->claim('D1', dbDelivery()))->toBeTrue()
        ->and($deduper->claim('D1', dbDelivery()))->toBeFalse()
        ->and(DB::table('tmesis_webhook_deliveries')->count())->toBe(1);
});

it('claims again after forget deletes the row', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', dbDelivery());
    $deduper->forget('D1');

    expect(DB::table('tmesis_webhook_deliveries')->count())->toBe(0)
        ->and($deduper->claim('D1', dbDelivery()))->toBeTrue();
});

it('marks a row processed', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', dbDelivery());
    $deduper->markProcessed('D1');

    expect(DB::table('tmesis_webhook_deliveries')->value('processed_at'))->not->toBeNull();
});

it('prunes only processed rows past the retention', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $rows = [
        ['old-processed', now()->subHours(72), true],
        ['new-processed', now()->subHours(1), true],
        // Unprocessed rows are work that has not happened. Never pruned, at any age.
        ['old-unprocessed', now()->subHours(72), false],
    ];

    foreach ($rows as [$id, $storedAt, $processed]) {
        $deduper->claim($id, dbDelivery($id));
        DB::table('tmesis_webhook_deliveries')->where('delivery', $id)->update(['stored_at' => $storedAt]);

        if ($processed) {
            $deduper->markProcessed($id);
        }
    }

    expect($deduper->prune())->toBe(1)
        ->and(DB::table('tmesis_webhook_deliveries')->pluck('delivery')->sort()->values()->all())
        ->toBe(['new-processed', 'old-unprocessed']);
});

it('counts claimed-but-unprocessed rows older than a window', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('stale', dbDelivery('stale'));
    DB::table('tmesis_webhook_deliveries')->where('delivery', 'stale')->update(['stored_at' => now()->subHours(25)]);

    $deduper->claim('handled', dbDelivery('handled'));
    DB::table('tmesis_webhook_deliveries')->where('delivery', 'handled')->update(['stored_at' => now()->subHours(25)]);
    $deduper->markProcessed('handled');

    $deduper->claim('fresh', dbDelivery('fresh'));

    expect($deduper->unprocessedOlderThan(86400))->toBe(1);
});

it('is durable and refuses a retention under 48 hours', function (): void {
    expect(app(DeliveryDeduper::class))->toBeInstanceOf(DatabaseDeliveryDeduper::class)
        ->and(app(DeliveryDeduper::class)->isDurable())->toBeTrue()
        ->and(app(DeliveryDeduper::class)->describe())->toContain('durable');

    expect(fn () => new DatabaseDeliveryDeduper(DB::connection(), 47))
        ->toThrow(InvalidConfigurationException::class, 'retention_hours');
});

it('holds the lease from the claim until it is settled', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', dbDelivery());

    // The original request holds it.
    expect($deduper->lease('D1'))->toBeFalse();

    $deduper->recordFailure('D1', new RuntimeException('x'));

    expect($deduper->lease('D1'))->toBeTrue()
        ->and($deduper->lease('D1'))->toBeFalse();

    $deduper->markProcessed('D1');

    expect(DB::table('tmesis_webhook_deliveries')->value('leased_until'))->toBeNull()
        ->and($deduper->lease('D1'))->toBeFalse()
        ->and($deduper->lease('unknown'))->toBeFalse();
});

it('takes a lapsed lease', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', dbDelivery());

    $this->travel(DatabaseDeliveryDeduper::LEASE_SECONDS + 1)->seconds();

    expect($deduper->lease('D1'))->toBeTrue();
});

it('records each failure with the class and a truncated message', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', dbDelivery());
    $deduper->recordFailure('D1', new RuntimeException('first'));
    $deduper->recordFailure('D1', new LogicException(str_repeat('m', 5000)));

    $row = DB::table('tmesis_webhook_deliveries')->first();

    expect((int) $row->failed_attempts)->toBe(2)
        ->and($row->last_error)->toStartWith('LogicException: mmm')
        ->and(mb_strlen($row->last_error))->toBeLessThanOrEqual(DatabaseDeliveryDeduper::ERROR_LENGTH + 3)
        ->and($row->failed_at)->not->toBeNull()
        ->and($row->processed_at)->toBeNull();
});

it('lists unprocessed, unleased rows past the age, filtered, oldest first', function (): void {
    $deduper = app(DeliveryDeduper::class);

    foreach (['C' => 60, 'A' => 180, 'B' => 120] as $id => $age) {
        $deduper->claim($id, dbDelivery($id));
        $deduper->recordFailure($id, new RuntimeException('x'));
        DB::table('tmesis_webhook_deliveries')->where('delivery', $id)->update(['stored_at' => now()->subSeconds($age)]);
    }

    $deduper->claim('leased', dbDelivery('leased'));
    $deduper->claim('done', dbDelivery('done'));
    $deduper->markProcessed('done');
    DB::table('tmesis_webhook_deliveries')->where('delivery', 'C')->update(['node' => 'origin-atl-01']);
    DB::table('tmesis_webhook_deliveries')->whereIn('delivery', ['leased', 'done'])->update(['stored_at' => now()->subHour()]);

    $ids = fn (array $rows): array => array_map(fn (StoredDelivery $row): string => $row->deliveryId, $rows);

    expect($ids($deduper->unprocessed(60, 100)))->toBe(['A', 'B', 'C'])
        ->and($ids($deduper->unprocessed(150, 100)))->toBe(['A'])
        ->and($ids($deduper->unprocessed(0, 2)))->toBe(['A', 'B'])
        ->and($ids($deduper->unprocessed(0, 100, ['C', 'done', 'leased'])))->toBe(['C'])
        ->and($ids($deduper->unprocessed(0, 100, null, 'origin-atl-01')))->toBe(['C']);

    $stored = $deduper->unprocessed(0, 1)[0];

    expect($stored->node)->toBe('edge-venue-07')
        ->and($stored->event)->toBe('stream.published')
        ->and($stored->attempt)->toBe(2)
        ->and($stored->headers)->toBe(['X-Tmesis-Event' => 'stream.published'])
        ->and($stored->failedAttempts)->toBe(1)
        ->and($stored->lastError)->toBe('RuntimeException: x')
        ->and($stored->toReceivedDelivery()->deliveryId)->toBe('A')
        ->and($stored->toEvent()->event())->toBe('stream.published');
});

it('supports retry', function (): void {
    expect(app(DeliveryDeduper::class)->supportsRetry())->toBeTrue();
});
