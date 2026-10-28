<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Support;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The contract's one timestamp format: RFC3339, UTC, `Z` suffixed
 * (`docs/API.md` 4).
 *
 * Only accessors parse. toArray() on a DTO returns the raw string it was given, so a
 * payload that round-trips through this package is byte identical to the one that
 * arrived.
 */
final class Rfc3339
{
    /**
     * Parse a contract timestamp.
     *
     * `Z` and `+00:00` are both accepted (the node emits `Z`; the tolerance costs
     * nothing). Any other offset is refused: the contract says UTC, and silently
     * accepting `+01:00` would let a wrong-zone timestamp through as a right one.
     *
     * Fractional seconds are accepted from 0 to 9 digits. PHP's parser handles at
     * most 6, so 7 to 9 digits are truncated to 6 before construction; sub-microsecond
     * precision has no representation in DateTimeImmutable to preserve.
     */
    public static function parse(string $value): DateTimeImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d{1,9})?(Z|\+00:00)\z/', $value, $matches) !== 1) {
            throw new InvalidArgumentException(
                "[{$value}] is not an RFC3339 UTC timestamp (docs/API.md 4 requires a Z or +00:00 offset)."
            );
        }

        $fraction = $matches[2];

        if (strlen($fraction) > 7) {
            $fraction = substr($fraction, 0, 7);
        }

        $normalised = $matches[1].$fraction.'Z';
        $format = $fraction === '' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z';

        $parsed = DateTimeImmutable::createFromFormat($format, $normalised, new \DateTimeZone('UTC'));

        if ($parsed === false) {
            throw new InvalidArgumentException("[{$value}] is not a valid RFC3339 UTC timestamp.");
        }

        return $parsed;
    }

    /**
     * Render a timestamp the way the node does: UTC, `Z`, millisecond precision.
     */
    public static function format(DateTimeInterface $at): string
    {
        return (new DateTimeImmutable('@'.$at->format('U.u')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Render a timestamp the way Go's encoding/json marshals a `time.Time` in UTC
     * (`time.RFC3339Nano`): `Z`, the fraction kept with its trailing zeros trimmed, and
     * no fraction at all when it is zero (`19:00:00Z`, `12:00:00.9Z`, `12:00:00.409Z`).
     * Precision stops at the microsecond, PHP's limit; parse() reads every output.
     */
    public static function formatNano(DateTimeInterface $at): string
    {
        $utc = (new DateTimeImmutable('@'.$at->format('U.u')))->setTimezone(new \DateTimeZone('UTC'));
        $fraction = rtrim($utc->format('u'), '0');

        return $utc->format('Y-m-d\TH:i:s').($fraction === '' ? '' : '.'.$fraction).'Z';
    }
}
