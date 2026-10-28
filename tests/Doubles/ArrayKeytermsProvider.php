<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;
use AlexHackney\Tmesis\Hooks\KeytermsProvider;
use RuntimeException;

/**
 * A keyterms provider that answers what a test set, records what it was asked, and
 * throws (with a message carrying a DSN) when told to.
 */
class ArrayKeytermsProvider implements KeytermsProvider
{
    /** @var array<array-key, mixed> */
    public static array $answer = [];

    public static bool $throws = false;

    /** @var list<KeytermsHookRequest> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$answer = [];
        self::$throws = false;
        self::$requests = [];
    }

    public function keyterms(KeytermsHookRequest $request): array
    {
        self::$requests[] = $request;

        if (self::$throws) {
            throw new RuntimeException('mysql://tsp:SECRET_PASSWORD@db.internal/tsp is unreachable');
        }

        /** @var list<string> */
        return self::$answer;
    }
}
