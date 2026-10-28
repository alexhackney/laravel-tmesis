<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\NodeOnline;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Tests\TestCase;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

afterEach(fn () => Carbon::setTestNow());

function webhooksRow(string $delivery): object
{
    return DB::table('webhooks')->where('source', 'tmesis')->where('payload->delivery', $delivery)->sole();
}

beforeEach(function (): void {
    $this->usesTableDeduper();
    Exceptions::fake();

    $this->deliver = function (string $delivery, string $event = 'stream.published'): array {
        $payload = $this->exampleAsTestNode($event);
        $payload['delivery'] = $delivery;

        $this->signedPost($event, $payload)->assertNoContent();

        return $payload;
    };
});

it('stores the signed body, answers 204, dispatches, and marks the row processed', function (): void {
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $this->signedPostRaw('stream.published', $body)->assertNoContent();
    $this->signedPostRaw('stream.published', $body)->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    $row = webhooksRow($payload['delivery']);

    expect(DB::table('webhooks')->count())->toBe(1)
        ->and($row->payload)->toBe($body)
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->reprocessed_at)->toBeNull();
});

it('answers 500 when the row cannot be written, so the node retries it as new', function (): void {
    Schema::rename('webhooks', 'webhooks_offline');

    $payload = $this->exampleAsTestNode('stream.published');

    // The claim runs in the controller, before any response exists: the node has no
    // 204 yet, and a 5xx is a delivery it retries.
    $this->signedPost('stream.published', $payload)->assertStatus(500);

    expect(Cache::store('array')->has('tmesis:delivery:'.$payload['delivery']))->toBeFalse();

    Schema::rename('webhooks_offline', 'webhooks');
    Event::fake();

    $this->signedPost('stream.published', $payload)->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('replays a failed row through tmesis:webhooks:retry and sets reprocessed_at', function (): void {
    $calls = [];

    Event::listen(StreamPublished::class, function (StreamPublished $event) use (&$calls): void {
        $calls[] = $event;

        if (count($calls) === 1) {
            throw new RuntimeException('first time fails');
        }
    });

    $payload = ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VT1');

    expect(webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VT1')->processed_at)->toBeNull();

    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry')
        ->expectsOutputToContain('1 dispatched, 0 failed, 0 skipped.')
        ->assertSuccessful();

    expect($calls)->toHaveCount(2);

    [$original, $retried] = $calls;
    $a = $original->delivery();
    $b = $retried->delivery();

    expect($retried->toArray())->toBe($payload)
        ->and($b->node)->toBe(TestCase::NODE)
        ->and($b->event)->toBe($a->event)
        ->and($b->deliveryId)->toBe($a->deliveryId)
        ->and($b->rawBody)->toBe($a->rawBody)
        // No headers are stored in the shared table.
        ->and($b->headers)->toBe([])
        ->and($b->attempt)->toBeNull()
        ->and($b->signature)->toBe('')
        ->and($b->timestamp)->toBe((string) $retried->occurredAt()->getTimestamp());

    $row = webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VT1');

    expect($row->processed_at)->not->toBeNull()
        ->and($row->reprocessed_at)->toBe($row->processed_at)
        ->and((int) $row->reprocessing_queued)->toBe(0);
});

it('sets only processed_at on a retry when reprocessed_at is not configured', function (): void {
    config()->set('tmesis.webhooks.dedupe.table.columns.reprocessed_at', null);
    $this->app->forgetInstance(DeliveryDeduper::class);

    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VT2');

    Event::fake();
    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry')->assertSuccessful();
    $this->artisan('tmesis:webhooks:retry')
        ->expectsOutputToContain('0 dispatched, 0 failed, 0 skipped.')
        ->assertSuccessful();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    $row = webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VT2');

    expect($row->processed_at)->not->toBeNull()
        ->and($row->reprocessed_at)->toBeNull();
});

it('leaves the row unprocessed when the retry throws too, and fails', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new LogicException('still down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VT3');

    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry')
        ->expectsOutputToContain('0 dispatched, 1 failed, 0 skipped.')
        ->assertFailed();

    $row = webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VT3');

    expect($row->processed_at)->toBeNull()
        ->and($row->reprocessed_at)->toBeNull()
        ->and(app(DeliveryDeduper::class)->lease('01J8ZC9GQ2M4YE7XW1RN0P6VT3'))->toBeTrue();
});

it('filters on --id and --node through the payload', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VA1');
    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VA2');

    Event::fake();
    $this->travel(2)->minutes();

    $this->artisan('tmesis:webhooks:retry', ['--node' => 'some-other-node'])->assertSuccessful();
    Event::assertNotDispatched(StreamPublished::class);

    $this->artisan('tmesis:webhooks:retry', ['--id' => ['01J8ZC9GQ2M4YE7XW1RN0P6VA2'], '--node' => TestCase::NODE])
        ->assertSuccessful();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    expect(webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VA1')->processed_at)->toBeNull()
        ->and(webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VA2')->processed_at)->not->toBeNull();
});

it('replays a row whose cache entry has expired', function (): void {
    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('down'));

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VG1');

    Event::fake();
    $this->travel(3)->days();

    expect(Cache::store('array')->has('tmesis:delivery:01J8ZC9GQ2M4YE7XW1RN0P6VG1'))->toBeFalse();

    $this->artisan('tmesis:webhooks:retry')->assertSuccessful();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    expect(webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VG1')->reprocessed_at)->not->toBeNull();
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

        expect(app(DeliveryDeduper::class)->lease('01J8ZC9GQ2M4YE7XW1RN0P6VD1'))->toBeFalse();

        Artisan::call('tmesis:webhooks:retry', ['--older-than' => 0]);

        expect(Artisan::output())->toContain('0 dispatched, 0 failed, 0 skipped.');
    });

    ($this->deliver)('01J8ZC9GQ2M4YE7XW1RN0P6VD1');

    $this->travel(2)->minutes();

    expect(Artisan::call('tmesis:webhooks:retry'))->toBe(0)
        ->and(Artisan::output())->toContain('1 dispatched, 0 failed, 0 skipped.')
        ->and($dispatched)->toBe(2)
        ->and(webhooksRow('01J8ZC9GQ2M4YE7XW1RN0P6VD1')->processed_at)->not->toBeNull();
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

    $row = webhooksRow($payload['delivery']);

    expect($dispatched)->toBe(1)
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->reprocessed_at)->toBeNull();
});

it('passes the doctor on the table driver, warning that the array store is not durable', function (): void {
    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('table driver on [webhooks] as source [tmesis]')
        ->expectsOutputToContain('Point tmesis.webhooks.dedupe.cache_store at a redis, database, memcached, dynamodb store.')
        ->assertSuccessful();

    // The doctor checks the table without writing to it.
    expect(DB::table('webhooks')->count())->toBe(0);
});

it('fails the doctor when the table or a configured column is missing', function (): void {
    config()->set('tmesis.webhooks.dedupe.table.columns.processed_at', 'handled_at');
    $this->app->forgetInstance(DeliveryDeduper::class);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('table [webhooks] has no column(s) [handled_at]')
        ->assertFailed();

    config()->set('tmesis.webhooks.dedupe.table.name', 'no_such_table');
    $this->app->forgetInstance(DeliveryDeduper::class);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('table [no_such_table] does not exist')
        ->assertFailed();
});

it('fails the doctor, rather than crashing, on a blank column in config', function (): void {
    config()->set('tmesis.webhooks.dedupe.table.columns.payload', '');
    $this->app->forgetInstance(DeliveryDeduper::class);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('tmesis.webhooks.dedupe.table.columns.payload')
        ->assertFailed();
});

it('counts this source\'s stale rows in the doctor', function (): void {
    DB::table('webhooks')->insert([
        ['source' => 'tmesis', 'payload' => '{"delivery":"S1"}', 'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)],
        ['source' => 'stripe', 'payload' => '{"delivery":"S2"}', 'created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)],
    ]);

    Artisan::call('tmesis:doctor');

    expect(Artisan::output())->toContain('1 delivery(s) claimed over 24h ago');
});
