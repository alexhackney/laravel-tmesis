<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\NodeOnline;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Tests\TestCase;
use AlexHackney\Tmesis\Webhooks\DatabaseDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

afterEach(fn () => Carbon::setTestNow());

function deliveryRow(string $delivery): object
{
    return DB::table('tmesis_webhook_deliveries')->where('delivery', $delivery)->sole();
}

beforeEach(function (): void {
    $this->usesDatabaseDeduper();
    Exceptions::fake();

    // Post a delivery under a given id, signed as the test node. With a throwing
    // listener registered first, it lands stored and unprocessed.
    $this->deliver = function (string $delivery, string $event = 'stream.published'): array {
        $payload = $this->exampleAsTestNode($event);
        $payload['delivery'] = $delivery;

        $this->signedPost($event, $payload)->assertNoContent();

        return $payload;
    };
});

it('re-dispatches the same typed event with the same delivery, and marks it processed', function (): void {
    $calls = [];

    Event::listen(StreamPublished::class, function (StreamPublished $event) use (&$calls): void {
        $calls[] = $event;

        if (count($calls) === 1) {
            throw new RuntimeException('first time fails');
        }
    });

    $payload = ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VT1');

    expect(deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VT1')->processed_at)->toBeNull();

    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry')
        ->expectsOutputToContain('1 dispatched, 0 failed, 0 skipped.')
        ->assertSuccessful();

    expect($calls)->toHaveCount(2);

    [$original, $retried] = $calls;
    $a = $original->delivery();
    $b = $retried->delivery();

    expect($retried)->toBeInstanceOf(StreamPublished::class)
        ->and($retried->toArray())->toBe($payload)
        ->and($a)->toBeInstanceOf(ReceivedDelivery::class)
        ->and($b)->toBeInstanceOf(ReceivedDelivery::class)
        ->and($b->node)->toBe(TestCase::NODE)
        ->and($b->node)->toBe($a->node)
        ->and($b->event)->toBe($a->event)
        ->and($b->deliveryId)->toBe($a->deliveryId)
        ->and($b->timestamp)->toBe($a->timestamp)
        ->and($b->attempt)->toBe($a->attempt)
        ->and($b->signature)->toBe($a->signature)
        ->and($b->rawBody)->toBe($a->rawBody)
        ->and($b->headers)->toBe($a->headers)
        ->and($b->receivedAt->getTimestamp())->toBe($a->receivedAt->getTimestamp());

    $row = deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VT1');

    expect($row->processed_at)->not->toBeNull()
        ->and($row->leased_until)->toBeNull()
        ->and((int) $row->failed_attempts)->toBe(1);
});

it('dispatches through the event dispatcher, once, and not again on a second run', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    $payload = ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VT2');

    Event::fake();
    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry')->assertSuccessful();
    $this->artisan('tmesis:webhooks:retry')
        ->expectsOutputToContain('0 dispatched, 0 failed, 0 skipped.')
        ->assertSuccessful();

    Event::assertDispatchedTimes(StreamPublished::class, 1);
    Event::assertDispatched(
        StreamPublished::class,
        fn (StreamPublished $event): bool => $event->toArray() === $payload
            && $event->delivery()?->node === TestCase::NODE,
    );
});

it('records the failure again when the retry throws too, and fails', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new LogicException('still down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VT3');

    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry')
        ->expectsOutputToContain('0 dispatched, 1 failed, 0 skipped.')
        ->assertFailed();

    $row = deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VT3');

    expect($row->processed_at)->toBeNull()
        ->and((int) $row->failed_attempts)->toBe(2)
        ->and($row->last_error)->toBe('LogicException: still down')
        ->and($row->leased_until)->toBeNull();
});

it('leaves a delivery younger than --older-than alone', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VT4');

    Event::fake();

    $this->travel(30)->seconds();
    $this->artisan('tmesis:webhooks:retry')->assertSuccessful();
    Event::assertNotDispatched(StreamPublished::class);

    $this->artisan('tmesis:webhooks:retry', ['--older-than' => 10])->assertSuccessful();
    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('dispatches only the named ids', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VA1');
    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VA2');
    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VA3');

    Event::fake();
    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry', ['--id' => ['01J8ZC9GQ2M4YE7XW1RN0P6VA1', '01J8ZC9GQ2M4YE7XW1RN0P6VA3']])
        ->assertSuccessful();

    Event::assertDispatchedTimes(StreamPublished::class, 2);

    expect(deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VA1')->processed_at)->not->toBeNull()
        ->and(deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VA2')->processed_at)->toBeNull()
        ->and(deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VA3')->processed_at)->not->toBeNull();
});

it('dispatches at most --limit, oldest first', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VB1');
    $this->travel(5)->seconds();
    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VB2');

    Event::fake();
    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry', ['--limit' => 1])->assertSuccessful();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    expect(deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VB1')->processed_at)->not->toBeNull()
        ->and(deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VB2')->processed_at)->toBeNull();
});

it('filters on --node', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VC1');

    Event::fake();
    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry', ['--node' => 'some-other-node'])->assertSuccessful();
    Event::assertNotDispatched(StreamPublished::class);

    $this->artisan('tmesis:webhooks:retry', ['--node' => TestCase::NODE])->assertSuccessful();
    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('never dispatches a row twice when a second run starts during the first', function (): void {
    $dispatched = 0;
    $failNext = true;

    Event::listen(StreamPublished::class, function () use (&$dispatched, &$failNext): void {
        $dispatched++;

        if ($failNext) {
            $failNext = false;

            throw new RuntimeException('down');
        }

        // A second run while this dispatch holds the lease: the row is neither
        // listed nor leasable.
        expect(app(DeliveryDeduper::class)->lease('01J8ZC9GQ2M4YE7XW1RN0P6VD1'))->toBeFalse();

        Artisan::call('tmesis:webhooks:retry', ['--older-than' => 0]);

        expect(Artisan::output())->toContain('0 dispatched, 0 failed, 0 skipped.');
    });

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VD1');

    $this->travel(2)->minutes();

    // Artisan::call rather than $this->artisan(): the nested run inside the listener
    // would otherwise replace the pending command's captured exit code.
    expect(Artisan::call('tmesis:webhooks:retry'))->toBe(0)
        ->and(Artisan::output())->toContain('1 dispatched, 0 failed, 0 skipped.')
        ->and($dispatched)->toBe(2)
        ->and(deliveryRow('01J8ZC9GQ2M4YE7XW1RN0P6VD1')->processed_at)->not->toBeNull();
});

it('never lets a retry take a row the original request is still dispatching', function (): void {
    $dispatched = 0;

    Event::listen(NodeOnline::class, function () use (&$dispatched): void {
        $dispatched++;

        Artisan::call('tmesis:webhooks:retry', ['--older-than' => 0]);

        expect(Artisan::output())->toContain('0 dispatched');
    });

    $payload = $this->exampleAsTestNode('node.online');
    $this->signedPost('node.online', $payload)->assertNoContent();

    expect($dispatched)->toBe(1)
        ->and(deliveryRow($payload['delivery'])->processed_at)->not->toBeNull();
});

it('skips a row that another caller leased after it was listed', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VE1');

    $deduper = app(DeliveryDeduper::class);

    $this->travel(2)->minutes();

    $listed = $deduper->unprocessed(0, 10);

    expect($listed)->toHaveCount(1)
        ->and($deduper->lease($listed[0]->deliveryId))->toBeTrue()
        ->and($deduper->lease($listed[0]->deliveryId))->toBeFalse();
});

it('picks up a claim whose request died before dispatching, once its lease lapses', function (): void {
    $deduper = app(DeliveryDeduper::class);
    $payload = $this->exampleAsTestNode('stream.published');
    $body = (string) json_encode($payload);

    $deduper->claim($payload['delivery'], new ReceivedDelivery(
        node: TestCase::NODE,
        event: 'stream.published',
        deliveryId: $payload['delivery'],
        timestamp: (string) now()->getTimestamp(),
        attempt: 1,
        signature: 'sha256=x',
        rawBody: $body,
        headers: ['X-Tmesis-Event' => 'stream.published', 'X-Tmesis-Delivery' => $payload['delivery']],
        receivedAt: new DateTimeImmutable('@'.now()->getTimestamp()),
    ), $payload);

    Event::fake();

    $this->travel(2)->minutes();
    $this->artisan('tmesis:webhooks:retry')->assertSuccessful();
    Event::assertNotDispatched(StreamPublished::class);

    $this->travel(DatabaseDeliveryDeduper::LEASE_SECONDS)->seconds();
    $this->artisan('tmesis:webhooks:retry')->assertSuccessful();
    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('refuses a bad --limit or --older-than', function (): void {
    $this->artisan('tmesis:webhooks:retry', ['--limit' => 0])
        ->expectsOutputToContain('--limit must be a whole number of at least 1.')
        ->assertFailed();

    $this->artisan('tmesis:webhooks:retry', ['--older-than' => '-5'])
        ->expectsOutputToContain('--older-than must be a whole number of at least 0.')
        ->assertFailed();
});

it('keeps a failed row through prune, at any age', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VF1');

    $this->travel(30)->days();

    expect(app(DeliveryDeduper::class)->prune())->toBe(0)
        ->and(DB::table('tmesis_webhook_deliveries')->count())->toBe(1);
});

it('says the cache driver cannot retry, and fails', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'cache');
    $this->app->forgetInstance(DeliveryDeduper::class);

    $this->artisan('tmesis:webhooks:retry')
        ->expectsOutputToContain('Not supported by this dedupe store')
        ->assertFailed();
});
