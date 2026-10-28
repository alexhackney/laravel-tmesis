<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\NodeConfig;

/**
 * NodeRegistry's memo of what the node resolver answered, for one request or one
 * queued job.
 *
 * Bound as a SCOPED instance: the container forgets it when Octane starts a request
 * and when a queue worker starts a job, which is exactly the lifetime wanted. The
 * registry itself is a singleton and must not hold this, or a worker would keep
 * accepting a node that was spun down hours ago.
 *
 * Only hits are kept. A miss is asked again every time, so a node whose row is
 * written a moment after its first webhook is accepted on the retry.
 *
 * @internal
 */
final class ResolvedNodes
{
    /** @var array<string, NodeConfig> */
    private array $found = [];

    /** @var list<NodeConfig>|null */
    private ?array $all = null;

    public function get(string $name): ?NodeConfig
    {
        return $this->found[$name] ?? null;
    }

    public function remember(NodeConfig $node): void
    {
        $this->found[$node->name] = $node;
    }

    /**
     * @return list<NodeConfig>|null
     */
    public function all(): ?array
    {
        return $this->all;
    }

    /**
     * @param  list<NodeConfig>  $nodes
     */
    public function rememberAll(array $nodes): void
    {
        $this->all = $nodes;

        foreach ($nodes as $node) {
            $this->remember($node);
        }
    }
}
