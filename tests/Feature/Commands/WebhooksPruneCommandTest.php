<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Webhooks\DatabaseDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * tmesis:webhooks:prune: the only production caller of
 * DeliveryDeduper::prune(), so the database driver's processed rows (full bodies)
 * do not stay forever.
 */

function pruneDelivery(string $id): ReceivedDelivery
{
    return new ReceivedDelivery(
        node: 'edge-venue-07',
        event: 'stream.published',
        deliveryId: $id,
        timestamp: (string) now()->getTimestamp(),
        attempt: 1,
        signature: 'sha256=x',
        rawBody: '{"event":"stream.published","delivery":"'.$id.'"}',
        headers: ['X-Tmesis-Event' => 'stream.published'],
        receivedAt: new DateTimeImmutable,
    );
}

/**
 * Claim a delivery, back-date it, and mark it processed when asked.
 */
function storedDelivery(string $id, int $hoursAgo, bool $processed = true): void
{
    $deduper = app(DeliveryDeduper::class);

    $deduper->claim($id, pruneDelivery($id));
    DB::table('tmesis_webhook_deliveries')->where('delivery', $id)->update(['stored_at' => now()->subHours($hoursAgo)]);

    if ($processed) {
        $deduper->markProcessed($id);
    }
}

/**
 * @return list<string>
 */
function keptDeliveries(): array
{
    return DB::table('tmesis_webhook_deliveries')->orderBy('delivery')->pluck('delivery')->all();
}

it('deletes processed rows past retention, keeps those inside it, and never an unprocessed row', function (): void {
    $this->usesDatabaseDeduper();

    storedDelivery('a-processed-old', 49);
    storedDelivery('b-processed-new', 47);
    storedDelivery('c-unprocessed-ancient', 24 * 365, processed: false);

    $this->artisan('tmesis:webhooks:prune')
        ->expectsOutputToContain('Deleted 1 processed delivery row(s). database driver')
        ->assertSuccessful();

    expect(keptDeliveries())->toBe(['b-processed-new', 'c-unprocessed-ancient']);
});

it('keeps processed rows for the fleet\'s 14 days while the fleet is on, whatever retention_hours says', function (): void {
    config()->set('tmesis.webhooks.dedupe.retention_hours', 48);
    $this->withFleet();

    expect(app(DeliveryDeduper::class))->toBeInstanceOf(DatabaseDeliveryDeduper::class)
        ->and(app(DeliveryDeduper::class)->retentionHours())->toBe(14 * 24);

    storedDelivery('a-five-days', 5 * 24);
    storedDelivery('b-thirteen-days', 13 * 24);
    storedDelivery('c-fifteen-days', 15 * 24);

    Artisan::call('tmesis:webhooks:prune');

    expect(keptDeliveries())->toBe(['a-five-days', 'b-thirteen-days']);
});

it('deletes nothing on the cache driver and says why', function (): void {
    expect(config('tmesis.webhooks.dedupe.driver'))->toBe('cache');

    $this->artisan('tmesis:webhooks:prune')
        ->expectsOutputToContain('Deleted 0 processed delivery row(s). cache driver')
        ->expectsOutputToContain('the cache expires them itself')
        ->assertSuccessful();
});

it('never deletes from the app\'s table on the table driver, and says so', function (): void {
    $this->usesTableDeduper();

    app(DeliveryDeduper::class)->claim('T1', pruneDelivery('T1'));
    app(DeliveryDeduper::class)->markProcessed('T1');
    DB::table('webhooks')->update(['created_at' => now()->subYear()]);

    $this->artisan('tmesis:webhooks:prune')
        ->expectsOutputToContain('Deleted 0 processed delivery row(s). table driver')
        ->expectsOutputToContain('its retention is the app\'s')
        ->assertSuccessful();

    expect(DB::table('webhooks')->count())->toBe(1);
});

it('counts and dates what a prune would delete, for the doctor', function (): void {
    $this->usesDatabaseDeduper();

    /** @var DatabaseDeliveryDeduper $deduper */
    $deduper = app(DeliveryDeduper::class);

    expect($deduper->prunableCount())->toBe(0)
        ->and($deduper->oldestPrunable())->toBeNull();

    storedDelivery('a', 100);
    storedDelivery('b', 60);
    storedDelivery('c', 200, processed: false);

    expect($deduper->prunableCount())->toBe(2)
        ->and($deduper->oldestPrunable()?->format('Y-m-d H'))->toBe(now()->subHours(100)->format('Y-m-d H'));
});

it('warns in the doctor about processed rows past retention, pointing at the command', function (): void {
    $this->usesDatabaseDeduper();

    Artisan::call('tmesis:doctor');
    expect(Artisan::output())->toMatch('/pass delivery retention\s+no processed delivery past 48h/');

    storedDelivery('old', 72);

    Artisan::call('tmesis:doctor');
    expect(Artisan::output())->toMatch('/warn delivery retention\s+1 processed delivery row\(s\) past 48h, the oldest stored .* UTC: schedule tmesis:webhooks:prune \(daily\)/');

    Artisan::call('tmesis:webhooks:prune');
    Artisan::call('tmesis:doctor');
    expect(Artisan::output())->toMatch('/pass delivery retention/');
});

it('prints no delivery retention row for a driver that keeps no rows of its own', function (): void {
    Artisan::call('tmesis:doctor');

    expect(Artisan::output())->not->toContain('delivery retention');
});

it('prunes in bounded chunks until a short chunk, and returns the total across them', function (): void {
    $this->usesDatabaseDeduper();

    foreach (range(1, 7) as $i) {
        storedDelivery("old-{$i}", 72);
    }
    storedDelivery('old-unprocessed', 72, processed: false);
    storedDelivery('new-processed', 1);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'delete')) {
            $queries[] = $query->bindings;
        }
    });

    expect((new DatabaseDeliveryDeduper(DB::connection(), 48, 3))->prune())->toBe(7)
        ->and(keptDeliveries())->toBe(['new-processed', 'old-unprocessed'])
        // 3 + 3 + 1: three bounded statements, never one over the whole backlog.
        ->and($queries)->toHaveCount(3);

    expect(DatabaseDeliveryDeduper::PRUNE_CHUNK)->toBe(1000);
});

it('bounds stored_at in UTC, the way it is written, whatever the app timezone', function (): void {
    $this->usesDatabaseDeduper();
    $zone = date_default_timezone_get();
    config()->set('app.timezone', 'America/New_York');
    date_default_timezone_set('America/New_York');

    try {
        $deduper = app(DeliveryDeduper::class);
        $utc = fn (int $hours): string => now('UTC')->subHours($hours)->format('Y-m-d H:i:s');

        foreach (['processed-47h' => [47, true], 'processed-49h' => [49, true], 'unprocessed-4h' => [4, false]] as $id => [$hours, $processed]) {
            $deduper->claim($id, pruneDelivery($id));
            // Lease released, as after a failed dispatch, so unprocessed() may return it.
            DB::table('tmesis_webhook_deliveries')->where('delivery', $id)->update(['stored_at' => $utc($hours), 'leased_until' => null]);

            if ($processed) {
                $deduper->markProcessed($id);
            }
        }

        expect($deduper->prunableCount())->toBe(1)
            ->and($deduper->oldestPrunable()?->getTimezone()->getName())->toBe('UTC')
            ->and($deduper->oldestPrunable()?->format('Y-m-d H:i:s'))->toBe($utc(49))
            ->and($deduper->unprocessedOlderThan(3 * 3600))->toBe(1)
            ->and($deduper->unprocessedOlderThan(5 * 3600))->toBe(0)
            ->and(array_map(fn ($d) => $d->deliveryId, $deduper->unprocessed(3 * 3600, 10)))->toBe(['unprocessed-4h'])
            ->and($deduper->unprocessed(5 * 3600, 10))->toBe([]);

        Artisan::call('tmesis:doctor');
        expect(Artisan::output())->toContain('the oldest stored '.$utc(49).' UTC');

        expect($deduper->prune())->toBe(1)
            ->and(keptDeliveries())->toBe(['processed-47h', 'unprocessed-4h']);
    } finally {
        date_default_timezone_set($zone);
    }
});
