<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use AlexHackney\Tmesis\Events\WebhookEvent;
use AlexHackney\Tmesis\Support\ContractSchemas;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One log line per delivery: the event, its id, when it happened at the node, and
 * whether it validated.
 *
 * Registered on WebhookEvent, the interface every typed event and every unknown name
 * implements: a listener on TmesisWebhookReceived itself hears unknown names only
 * (Laravel matches the exact class and its interfaces, never parents).
 *
 * Ordered BY `occurred_at` in anything that reads this log, never by arrival:
 * delivery is unordered, so two lines in the log are not two events in that order.
 */
class LogDelivery
{
    public function handle(WebhookEvent $event): void
    {
        Log::info('delivery', [
            'event' => $event->event(),
            'delivery' => $event->deliveryId(),
            'occurred_at' => $event->occurredAtRaw(),
            'validated' => $this->validated($event),
        ]);
    }

    /**
     * `yes`, `no: <first error>`, or `skipped` for an event this build has no schema
     * for, which is expected traffic from a newer node.
     */
    private function validated(WebhookEvent $event): string
    {
        if (! is_file(ContractSchemas::path($event->event()))) {
            return 'skipped (no bundled schema for this event)';
        }

        try {
            $error = ContractSchemas::validate($event->event(), $event->toArray());
        } catch (Throwable $e) {
            return 'skipped ('.$e->getMessage().')';
        }

        return $error === null ? 'yes' : 'no: '.$error;
    }
}
