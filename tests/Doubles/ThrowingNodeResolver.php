<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Client\NodeResolver;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use RuntimeException;

/**
 * A resolver whose store is down, with a credential in its message the way a
 * database driver's can carry a DSN. Nothing this package logs may repeat it.
 */
class ThrowingNodeResolver implements NodeResolver
{
    public const LEAK = 'mysql://tmesis:hunter2@db.internal/tsp';

    public function find(string $name): ?NodeConfig
    {
        throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused ('.self::LEAK.')');
    }

    public function all(): iterable
    {
        throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused ('.self::LEAK.')');
    }
}
