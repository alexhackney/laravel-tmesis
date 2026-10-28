<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Keys;

use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;

/**
 * The stream id charset, from `docs/API.md` 8.1.
 *
 * A stream id becomes a delivery path prefix, so it is validated as a path component
 * and not merely as a name: no dot (which is the key separator), no slash, no space,
 * no NUL, nothing above ASCII, 128 bytes at most.
 */
final class StreamId
{
    public const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,127}\z/';

    /**
     * @throws MalformedStreamKeyException
     */
    public static function assert(string $id): void
    {
        // No `u` modifier: the rule is over BYTES. With it, an invalid UTF-8 byte
        // sequence would make preg_match return false rather than 0, and a NUL byte
        // is only excluded because the pattern is anchored over bytes.
        if (preg_match(self::PATTERN, $id) !== 1) {
            throw new MalformedStreamKeyException(
                'A stream id must match ^[a-z0-9][a-z0-9_-]{0,127}$ (docs/API.md 8.1).'
            );
        }
    }

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }
}
