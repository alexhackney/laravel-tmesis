<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\NodeConfig;

class TmesisClient implements TmesisClientInterface
{
    public function __construct(private readonly NodeRegistry $registry) {}

    public function node(?string $name = null): PendingNode
    {
        return new PendingNode(
            $this->registry,
            $name === null ? $this->registry->default() : $this->registry->get($name)
        );
    }

    public function connect(NodeConfig $node): PendingNode
    {
        return new PendingNode($this->registry, $node);
    }

    public function registry(): NodeRegistry
    {
        return $this->registry;
    }
}
