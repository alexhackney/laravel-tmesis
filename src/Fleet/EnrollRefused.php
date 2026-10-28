<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use RuntimeException;

/**
 * An enrollment the package refuses, with the status and `error.code` the route
 * answers. Never carries a token.
 *
 * @internal
 */
final class EnrollRefused extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function unknownToken(): self
    {
        // One answer for unknown, expired, spent and outside the window: a caller
        // learns nothing about which tokens exist.
        return new self(401, 'unknown_token', 'The join token is not valid.');
    }
}
