<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Keys;

use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;

/**
 * The KEY epoch: field 2 of a stream key.
 *
 * It is the credential generation. The control plane raises it to invalidate every
 * key already minted for a stream, and the node refuses a key whose epoch is below
 * the stream's min_key_epoch.
 *
 * docs/API.md 8.3 names three things "epoch" and this is the first of them. They are
 * separate classes with no shared parent or interface ON PURPOSE, so a method typed
 * on one cannot be handed another; conflating them silently authorises the wrong
 * publish.
 */
final readonly class KeyEpoch
{
    private function __construct(public int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw new MalformedStreamKeyException('An epoch cannot be negative.');
        }

        return new self($value);
    }

    /**
     * Parse the decimal form: unsigned, base 10, no leading zeros, no sign, and it
     * must fit an int64. Every one of those is a row in test/vectors/keys.json.
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^(0|[1-9][0-9]*)\z/', $value) !== 1) {
            throw new MalformedStreamKeyException(
                'An epoch must be base 10, unsigned, and carry no leading zeros.'
            );
        }

        // Compared as a digit string rather than cast: (int) "9223372036854775808"
        // saturates to PHP_INT_MAX and would accept a value the node refuses.
        $ceiling = '9223372036854775807';

        if (strlen($value) > strlen($ceiling) || (strlen($value) === strlen($ceiling) && $value > $ceiling)) {
            throw new MalformedStreamKeyException('An epoch must fit a signed 64 bit integer.');
        }

        return new self((int) $value);
    }

    public function toString(): string
    {
        return (string) $this->value;
    }
}
