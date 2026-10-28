<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Commands;

use AlexHackney\Tmesis\Webhooks\DeliveryDeduper;
use AlexHackney\Tmesis\Webhooks\DeliveryDispatcher;
use AlexHackney\Tmesis\Webhooks\StoredDelivery;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dispatches stored deliveries whose dispatch failed or never ran.
 *
 * The node has its 204 before any listener runs and never retries an acknowledged
 * delivery, so the stored row is the only copy. This rebuilds the typed event from
 * the stored body and headers through EventMap, exactly as the receiver did, and
 * dispatches it under a lease so no row is dispatched twice at once.
 */
class RetryWebhooksCommand extends Command
{
    protected $signature = 'tmesis:webhooks:retry
        {--id=* : Only these delivery ids (repeatable)}
        {--older-than=60 : Only deliveries stored at least this many seconds ago}
        {--limit=100 : At most this many deliveries}
        {--node= : Only deliveries from this node}';

    protected $description = 'Dispatch stored Tmesis webhook deliveries that failed or never ran';

    public function handle(DeliveryDeduper $deduper, DeliveryDispatcher $dispatcher): int
    {
        if (! $deduper->supportsRetry()) {
            $this->error('Not supported by this dedupe store: '.$deduper->describe().'. It keeps no payloads, '
                .'so a failed dispatch cannot be retried. Use the database or table driver.');

            return Command::FAILURE;
        }

        $olderThan = $this->integerOption('older-than', 0);
        $limit = $this->integerOption('limit', 1);

        if ($olderThan === null || $limit === null) {
            return Command::FAILURE;
        }

        $ids = array_values(array_filter(
            (array) $this->option('id'),
            fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
        $node = $this->option('node');

        $due = $deduper->unprocessed(
            $olderThan,
            $limit,
            $ids === [] ? null : $ids,
            is_string($node) && $node !== '' ? $node : null,
        );

        $dispatched = $failed = $skipped = 0;

        foreach ($due as $stored) {
            if (! $deduper->lease($stored->deliveryId)) {
                // Taken since it was listed: another run, or the original request.
                $skipped++;
                $this->line("  <fg=yellow>skip</> {$stored->deliveryId} {$stored->event} (leased elsewhere or processed)");

                continue;
            }

            if ($this->retry($stored, $dispatcher)) {
                $dispatched++;
                $this->line("  <fg=green>done</> {$stored->deliveryId} {$stored->event}");
            } else {
                $failed++;
                $this->line("  <fg=red>fail</> {$stored->deliveryId} {$stored->event} (failure recorded on the row)");
            }
        }

        $this->line("  {$dispatched} dispatched, {$failed} failed, {$skipped} skipped.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function retry(StoredDelivery $stored, DeliveryDispatcher $dispatcher): bool
    {
        try {
            $event = $stored->toEvent();
        } catch (Throwable $e) {
            $dispatcher->failed($stored->deliveryId, $e, $stored->node, $stored->event);

            return false;
        }

        return $dispatcher->dispatch($stored->deliveryId, $event);
    }

    private function integerOption(string $name, int $min): ?int
    {
        $value = $this->option($name);
        $value = is_int($value) ? (string) $value : $value;

        if (! is_string($value) || preg_match('/^[0-9]+\z/', $value) !== 1 || (int) $value < $min) {
            $this->error("--{$name} must be a whole number of at least {$min}.");

            return null;
        }

        return (int) $value;
    }
}
