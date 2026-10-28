<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

/**
 * Which secret verifies a given inbound request.
 *
 * The signing function is the same for all three. Only the secret differs: a node's
 * `ingest.verify_hook.secret` and `transcription.keyterms_hook.secret` may each be set
 * separately from its `webhooks.secret`. The keyterms hook is a GET, so what is signed
 * is the timestamp and an EMPTY body (tmesis `v0.8.0`, WEBHOOKS.md 13.2).
 *
 * v0.1 wires Webhook only. The middleware already takes the purpose as a route
 * parameter so the v0.2 hook endpoint is a route line, not a second middleware.
 */
enum SignaturePurpose: string
{
    /** Asynchronous, at-least-once deliveries from the node's outbox. */
    case Webhook = 'webhook';

    /** Synchronous publish hooks, answered inside a 500 ms cap. */
    case Hook = 'hook';

    /** The keyterms hook GET (tmesis v0.8.0), signed over an empty body. */
    case Keyterms = 'keyterms';

    public static function fromName(string $purpose): self
    {
        return self::tryFrom($purpose) ?? self::Webhook;
    }
}
