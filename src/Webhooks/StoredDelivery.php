<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use DateTimeImmutable;
use JsonException;

/**
 * A claimed delivery read back from the store, for a local retry.
 *
 * Holds what the receiver stored at claim time: the dedupe key, the node the delivery
 * arrived from, the contract headers and the raw signed body. That is everything
 * WebhookController used to build the event, so the retry builds the same one.
 *
 * A store that keeps no headers (the `table` driver) passes an empty list and a
 * `$timestamp`: the rebuilt delivery then has a null attempt, an empty signature and
 * that timestamp. Nothing in this package reads those three on the way to a
 * listener; they reach it through `delivery()` only.
 */
final readonly class StoredDelivery
{
    /**
     * @param  array<string, string>  $headers  the contract headers as received
     */
    public function __construct(
        public string $deliveryId,
        public string $node,
        public string $event,
        public ?int $attempt,
        public array $headers,
        public string $body,
        public DateTimeImmutable $storedAt,
        public int $failedAttempts = 0,
        public ?string $lastError = null,
        public ?DateTimeImmutable $failedAt = null,
        public ?string $timestamp = null,
    ) {}

    /**
     * The delivery as the signature middleware built it on arrival.
     *
     * The event name, delivery id, timestamp, attempt and signature come from the
     * stored headers, exactly as ReceivedDelivery::fromRequest() read them, with the
     * store's own values where a header is absent; the receive time is the claim's.
     */
    public function toReceivedDelivery(): ReceivedDelivery
    {
        $attempt = $this->headers['X-Tmesis-Attempt'] ?? null;

        return new ReceivedDelivery(
            node: $this->node,
            event: $this->headers['X-Tmesis-Event'] ?? $this->event,
            deliveryId: $this->headers['X-Tmesis-Delivery'] ?? $this->deliveryId,
            timestamp: $this->headers['X-Tmesis-Timestamp'] ?? $this->timestamp ?? '',
            attempt: is_string($attempt) && ctype_digit($attempt) ? (int) $attempt : $this->attempt,
            signature: $this->headers['X-Tmesis-Signature'] ?? '',
            rawBody: $this->body,
            headers: $this->headers,
            receivedAt: $this->storedAt,
            channel: ($this->headers[ReceivedDelivery::CHANNEL_HEADER] ?? '') === ReceivedDelivery::CHANNEL_FLEET
                ? ReceivedDelivery::CHANNEL_FLEET
                : ReceivedDelivery::CHANNEL_WEBHOOK,
        );
    }

    /**
     * The typed event, built through EventMap the way the controller builds it.
     *
     * @throws JsonException when the stored body is not a JSON object, which the
     *                       receiver never stores
     */
    public function toEvent(): TmesisWebhookReceived
    {
        $payload = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new JsonException('The stored body of delivery ['.$this->deliveryId.'] does not decode to an array.');
        }

        /** @var array<string, mixed> $payload */
        return EventMap::make($payload, $this->toReceivedDelivery());
    }
}
