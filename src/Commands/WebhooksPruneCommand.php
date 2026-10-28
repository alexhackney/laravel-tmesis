<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Webhooks\CacheDeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\TableDeliveryDeduper;
use Illuminate\Console\Command;

/**
 * Delete processed webhook deliveries older than the dedupe retention, through the
 * bound DeliveryDeduper. Unprocessed rows are never deleted, whatever their age: they
 * are dispatches `tmesis:webhooks:retry` still owes. With the fleet on the window is
 * at least `fleet.tombstone_days`, so an id another channel may still deliver stays
 * known. Schedule it daily:
 *
 *     Schedule::command('tmesis:webhooks:prune')->daily();
 *
 * Only the `database` driver deletes anything: the `cache` driver's entries expire on
 * their own, and the `table` driver never deletes from the app's table, whose
 * retention is the app's.
 */
class WebhooksPruneCommand extends Command
{
    protected $signature = 'tmesis:webhooks:prune';

    protected $description = 'Delete processed Tmesis webhook deliveries past the dedupe retention';

    public function handle(DeliveryDeduper $deduper): int
    {
        $deleted = $deduper->prune();

        $this->line("  Deleted {$deleted} processed delivery row(s). ".$deduper->describe().'.');

        if ($deduper instanceof CacheDeliveryDeduper) {
            $this->line('  The cache driver keeps ids only, and the cache expires them itself.');
        } elseif ($deduper instanceof TableDeliveryDeduper) {
            $this->line('  The table driver never deletes from the app\'s table: its retention is the app\'s.');
        }

        return Command::SUCCESS;
    }
}
