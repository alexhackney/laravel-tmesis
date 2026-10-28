<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use AlexHackney\Tmesis\Webhooks\StoredDelivery;
use AlexHackney\Tmesis\Webhooks\TableDeliveryDeduper;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->usesTableDeduper();
});

afterEach(fn () => Carbon::setTestNow());

function tableDelivery(string $id = 'D1', ?string $body = null): ReceivedDelivery
{
    return new ReceivedDelivery(
        node: 'edge-venue-07',
        event: 'stream.published',
        deliveryId: $id,
        timestamp: (string) now()->getTimestamp(),
        attempt: 1,
        signature: 'sha256=x',
        rawBody: $body ?? '{"event":"stream.published","delivery":"'.$id.'","node":"edge-venue-07","occurred_at":"2026-08-28T19:12:01.482Z"}',
        headers: ['X-Tmesis-Event' => 'stream.published'],
        receivedAt: new DateTimeImmutable,
    );
}

function tableRow(string $delivery): object
{
    return DB::table('webhooks')->where('source', 'tmesis')->where('payload->delivery', $delivery)->sole();
}

/**
 * @param  array<string, mixed>  $columns
 */
function tableDeduper(array $columns = [], string $name = 'webhooks', ?string $cacheDriver = 'array'): TableDeliveryDeduper
{
    return new TableDeliveryDeduper(
        DB::connection(),
        app('cache'),
        48,
        'array',
        $name,
        'tmesis',
        array_merge((array) config('tmesis.webhooks.dedupe.table.columns'), $columns),
        $cacheDriver,
    );
}

function otherSourceRow(string $delivery, string $source = 'stripe'): int
{
    return DB::table('webhooks')->insertGetId([
        'source' => $source,
        'payload' => '{"delivery":"'.$delivery.'","node":"edge-venue-07"}',
        'processed_at' => null,
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);
}

it('is the table driver, bound from config', function (): void {
    $deduper = app(DeliveryDeduper::class);

    expect($deduper)->toBeInstanceOf(TableDeliveryDeduper::class)
        ->and($deduper->supportsRetry())->toBeTrue()
        ->and($deduper->describe())->toContain('[webhooks]')
        ->and($deduper->describe())->toContain('source [tmesis]')
        ->and($deduper->describe())->toContain('[array] cache store');
});

it('claims once, then reports a duplicate without a second row', function (): void {
    $deduper = app(DeliveryDeduper::class);

    expect($deduper->claim('D1', tableDelivery()))->toBeTrue()
        ->and($deduper->claim('D1', tableDelivery()))->toBeFalse()
        ->and(DB::table('webhooks')->count())->toBe(1);
});

it('stores the raw signed body verbatim as the payload, unprocessed', function (): void {
    $body = '{"event": "stream.published",  "delivery":"D1", "labels": {}}';

    app(DeliveryDeduper::class)->claim('D1', tableDelivery('D1', $body), json_decode($body, true));

    $row = DB::table('webhooks')->sole();

    expect($row->source)->toBe('tmesis')
        ->and($row->payload)->toBe($body)
        ->and($row->processed_at)->toBeNull()
        ->and($row->reprocessed_at)->toBeNull()
        ->and($row->created_at)->not->toBeNull()
        ->and($row->updated_at)->toBe($row->created_at)
        ->and((int) $row->reprocessing_queued)->toBe(0)
        ->and($row->deleted_at)->toBeNull();
});

it('keeps the row id in the cache and marks processed by primary key', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', tableDelivery());
    $id = DB::table('webhooks')->value('id');

    expect(Cache::store('array')->get('tmesis:delivery:D1'))->toBe($id);

    // A payload that no longer names the delivery: only the primary key finds it.
    DB::table('webhooks')->where('id', $id)->update(['payload' => '{}']);
    $this->travel(5)->seconds();

    $deduper->markProcessed('D1');

    $row = DB::table('webhooks')->find($id);

    expect($row->processed_at)->not->toBeNull()
        ->and($row->updated_at)->not->toBe($row->created_at)
        // The original request is not a retry.
        ->and($row->reprocessed_at)->toBeNull();
});

it('falls back to the payload query when the cache has lost the row id', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', tableDelivery());
    $deduper->claim('D2', tableDelivery('D2'));
    Cache::store('array')->forget('tmesis:delivery:D1');

    $deduper->markProcessed('D1');

    expect(tableRow('D1')->processed_at)->not->toBeNull()
        ->and(tableRow('D2')->processed_at)->toBeNull();
});

it('never lists, counts or touches another source\'s rows', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $stripe = otherSourceRow('D1');
    otherSourceRow('X9', 'mailgun');

    $deduper->claim('D1', tableDelivery());
    $deduper->recordFailure('D1', new RuntimeException('x'));
    DB::table('webhooks')->where('source', 'tmesis')->update(['created_at' => now()->subDays(2)]);
    Cache::store('array')->forget('tmesis:delivery:D1');

    $ids = array_map(fn (StoredDelivery $row): string => $row->deliveryId, $deduper->unprocessed(0, 100));

    expect($ids)->toBe(['D1'])
        ->and($deduper->unprocessed(0, 100, ['X9']))->toBe([])
        ->and($deduper->unprocessedOlderThan(86400))->toBe(1);

    // The fallback update is scoped to the source too.
    $deduper->markProcessed('D1');

    expect(DB::table('webhooks')->find($stripe)->processed_at)->toBeNull()
        ->and(DB::table('webhooks')->where('source', '!=', 'tmesis')->whereNotNull('processed_at')->count())->toBe(0)
        ->and(tableRow('D1')->processed_at)->not->toBeNull();
});

it('leaves a failed row unprocessed, touches updated_at, and releases the lease', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', tableDelivery());
    $created = tableRow('D1')->created_at;

    expect($deduper->lease('D1'))->toBeFalse();

    $this->travel(5)->seconds();
    $deduper->recordFailure('D1', new RuntimeException('listener exploded'));

    $row = tableRow('D1');

    expect($row->processed_at)->toBeNull()
        ->and($row->updated_at)->not->toBe($created)
        ->and($deduper->lease('D1'))->toBeTrue()
        ->and($deduper->lease('D1'))->toBeFalse();
});

it('refuses a lease on a processed row and an unknown id, and takes a lapsed one', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', tableDelivery());
    $deduper->markProcessed('D1');

    expect($deduper->lease('D1'))->toBeFalse()
        ->and($deduper->lease('unknown'))->toBeFalse();

    $deduper->claim('D2', tableDelivery('D2'));

    expect($deduper->lease('D2'))->toBeFalse();

    $this->travel(TableDeliveryDeduper::LEASE_SECONDS + 1)->seconds();

    expect($deduper->lease('D2'))->toBeTrue();
});

it('lists unprocessed, unleased rows past the age, filtered, oldest first', function (): void {
    $deduper = app(DeliveryDeduper::class);

    foreach (['C' => 60, 'A' => 180, 'B' => 120] as $id => $age) {
        $deduper->claim($id, tableDelivery($id));
        $deduper->recordFailure($id, new RuntimeException('x'));
        DB::table('webhooks')->where('payload->delivery', $id)->update(['created_at' => now()->subSeconds($age)]);
    }

    $deduper->claim('leased', tableDelivery('leased'));
    $deduper->claim('done', tableDelivery('done'));
    $deduper->markProcessed('done');
    DB::table('webhooks')->whereIn('payload->delivery', ['leased', 'done'])->update(['created_at' => now()->subHour()]);
    DB::table('webhooks')->where('payload->delivery', 'C')->update([
        'payload' => '{"event":"stream.published","delivery":"C","node":"origin-atl-01","occurred_at":"2026-08-28T19:12:01.482Z"}',
    ]);

    $ids = fn (array $rows): array => array_map(fn (StoredDelivery $row): string => $row->deliveryId, $rows);

    expect($ids($deduper->unprocessed(60, 100)))->toBe(['A', 'B', 'C'])
        ->and($ids($deduper->unprocessed(150, 100)))->toBe(['A'])
        ->and($ids($deduper->unprocessed(0, 2)))->toBe(['A', 'B'])
        ->and($ids($deduper->unprocessed(0, 100, ['C', 'done', 'leased'])))->toBe(['C'])
        ->and($ids($deduper->unprocessed(0, 100, null, 'origin-atl-01')))->toBe(['C']);

    $stored = $deduper->unprocessed(0, 1)[0];
    $received = $stored->toReceivedDelivery();

    expect($stored->node)->toBe('edge-venue-07')
        ->and($stored->event)->toBe('stream.published')
        ->and($stored->headers)->toBe([])
        ->and($received->deliveryId)->toBe('A')
        ->and($received->attempt)->toBeNull()
        ->and($received->signature)->toBe('')
        ->and($received->timestamp)->toBe((string) (new DateTimeImmutable('2026-08-28T19:12:01Z'))->getTimestamp())
        ->and($stored->toEvent()->event())->toBe('stream.published');
});

it('takes the replay timestamp from created_at when the payload has no occurred_at', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', tableDelivery('D1', '{"event":"node.online","delivery":"D1","node":"edge-venue-07"}'));
    $deduper->recordFailure('D1', new RuntimeException('x'));

    $stored = $deduper->unprocessed(0, 1)[0];

    expect($stored->toReceivedDelivery()->timestamp)->toBe((string) $stored->storedAt->getTimestamp());
});

it('prunes nothing, at any age', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', tableDelivery());
    $deduper->markProcessed('D1');
    otherSourceRow('X1');

    $this->travel(365)->days();

    expect($deduper->prune())->toBe(0)
        ->and(DB::table('webhooks')->count())->toBe(2);
});

it('forgets the claim and keeps the row', function (): void {
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim('D1', tableDelivery());
    $deduper->forget('D1');

    expect(Cache::store('array')->has('tmesis:delivery:D1'))->toBeFalse()
        ->and(DB::table('webhooks')->count())->toBe(1)
        ->and($deduper->claim('D1', tableDelivery()))->toBeTrue();
});

it('drops the cache key and the lease when the insert fails, and rethrows', function (): void {
    $deduper = tableDeduper(name: 'no_such_table');

    expect(fn () => $deduper->claim('D1', tableDelivery()))->toThrow(QueryException::class)
        ->and(Cache::store('array')->has('tmesis:delivery:D1'))->toBeFalse();

    $lock = Cache::store('array')->getStore()->lock('tmesis:delivery-lease:D1', 10);

    expect($lock->get())->toBeTrue();
});

it('is durable only on a cache driver whose add is atomic across processes and restarts', function (string $driver, bool $durable): void {
    expect(tableDeduper(cacheDriver: $driver)->isDurable())->toBe($durable)
        ->and(tableDeduper(cacheDriver: $driver)->describe())->toContain($durable ? '(durable)' : '(not durable)');
})->with([
    ['redis', true],
    ['database', true],
    ['memcached', true],
    ['dynamodb', true],
    ['array', false],
    ['file', false],
]);

it('refuses a blank required column, source, table or short retention', function (): void {
    expect(fn () => tableDeduper(['payload' => '']))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.webhooks.dedupe.table.columns.payload')
        ->and(fn () => tableDeduper(['id' => null]))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.webhooks.dedupe.table.columns.id')
        ->and(fn () => tableDeduper(name: ''))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.webhooks.dedupe.table.name')
        ->and(fn () => new TableDeliveryDeduper(DB::connection(), app('cache'), 48, 'array', 'webhooks', '', []))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.webhooks.dedupe.table.source')
        ->and(fn () => new TableDeliveryDeduper(DB::connection(), app('cache'), 47, 'array', 'webhooks', 'tmesis', []))
        ->toThrow(InvalidConfigurationException::class, 'retention_hours')
        ->and(fn () => tableDeduper(['reprocessed_at' => ['x']]))
        ->toThrow(InvalidConfigurationException::class, 'columns.reprocessed_at');

    // Blank reprocessed_at is "the table has none", not an error.
    expect(tableDeduper(['reprocessed_at' => null]))->toBeInstanceOf(TableDeliveryDeduper::class)
        ->and(tableDeduper(['reprocessed_at' => '']))->toBeInstanceOf(TableDeliveryDeduper::class);
});

it('refuses a blank column through the container binding', function (): void {
    config()->set('tmesis.webhooks.dedupe.table.columns.source', '');
    $this->app->forgetInstance(DeliveryDeduper::class);

    expect(fn () => app(DeliveryDeduper::class))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.webhooks.dedupe.table.columns.source');
});

it('reports no problems for a matching table, and names a missing table or column', function (): void {
    expect(tableDeduper()->problems())->toBe([])
        ->and(tableDeduper(name: 'no_such_table')->problems())->toBe([
            'table [no_such_table] does not exist on the [testing] connection',
        ])
        ->and(tableDeduper(['processed_at' => 'handled_at', 'reprocessed_at' => 'replayed_at'])->problems())->toBe([
            'table [webhooks] has no column(s) [handled_at, replayed_at], named in tmesis.webhooks.dedupe.table.columns',
        ])
        ->and(tableDeduper(['reprocessed_at' => null])->problems())->toBe([]);
});

/*
 * the optional delivery column, the backstop under the cache.
 */

function withDeliveryColumn(bool $unique = true): void
{
    Schema::table('webhooks', function (Blueprint $table) use ($unique): void {
        $column = $table->string('tmesis_delivery', 64)->nullable();

        if ($unique) {
            $column->unique();
        }
    });
}

it('dispatches a second sight again when the cache key is lost and no delivery column is set', function (): void {
    // The documented gap the column closes: the cache is the only dedupe here.
    expect(tableDeduper()->claim('D1', tableDelivery()))->toBeTrue();

    Cache::store('array')->flush();

    expect(tableDeduper()->claim('D1', tableDelivery()))->toBeTrue()
        ->and(DB::table('webhooks')->where('source', 'tmesis')->count())->toBe(2);
});

it('writes the delivery id into the delivery column on the claim', function (): void {
    withDeliveryColumn();

    tableDeduper(['delivery' => 'tmesis_delivery'])->claim('D1', tableDelivery());

    expect(DB::table('webhooks')->sole()->tmesis_delivery)->toBe('D1');
});

it('reports a duplicate when the cache key is lost and the delivery column has the id', function (): void {
    withDeliveryColumn();
    $columns = ['delivery' => 'tmesis_delivery'];

    expect(tableDeduper($columns)->claim('D1', tableDelivery()))->toBeTrue();
    $id = DB::table('webhooks')->value('id');

    Cache::store('array')->flush();

    // Another process, as a node retry or the fleet's second channel would be.
    expect(tableDeduper($columns)->claim('D1', tableDelivery()))->toBeFalse()
        ->and(DB::table('webhooks')->count())->toBe(1)
        // Re-seeded with the existing row, so the next sight stops at the cache.
        ->and(Cache::store('array')->get('tmesis:delivery:D1'))->toBe($id)
        ->and(tableDeduper($columns)->claim('D1', tableDelivery()))->toBeFalse()
        // The duplicate left the row alone: still unprocessed, still due for retry.
        ->and(tableRow('D1')->processed_at)->toBeNull();
});

it('releases the duplicate claim\'s lease so the row stays leasable for a retry', function (): void {
    withDeliveryColumn();
    $columns = ['delivery' => 'tmesis_delivery'];

    $first = tableDeduper($columns);
    $first->claim('D1', tableDelivery());
    $first->recordFailure('D1', new RuntimeException('listener failed'));

    Cache::store('array')->forget('tmesis:delivery:D1');

    expect(tableDeduper($columns)->claim('D1', tableDelivery()))->toBeFalse()
        ->and(tableDeduper($columns)->lease('D1'))->toBeTrue();
});

it('rethrows a unique violation on another index, and drops the claim', function (): void {
    withDeliveryColumn();
    Schema::table('webhooks', fn (Blueprint $table) => $table->unique('payload'));
    DB::table('webhooks')->insert(['source' => 'stripe', 'payload' => tableDelivery()->rawBody]);

    $deduper = tableDeduper(['delivery' => 'tmesis_delivery']);

    expect(fn () => $deduper->claim('D1', tableDelivery()))->toThrow(UniqueConstraintViolationException::class)
        ->and(Cache::store('array')->has('tmesis:delivery:D1'))->toBeFalse()
        ->and(DB::table('webhooks')->where('source', 'tmesis')->count())->toBe(0);
});

it('rethrows any unique violation when no delivery column is set, as before', function (): void {
    Schema::table('webhooks', fn (Blueprint $table) => $table->unique('payload'));
    DB::table('webhooks')->insert(['source' => 'stripe', 'payload' => tableDelivery()->rawBody]);

    expect(fn () => tableDeduper()->claim('D1', tableDelivery()))->toThrow(UniqueConstraintViolationException::class)
        ->and(Cache::store('array')->has('tmesis:delivery:D1'))->toBeFalse();
});

it('names a missing delivery column, and one without a unique index of its own', function (): void {
    $columns = ['delivery' => 'tmesis_delivery'];

    expect(tableDeduper($columns)->problems())->toBe([
        'table [webhooks] has no column(s) [tmesis_delivery], named in tmesis.webhooks.dedupe.table.columns',
    ]);

    withDeliveryColumn(unique: false);

    expect(tableDeduper($columns)->problems())->toBe([
        'column [tmesis_delivery] on table [webhooks] has no unique index of its own, so tmesis.webhooks.dedupe.table.columns.delivery dedupes nothing past the cache',
    ]);

    Schema::table('webhooks', fn (Blueprint $table) => $table->unique('tmesis_delivery'));

    expect(tableDeduper($columns)->problems())->toBe([]);
});

it('refuses a delivery column that is not a string', function (): void {
    expect(fn () => tableDeduper(['delivery' => 7]))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.webhooks.dedupe.table.columns.delivery');
});

it('makes a forgotten delivery new again with the delivery column, keeping the old row', function (): void {
    withDeliveryColumn();
    $columns = ['delivery' => 'tmesis_delivery'];

    $deduper = tableDeduper($columns);
    $deduper->claim('D1', tableDelivery());
    $deduper->markProcessed('D1');
    $first = DB::table('webhooks')->value('id');

    tableDeduper($columns)->forget('D1');

    expect(DB::table('webhooks')->find($first)->tmesis_delivery)->toBeNull()
        ->and(tableDeduper($columns)->claim('D1', tableDelivery()))->toBeTrue()
        ->and(DB::table('webhooks')->where('source', 'tmesis')->count())->toBe(2)
        ->and(DB::table('webhooks')->where('tmesis_delivery', 'D1')->value('id'))->not->toBe($first);
});

it('clears the delivery column on this source only when it forgets', function (): void {
    withDeliveryColumn();
    DB::table('webhooks')->insert(['source' => 'stripe', 'payload' => '{}', 'tmesis_delivery' => 'D1']);

    tableDeduper(['delivery' => 'tmesis_delivery'])->forget('D1');

    expect(DB::table('webhooks')->where('source', 'stripe')->value('tmesis_delivery'))->toBe('D1');
});

it('leases a forgotten, failed row by its payload once the column no longer names it', function (): void {
    withDeliveryColumn();
    $columns = ['delivery' => 'tmesis_delivery'];

    $deduper = tableDeduper($columns);
    $deduper->claim('D1', tableDelivery());
    $deduper->recordFailure('D1', new RuntimeException('listener failed'));
    $deduper->forget('D1');

    expect(tableDeduper($columns)->lease('D1'))->toBeTrue();
});

it('finds the row by the delivery column when the cache has lost the row id', function (): void {
    withDeliveryColumn();
    $columns = ['delivery' => 'tmesis_delivery'];

    tableDeduper($columns)->claim('D1', tableDelivery());
    tableDeduper($columns)->claim('D2', tableDelivery('D2'));
    Cache::store('array')->forget('tmesis:delivery:D1');

    // A payload that no longer names the delivery: only the column finds it.
    $id = DB::table('webhooks')->where('tmesis_delivery', 'D1')->value('id');
    DB::table('webhooks')->where('id', $id)->update(['payload' => '{}']);

    tableDeduper($columns)->markProcessed('D1');

    expect(DB::table('webhooks')->find($id)->processed_at)->not->toBeNull()
        ->and(tableRow('D2')->processed_at)->toBeNull();
});

it('falls back to the payload query for a row stored before the delivery column existed', function (): void {
    tableDeduper()->claim('D1', tableDelivery());
    withDeliveryColumn();
    Cache::store('array')->forget('tmesis:delivery:D1');

    tableDeduper(['delivery' => 'tmesis_delivery'])->markProcessed('D1');

    expect(tableRow('D1')->processed_at)->not->toBeNull()
        ->and(tableRow('D1')->tmesis_delivery)->toBeNull();
});
