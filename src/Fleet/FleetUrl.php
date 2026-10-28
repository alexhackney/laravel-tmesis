<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

/**
 * The node's rule for a fleet URL (tmesis D8, `internal/fleet` checkURL): absolute,
 * https, or plain http only to a LITERAL loopback host (127.0.0.0/8, ::1, localhost),
 * and never with credentials in it.
 *
 * The package checks the heartbeat URL it is about to hand out against the same rule,
 * because the node validates the enroll answer whole and refuses all of it over one
 * insecure URL: an app behind a TLS-terminating proxy that believes it is on http
 * would otherwise spend a join token on an answer the node throws away.
 */
final class FleetUrl
{
    public static function acceptable(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return match (strtolower($parts['scheme'])) {
            'https' => true,
            'http' => self::loopback($parts['host']),
            default => false,
        };
    }

    private static function loopback(string $host): bool
    {
        $host = trim($host, '[]');

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }
}
