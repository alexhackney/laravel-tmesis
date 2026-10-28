<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * One verified inbound delivery, as it arrived.
 *
 * Built by VerifyTmesisSignature from the raw request. `rawBody` holds the exact
 * bytes the signature covers, and nothing here ever re-serialises them: a body that
 * has been decoded and re-encoded is a different body and would not verify.
 */
final readonly class ReceivedDelivery
{
    /** The node POSTed it to the webhook receiver, signed. */
    public const CHANNEL_WEBHOOK = 'webhook';

    /** It rode a fleet heartbeat (tmesis v0.7.0, D7): the same id and bytes, no HMAC. */
    public const CHANNEL_FLEET = 'fleet';

    /**
     * The pseudo-header a stored fleet delivery carries, so a replay from the store
     * knows its channel. Not a contract header: the node never sends it.
     */
    public const CHANNEL_HEADER = 'X-Tmesis-Channel';

    /**
     * @param  array<string, string>  $headers  the eight contract headers
     * @param  string  $channel  which transport carried it; the same event can arrive
     *                           on both, and the dedupe key makes it dispatch once
     */
    public function __construct(
        public string $node,
        public string $event,
        public string $deliveryId,
        public string $timestamp,
        public ?int $attempt,
        public string $signature,
        public string $rawBody,
        public array $headers,
        public DateTimeImmutable $receivedAt,
        public string $channel = self::CHANNEL_WEBHOOK,
    ) {}

    /**
     * One event from a heartbeat's `events[]`, as the dedupe store and EventMap take
     * a delivery. `rawBody` is the payload string exactly, which is byte for byte the
     * webhook body (tmesis seam S1). There is no timestamp, attempt or signature: the
     * heartbeat's bearer token over TLS authenticated it.
     */
    public static function fromFleet(string $node, string $id, string $event, string $payload, DateTimeImmutable $receivedAt): self
    {
        return new self(
            node: $node,
            event: $event,
            deliveryId: $id,
            timestamp: '',
            attempt: null,
            signature: '',
            rawBody: $payload,
            headers: [
                'X-Tmesis-Event' => $event,
                'X-Tmesis-Delivery' => $id,
                'X-Tmesis-Node' => $node,
                self::CHANNEL_HEADER => self::CHANNEL_FLEET,
            ],
            receivedAt: $receivedAt,
            channel: self::CHANNEL_FLEET,
        );
    }

    /**
     * The eight headers the contract defines, in `docs/WEBHOOKS.md` 3 order.
     *
     * @var list<string>
     */
    public const HEADERS = [
        'Content-Type',
        'User-Agent',
        'X-Tmesis-Event',
        'X-Tmesis-Delivery',
        'X-Tmesis-Timestamp',
        'X-Tmesis-Signature',
        'X-Tmesis-Node',
        'X-Tmesis-Attempt',
    ];

    public static function fromRequest(Request $request, DateTimeImmutable $receivedAt): self
    {
        $headers = [];

        foreach (self::HEADERS as $name) {
            $value = $request->header($name);

            if (is_string($value)) {
                $headers[$name] = $value;
            }
        }

        $attempt = $request->header('X-Tmesis-Attempt');

        return new self(
            node: (string) $request->header('X-Tmesis-Node', ''),
            event: (string) $request->header('X-Tmesis-Event', ''),
            deliveryId: (string) $request->header('X-Tmesis-Delivery', ''),
            timestamp: (string) $request->header('X-Tmesis-Timestamp', ''),
            attempt: is_string($attempt) && ctype_digit($attempt) ? (int) $attempt : null,
            signature: (string) $request->header('X-Tmesis-Signature', ''),
            rawBody: $request->getContent(),
            headers: $headers,
            receivedAt: $receivedAt,
        );
    }
}
