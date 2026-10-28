<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Keys;

use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;

/**
 * The SESSION epoch: the local publish generation for a stream.
 *
 * The node raises it every time a publisher attaches. It appears on stream.published,
 * stream.unpublished and stream.live, and on a StreamRecord as epoch.
 *
 * Distinct from KeyEpoch and FencingEpoch by design; see KeyEpoch.
 */
final readonly class SessionEpoch
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
