<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\NodeConfig;

/**
 * Supplies nodes that are not in `config('tmesis.nodes')`: a fleet whose origins,
 * transcoders and edges come and go, kept as rows in the consumer's own store.
 *
 * NodeRegistry consults `tmesis.nodes` first and this second, so a configured name
 * always wins a clash. Bind it in a service provider's register(), or name the class
 * in `tmesis.nodes_resolver`.
 *
 * Every NodeConfig handed back carries secrets. An implementation must never log,
 * cache across requests, or render one; the registry memoizes answers for the life of
 * one request or queued job only, so a node that was spun down stops being accepted
 * on the next one.
 *
 * Anything thrown here reaches callers as a NodeResolverException, and the webhook
 * receiver answers it with a 503 so the node retries the delivery.
 */
interface NodeResolver
{
    /**
     * The node of that name, or null when there is none (never registered, or spun
     * down). The returned config's name must be the name asked for.
     */
    public function find(string $name): ?NodeConfig;

    /**
     * Every node this resolver currently knows, for `tmesis:health --all` and
     * `tmesis:doctor`.
     *
     * @return iterable<NodeConfig>
     */
    public function all(): iterable;
}
