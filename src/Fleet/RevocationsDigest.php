<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

/**
 * The revocation list's digest, as the node computes it (docs/API.md 15.2, tmesis
 * WP5): lowercase hex SHA-256 over the list sorted, deduplicated and joined with "\n",
 * no trailing newline. The empty list hashes zero bytes.
 *
 * The heartbeat carries the node's digest of the list IN FORCE there; the package
 * compares it with this one over the list it intends, and a difference resends the
 * whole desired state at the SAME revision. That is how a delayed or lost admin-API
 * push (the second feeder) is corrected without a revision change.
 */
final class RevocationsDigest
{
    /** The digest of the empty list, which is SHA-256 of zero bytes. */
    public const EMPTY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * @param  array<array-key, string>  $streams
     */
    public static function of(array $streams): string
    {
        $unique = array_values(array_unique(array_map('strval', $streams)));
        sort($unique, SORT_STRING);

        return hash('sha256', implode("\n", $unique));
    }
}
