<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches a claimed delivery and settles its row, for the receiver and the retry.
 *
 * A dispatch that throws never rethrows and never drops the claim. The node already
 * has its 204 and does not retry, so the stored row is the only copy: it stays
 * unprocessed with the failure recorded, for `tmesis:webhooks:retry`.
 */
class DeliveryDispatcher
{
    public function __construct(private readonly DeliveryDeduper $deduper) {}

    /**
     * @return bool whether every listener ran and the row is marked processed
     */
    public function dispatch(string $deliveryId, TmesisWebhookReceived $event): bool
    {
        try {
            Event::dispatch($event);
        } catch (Throwable $e) {
            $this->failed($deliveryId, $e, $event->node(), $event->event());

            return false;
        }

        try {
            $this->deduper->markProcessed($deliveryId);
        } catch (Throwable $e) {
            // The listeners ran. Not a dispatch failure, so nothing is recorded as
            // one; the row stays unprocessed and the doctor will count it.
            report($e);

            return false;
        }

        return true;
    }

    /**
     * Record a failure that happened before there was an event to dispatch.
     */
    public function failed(string $deliveryId, Throwable $error, string $node, string $event): void
    {
        report($error);

        Log::error('Tmesis webhook dispatch failed.', [
            'node' => $node,
            'delivery' => $deliveryId,
            'event' => $event,
            'retry' => $this->deduper->supportsRetry() ? 'tmesis:webhooks:retry' : 'unsupported by this dedupe store',
        ]);

        $this->deduper->recordFailure($deliveryId, $error);
    }
}
