<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Fleet\FleetConfig;
use AlexHackney\Tmesis\Fleet\FleetStore;
use Illuminate\Console\Command;

/**
 * Delete settled fleet rows older than `tombstone_days`: acked or expired commands, and
 * join tokens that were used or expired. Nodes are never deleted (a left node is
 * history). Schedule it daily:
 *
 *     Schedule::command('tmesis:fleet:prune')->daily();
 */
class FleetPruneCommand extends Command
{
    protected $signature = 'tmesis:fleet:prune';

    protected $description = 'Delete settled fleet commands and spent join tokens past the tombstone window';

    public function handle(FleetStore $store): int
    {
        $deleted = $store->prune(now()->toImmutable()->subDays(FleetConfig::tombstoneDays()));

        $this->line("  Deleted {$deleted} settled fleet row(s).");

        return Command::SUCCESS;
    }
}
