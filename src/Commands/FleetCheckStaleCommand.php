<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Events\Fleet\FleetCommandExpired;
use AlexHackney\Tmesis\Events\Fleet\FleetNodeStale;
use AlexHackney\Tmesis\Fleet\FleetConfig;
use AlexHackney\Tmesis\Fleet\FleetStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

/**
 * Mark nodes silent past `stale_after_seconds` as stale (FleetNodeStale once per
 * streak), and expire commands that no heartbeat delivered within their TTL
 * (FleetCommandExpired). Schedule it every minute:
 *
 *     Schedule::command('tmesis:fleet:check-stale')->everyMinute()->withoutOverlapping();
 *
 * A heartbeat expires its own node's commands too; this is for the nodes that are not
 * heartbeating, which are exactly the ones whose commands would otherwise wait.
 */
class FleetCheckStaleCommand extends Command
{
    protected $signature = 'tmesis:fleet:check-stale';

    protected $description = 'Mark silent fleet nodes stale and expire undelivered commands';

    public function handle(FleetStore $store): int
    {
        $now = now()->toImmutable();

        $stale = $store->markStale($now->subSeconds(FleetConfig::staleAfterSeconds()), $now);

        foreach ($stale as $id) {
            $node = $store->node($id);

            if ($node !== null) {
                Event::dispatch(new FleetNodeStale($node));
            }
        }

        $expired = $store->expireUndelivered($now);

        foreach ($expired as $row) {
            Event::dispatch(new FleetCommandExpired((string) $row->id, (string) $row->node_id, (string) $row->kind, is_string($row->stream) ? $row->stream : null));
        }

        $this->line(sprintf('  %d node(s) newly stale, %d command(s) expired undelivered.', count($stale), count($expired)));

        return Command::SUCCESS;
    }
}
