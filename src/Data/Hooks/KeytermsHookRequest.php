<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Hooks;

/**
 * One keyterms hook call (tmesis `v0.8.0`, `docs/WEBHOOKS.md` 13): which stream,
 * session and caption language the node wants the keyterms for.
 *
 * A GET with no body, so there is no request document and no request schema: the
 * three identifiers are the QUERY, which the node's signature does NOT cover (it
 * signs the timestamp and an empty body). The query names show data, not a secret;
 * the signature proves only that the node asked. A provider answers for the stream
 * it names and never treats the query as authorization for anything else.
 */
final readonly class KeytermsHookRequest
{
    /** The X-Tmesis-Event the node sends. */
    public const EVENT = 'keyterms.hook';

    /** The response document (`keyterms.hook.response.schema.json`). */
    public const RESPONSE_SCHEMA = 'keyterms.hook.response';

    public function __construct(
        /** The node that asked, its X-Tmesis-Node. */
        public string $node,
        public string $stream,
        /** The publish session's ULID. */
        public string $session,
        /** The caption language, an RFC 5646 tag. */
        public string $lang,
        /** X-Tmesis-Delivery: a ULID per call, for correlation only. */
        public string $deliveryId,
    ) {}

    /**
     * The query the node sends, or null when `stream`, `session` or `lang` is missing
     * or not a non-empty string.
     *
     * @param  array<array-key, mixed>  $query
     */
    public static function fromQuery(string $node, string $deliveryId, array $query): ?self
    {
        $values = [];

        foreach (['stream', 'session', 'lang'] as $name) {
            $value = $query[$name] ?? null;

            if (! is_string($value) || $value === '') {
                return null;
            }

            $values[$name] = $value;
        }

        return new self($node, $values['stream'], $values['session'], $values['lang'], $deliveryId);
    }
}
