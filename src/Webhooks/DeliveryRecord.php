<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * One row of `tmesis_webhook_deliveries`, built from a verified delivery.
 */
final readonly class DeliveryRecord
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $id,
        public string $delivery,
        public string $event,
        public string $node,
        public ?DateTimeImmutable $occurredAt,
        public int $attempt,
        public array $headers,
        public string $body,
        public DateTimeImmutable $storedAt,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload  the decoded body, when it decoded
     */
    public static function fromDelivery(string $deliveryId, ReceivedDelivery $delivery, ?array $payload = null): self
    {
        $event = $delivery->event;

        if (is_string($payload['event'] ?? null) && $payload['event'] !== '') {
            $event = $payload['event'];
        }

        return new self(
            id: (string) Str::ulid(),
            delivery: $deliveryId,
            event: $event,
            node: $delivery->node,
            occurredAt: self::occurredAt($payload),
            attempt: $delivery->attempt ?? 1,
            headers: $delivery->headers,
            body: $delivery->rawBody,
            storedAt: $delivery->receivedAt,
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function occurredAt(?array $payload): ?DateTimeImmutable
    {
        $occurredAt = $payload['occurred_at'] ?? null;

        if (! is_string($occurredAt) || $occurredAt === '') {
            return null;
        }

        try {
            return Rfc3339::parse($occurredAt);
        } catch (Throwable) {
            // A body that will not parse is still stored. The node signed it, so a
            // 204 with an unusable timestamp beats 24 hours of retries.
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabaseRow(): array
    {
        return [
            'id' => $this->id,
            'delivery' => $this->delivery,
            'event' => $this->event,
            'node' => $this->node,
            'occurred_at' => $this->occurredAt?->format('Y-m-d H:i:s'),
            'attempt' => $this->attempt,
            'headers' => json_encode($this->headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'body' => $this->body,
            'stored_at' => $this->storedAt->format('Y-m-d H:i:s'),
            'processed_at' => null,
        ];
    }
}
