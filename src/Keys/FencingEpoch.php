<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Keys;

use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;

/**
 * The FENCING epoch: which node holds the write lease for an output prefix.
 *
 * Monotonic per prefix, and the tiebreak that stops two origins writing the same
 * playlist. It appears on recording.started as fencing_epoch.
 *
 * Distinct from KeyEpoch and SessionEpoch by design; see KeyEpoch.
 */
final readonly class FencingEpoch
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
