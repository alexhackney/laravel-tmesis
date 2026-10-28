<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Client\NodeResolver;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use Illuminate\Contracts\Container\Container;

/**
 * An in-memory NodeResolver, for tests and examples.
 *
 * `ArrayNodeResolver::bind([...])` binds one into the container, so the registry,
 * the receiver, the hook endpoint and `Tmesis::fake()` all see its nodes. `put()` and
 * `forget()` model a node being spun up and down between requests, and `lookups()`
 * counts what the registry actually asked, which is how memoization is observed.
 */
class ArrayNodeResolver implements NodeResolver
{
    /** @var array<string, NodeConfig> */
    private array $nodes = [];

    /** @var list<string> */
    private array $lookups = [];

    /**
     * @param  iterable<NodeConfig>  $nodes
     */
    public function __construct(iterable $nodes = [])
    {
        foreach ($nodes as $node) {
            $this->put($node);
        }
    }

    /**
     * Build one and bind it as the NodeResolver.
     *
     * @param  iterable<NodeConfig>  $nodes
     */
    public static function bind(iterable $nodes = [], ?Container $container = null): self
    {
        $resolver = new self($nodes);

        ($container ?? app())->instance(NodeResolver::class, $resolver);

        return $resolver;
    }

    public function put(NodeConfig $node): self
    {
        $this->nodes[$node->name] = $node;

        return $this;
    }

    public function forget(string $name): self
    {
        unset($this->nodes[$name]);

        return $this;
    }

    public function find(string $name): ?NodeConfig
    {
        $this->lookups[] = $name;

        return $this->nodes[$name] ?? null;
    }

    public function all(): iterable
    {
        $this->lookups[] = '*';

        return array_values($this->nodes);
    }

    /**
     * Every name find() was asked for, in order, with `*` for each all().
     *
     * @return list<string>
     */
    public function lookups(): array
    {
        return $this->lookups;
    }
}
