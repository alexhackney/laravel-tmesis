<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Keys;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Exceptions\StreamKeySignatureException;

/**
 * Mints and verifies publish keys.
 *
 * The control plane mints; the node only ever verifies. Both compute the same HMAC
 * over the same signed string, and `test/vectors/keys.json` is the agreement. A row
 * this class cannot reproduce is a bug here, never a reason to edit the vector.
 *
 * Nothing in this class logs a key. StreamKey::fragment() is the loggable form.
 */
class StreamKeyMinter
{
    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * Mint the key for a stream at a key epoch.
     *
     * The secret is used as its raw bytes exactly as configured: not decoded, not
     * trimmed. Output is lowercase hex, which is what hash_hmac already emits;
     * strtolower is belt and braces against a future default changing.
     */
    public function mint(string $stream, KeyEpoch $epoch, ?string $secret = null): StreamKey
    {
        StreamId::assert($stream);

        $secret ??= $this->registry->keySecret();

        $mac = strtolower(hash_hmac('sha256', $stream.'.'.$epoch->toString(), $secret));

        return new StreamKey($stream, $epoch, $mac);
    }

    /**
     * @throws StreamKeySignatureException when the MAC does not match
     */
    public function verify(StreamKey|string $key, ?string $secret = null): void
    {
        $key = $key instanceof StreamKey ? $key : StreamKey::parse($key);

        $secret ??= $this->registry->keySecret();

        $expected = $this->mint($key->stream, $key->epoch, $secret);

        if (! hash_equals($expected->mac, $key->mac)) {
            throw new StreamKeySignatureException(
                'The stream key signature does not match: '.$key->fragment()
            );
        }
    }

    /**
     * Verify against the current secret, then each rotating-out secret in turn.
     *
     * This is the rotation window: a key already in a producer's hands stays valid
     * while the new secret is rolled out.
     *
     * @param  list<string>|null  $secrets  defaults to keys.previous_secrets
     *
     * @throws StreamKeySignatureException when none of them match
     */
    public function verifyAgainstAny(StreamKey|string $key, ?array $secrets = null): void
    {
        $key = $key instanceof StreamKey ? $key : StreamKey::parse($key);

        $candidates = [$this->registry->keySecret(), ...($secrets ?? $this->registry->previousKeySecrets())];

        foreach ($candidates as $secret) {
            try {
                $this->verify($key, $secret);

                return;
            } catch (StreamKeySignatureException) {
                continue;
            }
        }

        throw new StreamKeySignatureException(
            'The stream key signature does not match any configured secret: '.$key->fragment()
        );
    }
}
