<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

/**
 * The webhook signing function, and the only one in this package.
 *
 * A byte-for-byte surface: the node signs, this verifies, and a disagreement about
 * the separator, the encoding, or the hex case rejects every webhook on the platform.
 * `test/vectors/webhooks.json` is the agreement, negative rows included, and every
 * one of those rows names a mistake this implementation must not make.
 */
final class WebhookSignature
{
    /**
     * The prefix names the algorithm so a future one can be added. A receiver must
     * REJECT a prefix it does not recognise rather than guessing.
     */
    public const PREFIX = 'sha256=';

    /**
     * Timestamp bytes, then one 0x2E, then body bytes. Nothing else joins them: no
     * newline, no colon, no length prefix, no space.
     *
     * The timestamp is the ASCII of the header value. Do not parse it to an integer
     * and re-format it.
     */
    public static function signedString(string $timestamp, string $body): string
    {
        return $timestamp.'.'.$body;
    }

    /**
     * The X-Tmesis-Signature header value for one attempt.
     *
     * The secret is used as its raw bytes exactly as configured: not base64-decoded,
     * not hex-decoded, and NOT trimmed. A trailing newline is a different secret.
     */
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return self::PREFIX.hash_hmac('sha256', self::signedString($timestamp, $body), $secret);
    }

    /**
     * Constant-time comparison over the FULL header value, prefix included.
     *
     * An unrecognised prefix is rejected before any MAC is computed, so a bare hex
     * value or an uppercase SHA256= never reaches the comparison.
     */
    public static function verify(string $secret, string $timestamp, string $body, string $presented): bool
    {
        if (! str_starts_with($presented, self::PREFIX)) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $body), $presented);
    }
}
