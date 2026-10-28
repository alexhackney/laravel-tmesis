<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use DateTimeImmutable;

/**
 * Every webhook event, typed or not. Listen on THIS to see every delivery.
 *
 * A listener on TmesisWebhookReceived does not: the receiver dispatches the typed
 * object once, and Laravel resolves listeners by the exact class name plus
 * `class_implements()`, never by parent class. A base-class listener therefore
 * fires only for an event name this build has no typed class for. The base class
 * implements this interface, so all 38 typed events and every unknown name do too.
 *
 * The accessors are the envelope's (`docs/WEBHOOKS.md` 8.1); see
 * TmesisWebhookReceived for what each one means.
 */
interface WebhookEvent
{
    /**
     * The payload exactly as given, unknown keys included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * The event name. Matches the X-Tmesis-Event header.
     */
    public function event(): string;

    /**
     * The delivery ULID from the body, the dedupe key.
     */
    public function deliveryId(): string;

    /**
     * The raw `occurred_at` string, untouched.
     */
    public function occurredAtRaw(): string;

    /**
     * When this happened ON THE NODE. Order by this, never by arrival.
     */
    public function occurredAt(): DateTimeImmutable;

    /**
     * The node that emitted it.
     */
    public function node(): string;

    /**
     * `edge`, `origin`, `transcoder`, or `standalone`, as an open string.
     */
    public function nodeRole(): string;

    public function contractVersion(): int;

    /**
     * The stream this is about, or null on `node.*` events.
     */
    public function stream(): ?string;

    /**
     * The labels set on the stream, echoed back.
     *
     * @return array<string, string>
     */
    public function labels(): array;

    /**
     * The verified delivery this arrived on, or null for an event built by hand.
     */
    public function delivery(): ?ReceivedDelivery;

    /**
     * Whether this build has a typed class for the event name.
     */
    public function isKnownEvent(): bool;
}
