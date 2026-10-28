<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Client\NodeResolver;
use AlexHackney\Tmesis\Data\Node\NodeConfig;

/**
 * A resolver named by class in tmesis.nodes_resolver rather than bound.
 */
class ConfiguredNodeResolver implements NodeResolver
{
    public function find(string $name): ?NodeConfig
    {
        return $name === 'cfg-node' ? self::node() : null;
    }

    public function all(): iterable
    {
        return [self::node()];
    }

    public static function node(): NodeConfig
    {
        return new NodeConfig('cfg-node', 'http://10.0.0.20:7000', 'cfg-token', 'cfg-secret');
    }
}
