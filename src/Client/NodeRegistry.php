<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NodeResolverException;
use AlexHackney\Tmesis\Support\Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Resolves node names to their settings: `tmesis.nodes` first, then the bound
 * NodeResolver.
 *
 * Config wins a name clash. The nodes that stay up (the DC origin, the long-lived
 * edges) are pinned by the operator in config, and a row in the consumer's store must
 * not be able to redirect one of them or swap its secrets; a configured node also
 * never costs a resolver lookup, which keeps the receiver's hot path off the database
 * for the fixed fleet.
 *
 * Resolver answers are memoized in ResolvedNodes, a scoped instance, so one request or
 * one queued job asks once per name and the next one asks again. A miss is never
 * memoized.
 *
 * It holds the config repository and reads `tmesis.*` LAZILY on every call. It never
 * snapshots the array at construction, and that is load bearing rather than
 * incidental: the webhook vector suite sets a node's secret per dataset row,
 * `tmesis:doctor` walks every node, and a consumer's test calls `config()->set()` on a
 * node right before `FakeTmesis::webhook()->deliverTo()`. All three happen after this
 * singleton has been resolved, and all three expect the next call to see the change.
 */
class NodeRegistry
{
    public function __construct(
        private readonly Repository $config,
        private readonly ?Container $container = null,
    ) {}

    /**
     * The node used when a call names none.
     */
    public function default(): NodeConfig
    {
        return $this->get($this->defaultName());
    }

    public function defaultName(): string
    {
        $name = $this->config->get('tmesis.default');

        return Config::requireValue('tmesis.default', $name);
    }

    /**
     * @throws InvalidConfigurationException when no node of that name is configured
     *                                       or known to the resolver
     * @throws NodeResolverException when the resolver fails
     */
    public function get(string $name): NodeConfig
    {
        $node = $this->configured($name);

        if ($node !== null) {
            return $node;
        }

        $resolver = $this->resolver();

        if ($resolver === null) {
            throw InvalidConfigurationException::missing("tmesis.nodes.{$name}");
        }

        return $this->find($resolver, $name) ?? throw InvalidConfigurationException::unknownNode($name);
    }

    /**
     * @throws NodeResolverException when the resolver fails
     * @throws InvalidConfigurationException when `tmesis.nodes_resolver` is not a resolver
     */
    public function has(string $name): bool
    {
        if ($this->configured($name) !== null) {
            return true;
        }

        $resolver = $this->resolver();

        return $resolver !== null && $this->find($resolver, $name) !== null;
    }

    /**
     * Every configured node name, then every name the resolver supplies that config
     * does not already have.
     *
     * With a resolver, a configured entry that carries no token, no webhook secret
     * and no hook secret is left out. That is the shipped `nodes.default` stub (its
     * url defaults to loopback, so it always has one) in an app whose nodes all come
     * from the resolver: it can neither be called nor verify a delivery, and walking
     * it made `tmesis:doctor` and `tmesis:health --all` probe a box that is not there.
     * It is left out of this list only: get() still answers it, config still wins a
     * clash with it, and without a resolver it is listed as before so the doctor can
     * say which key the zero-config setup is missing.
     *
     * @return list<string>
     *
     * @throws NodeResolverException when the resolver fails
     * @throws InvalidConfigurationException when `tmesis.nodes_resolver` is not a resolver
     */
    public function names(): array
    {
        $configured = $this->configuredNames();
        $resolver = $this->resolver();

        if ($resolver === null) {
            return $configured;
        }

        $names = array_values(array_filter(
            $configured,
            fn (string $name): bool => ! $this->inert($name),
        ));

        foreach ($this->all($resolver) as $node) {
            if (! in_array($node->name, $configured, true) && ! in_array($node->name, $names, true)) {
                $names[] = $node->name;
            }
        }

        return $names;
    }

    /**
     * The bound NodeResolver, else the class `tmesis.nodes_resolver` names, else null.
     *
     * Read on every call, the way the hook decider and the transfer signer are, so a
     * test can bind one after this singleton has resolved.
     *
     * @throws InvalidConfigurationException when the configured class is not a resolver
     */
    public function resolver(): ?NodeResolver
    {
        if ($this->container === null) {
            return null;
        }

        if ($this->container->bound(NodeResolver::class)) {
            $resolver = $this->container->make(NodeResolver::class);
        } else {
            $class = $this->config->get('tmesis.nodes_resolver');

            if (blank($class)) {
                return null;
            }

            $resolver = is_string($class) && class_exists($class) ? $this->container->make($class) : null;
        }

        if (! $resolver instanceof NodeResolver) {
            throw InvalidConfigurationException::invalid(
                'tmesis.nodes_resolver',
                'it must name a class implementing '.NodeResolver::class
            );
        }

        return $resolver;
    }

    /**
     * The node's base URL, guarded.
     *
     * The transport reads the URL through here and nowhere else, so a Doppler `""`
     * fails naming the key before a request is built rather than producing a request
     * to a relative address.
     */
    public function url(NodeConfig|string $node): string
    {
        $node = $this->resolve($node);

        return Config::requireValue($this->keyFor($node, 'url'), $node->url);
    }

    /**
     * The node's admin bearer token, guarded.
     *
     * Same reason as url(): an empty token would otherwise go out as an empty
     * Authorization header and come back as an unexplained 401.
     */
    public function token(NodeConfig|string $node): string
    {
        $node = $this->resolve($node);

        return Config::requireValue($this->keyFor($node, 'token'), $node->token);
    }

    /**
     * The secret this node signs its webhooks with.
     */
    public function webhookSecret(NodeConfig|string $node): string
    {
        $node = $this->resolve($node);

        return Config::requireValue($this->keyFor($node, 'webhook_secret'), $node->webhookSecret);
    }

    /**
     * The secret this node signs synchronous publish hooks with.
     *
     * Falls back to the webhook secret when blank, which is the documented default:
     * the node's `ingest.verify_hook.secret` MAY differ from `webhooks.secret` but
     * usually does not.
     */
    public function hookSecret(NodeConfig|string $node): string
    {
        $node = $this->resolve($node);

        if (! blank($node->hookSecret)) {
            return (string) $node->hookSecret;
        }

        return $this->webhookSecret($node);
    }

    /**
     * The secret this node signs its keyterms hook GET with (tmesis `v0.8.0`).
     *
     * Falls back to the webhook secret when blank, the hook secret's posture: the
     * enrolment mints it AS the webhook secret unless asked for a distinct one, so a
     * store that keeps the webhook secret alone verifies all three channels. Sound
     * because the keyterms signature covers an EMPTY body and every webhook body is
     * a JSON document, so neither signature verifies as the other.
     */
    public function keytermsHookSecret(NodeConfig|string $node): string
    {
        $node = $this->resolve($node);

        if (! blank($node->keytermsHookSecret)) {
            return (string) $node->keytermsHookSecret;
        }

        return $this->webhookSecret($node);
    }

    /**
     * The secret new publish keys are minted with.
     *
     * Global, never per node, and never supplied by the resolver: keys are ours and
     * self-validating, so every node in the fleet, configured or resolved, verifies
     * them with this same secret in its `ingest.keys`.
     */
    public function keySecret(): string
    {
        return Config::requireValue('tmesis.keys.secret', $this->config->get('tmesis.keys.secret'));
    }

    /**
     * Secrets being rotated out, tried in order after the current one.
     *
     * @return list<string>
     */
    public function previousKeySecrets(): array
    {
        $secrets = $this->config->get('tmesis.keys.previous_secrets');

        if (! is_array($secrets)) {
            return [];
        }

        $out = [];

        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $out[] = $secret;
            }
        }

        return $out;
    }

    private function resolve(NodeConfig|string $node): NodeConfig
    {
        return $node instanceof NodeConfig ? $node : $this->get($node);
    }

    private function configured(string $name): ?NodeConfig
    {
        $node = $this->config->get("tmesis.nodes.{$name}");

        if (! is_array($node)) {
            return null;
        }

        /** @var array<string, mixed> $node */
        return NodeConfig::fromArray($name, $node);
    }

    /**
     * A configured entry with no credential of any kind: nothing can be called on it
     * and nothing it sends can be verified.
     */
    private function inert(string $name): bool
    {
        $node = $this->configured($name);

        return $node !== null
            && blank($node->token)
            && blank($node->webhookSecret)
            && blank($node->hookSecret)
            && blank($node->keytermsHookSecret);
    }

    /**
     * @return list<string>
     */
    private function configuredNames(): array
    {
        $nodes = $this->config->get('tmesis.nodes');

        if (! is_array($nodes)) {
            return [];
        }

        /** @var list<string> $names */
        $names = array_values(array_filter(array_keys($nodes), 'is_string'));

        return $names;
    }

    private function find(NodeResolver $resolver, string $name): ?NodeConfig
    {
        $memo = $this->memo();

        if (($node = $memo?->get($name)) !== null) {
            return $node;
        }

        try {
            $node = $resolver->find($name);
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw NodeResolverException::failed($resolver::class, $name, $e);
        }

        if ($node === null) {
            return null;
        }

        if ($node->name !== $name) {
            throw NodeResolverException::mismatch($resolver::class, $name, $node->name);
        }

        $memo?->remember($node);

        return $node;
    }

    /**
     * @return list<NodeConfig>
     */
    private function all(NodeResolver $resolver): array
    {
        $memo = $this->memo();

        if (($nodes = $memo?->all()) !== null) {
            return $nodes;
        }

        try {
            $nodes = [];

            foreach ($resolver->all() as $node) {
                $nodes[] = $node;
            }
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw NodeResolverException::failed($resolver::class, null, $e);
        }

        $memo?->rememberAll($nodes);

        return $nodes;
    }

    private function memo(): ?ResolvedNodes
    {
        return $this->container?->make(ResolvedNodes::class);
    }

    /**
     * The key a guard names: the config path for a configured node (and for a node
     * built by connect()), a resolver label for a node the resolver supplied.
     */
    private function keyFor(NodeConfig $node, string $field): string
    {
        if ($this->configured($node->name) === null && $this->memo()?->get($node->name) !== null) {
            return "tmesis.nodes_resolver[{$node->name}].{$field}";
        }

        return "tmesis.nodes.{$node->name}.{$field}";
    }
}
