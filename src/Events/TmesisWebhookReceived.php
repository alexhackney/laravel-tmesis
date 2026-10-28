<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Support\Rfc3339;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use DateTimeImmutable;
use RuntimeException;

/**
 * The base webhook event, and the one dispatched for an event name this build does not
 * know.
 *
 * An unknown event name NEVER throws. The catalog is frozen at v1 but additive, so a
 * name from a newer node is expected traffic; a receiver that threw on one would turn
 * every delivery of it into 24 hours of retries.
 *
 * The envelope accessors below are `docs/WEBHOOKS.md` 8.1. `required` in a schema
 * means the key is PRESENT, not that it is non-null, so a nullable field is nullable
 * here too.
 *
 * To see EVERY delivery, listen on WebhookEvent, which this class implements. A
 * listener on this class fires only for unknown event names: the receiver dispatches
 * the typed subclass, and Laravel matches listeners by exact class plus interfaces,
 * never by parent class.
 *
 * @phpstan-consistent-constructor every subclass keeps this constructor, which is
 * what lets EventMap build any of them through the same call.
 */
class TmesisWebhookReceived implements WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload  the decoded body, exactly as it arrived
     */
    public function __construct(
        protected readonly array $payload,
        protected readonly ?ReceivedDelivery $delivery = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload, ?ReceivedDelivery $delivery = null): static
    {
        return new static($payload, $delivery);
    }

    /**
     * The payload exactly as given, unknown keys included.
     *
     * This is what makes the round trip byte neutral: nothing is dropped on the way
     * in, so a field from a newer node survives being read by an older client.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * The event name. Matches the X-Tmesis-Event header.
     */
    public function event(): string
    {
        return $this->string('event');
    }

    /**
     * The delivery ULID from the body. Duplicated in the body so a receiver that logs
     * bodies has the dedupe key without the headers.
     */
    public function deliveryId(): string
    {
        return $this->string('delivery');
    }

    /**
     * The raw `occurred_at` string, untouched.
     */
    public function occurredAtRaw(): string
    {
        return $this->required('occurred_at');
    }

    /**
     * When this happened ON THE NODE.
     *
     * ORDER BY THIS, never by arrival: delivery is unordered, so a `stream.sealed`
     * can reach a receiver before the `stream.live` that preceded it.
     */
    public function occurredAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->occurredAtRaw());
    }

    /**
     * The node that emitted it.
     */
    public function node(): string
    {
        return $this->string('node');
    }

    /**
     * `edge`, `origin`, `transcoder`, or `standalone`.
     *
     * A string rather than an enum: the value set is open in practice, and a backed
     * enum's from() would throw on the day a new role ships. Branch against the
     * constants below.
     */
    public function nodeRole(): string
    {
        return $this->string('node_role');
    }

    public const ROLE_EDGE = 'edge';

    public const ROLE_ORIGIN = 'origin';

    public const ROLE_TRANSCODER = 'transcoder';

    public const ROLE_STANDALONE = 'standalone';

    public function contractVersion(): int
    {
        return $this->int('contract_version');
    }

    /**
     * The stream this is about, or null on `node.*` events and wherever the node
     * could not parse one.
     */
    public function stream(): ?string
    {
        $stream = $this->payload['stream'] ?? null;

        return is_string($stream) ? $stream : null;
    }

    /**
     * The labels set on the stream, echoed back. The node never interprets them.
     *
     * Empty when the payload carries none: `node.*` events have no labels, and
     * neither does `stream.publish_rejected` (there may be no stream to read them
     * from).
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = $this->payload['labels'] ?? null;

        if (! is_array($labels)) {
            return [];
        }

        $out = [];

        foreach ($labels as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The verified delivery this arrived on, when it arrived over HTTP.
     *
     * Null for an event built by hand, which is what a consumer's own tests do.
     */
    public function delivery(): ?ReceivedDelivery
    {
        return $this->delivery;
    }

    /**
     * Whether this build has a typed class for the event name.
     */
    public function isKnownEvent(): bool
    {
        return static::class !== self::class;
    }

    protected function string(string $key): string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    protected function nullableString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    protected function int(string $key): int
    {
        $value = $this->payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    protected function float(string $key): float
    {
        $value = $this->payload[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function bool(string $key): bool
    {
        return (bool) ($this->payload[$key] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function object(string $key): array
    {
        $value = $this->payload[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * A list of strings; anything else in it is dropped.
     *
     * @return list<string>
     */
    protected function stringList(string $key): array
    {
        $value = $this->payload[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    protected function timestamp(string $key): DateTimeImmutable
    {
        return Rfc3339::parse($this->required($key));
    }

    protected function nullableTimestamp(string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($key);

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * A key the schema requires. Missing means this is not the payload it claims to
     * be, so it throws naming the key rather than returning a plausible empty value.
     */
    protected function required(string $key): string
    {
        $value = $this->payload[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException(
                'The Tmesis payload for ['.$this->string('event')."] has no [{$key}]."
            );
        }

        return $value;
    }
}
