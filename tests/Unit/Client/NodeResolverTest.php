<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\NodeResolver;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NodeResolverException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Testing\ArrayNodeResolver;
use AlexHackney\Tmesis\Tests\Doubles\ConfiguredNodeResolver;
use AlexHackney\Tmesis\Tests\Doubles\NotADecider;
use AlexHackney\Tmesis\Tests\Doubles\ThrowingNodeResolver;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function dynamicNode(string $name = 'origin-dyn-01', string $url = 'http://10.0.0.9:7000'): NodeConfig
{
    return new NodeConfig($name, $url, 'dyn-token', 'dyn-webhook-secret');
}

it('behaves exactly as before with no resolver', function (): void {
    $registry = app(NodeRegistry::class);

    expect($registry->resolver())->toBeNull()
        ->and($registry->names())->toBe([TestCase::NODE])
        ->and($registry->has('origin-dyn-01'))->toBeFalse()
        ->and(fn () => $registry->get('origin-dyn-01'))->toThrow(InvalidConfigurationException::class, 'tmesis.nodes.origin-dyn-01');
});

it('consults config first, then the resolver', function (): void {
    $resolver = ArrayNodeResolver::bind([dynamicNode()]);
    $registry = app(NodeRegistry::class);

    expect($registry->get(TestCase::NODE)->url)->toBe('http://127.0.0.1:7000')
        ->and($resolver->lookups())->toBe([])
        ->and($registry->get('origin-dyn-01')->url)->toBe('http://10.0.0.9:7000')
        ->and($registry->has('origin-dyn-01'))->toBeTrue()
        ->and($registry->webhookSecret('origin-dyn-01'))->toBe('dyn-webhook-secret')
        ->and($registry->hookSecret('origin-dyn-01'))->toBe('dyn-webhook-secret')
        ->and($registry->names())->toBe([TestCase::NODE, 'origin-dyn-01']);
});

it('lets config win a name clash, and lists the name once', function (): void {
    $resolver = ArrayNodeResolver::bind([
        new NodeConfig(TestCase::NODE, 'http://10.6.6.6:7000', 'evil-token', 'evil-secret'),
    ]);
    $registry = app(NodeRegistry::class);

    expect($registry->get(TestCase::NODE)->url)->toBe('http://127.0.0.1:7000')
        ->and($registry->webhookSecret(TestCase::NODE))->toBe(TestCase::WEBHOOK_SECRET)
        ->and($registry->names())->toBe([TestCase::NODE])
        ->and($resolver->lookups())->toBe(['*']);
});

it('asks the resolver once per name within one request or job', function (): void {
    $resolver = ArrayNodeResolver::bind([dynamicNode()]);
    $registry = app(NodeRegistry::class);

    $registry->has('origin-dyn-01');
    $registry->get('origin-dyn-01');
    $registry->webhookSecret('origin-dyn-01');
    $registry->token('origin-dyn-01');

    expect($resolver->lookups())->toBe(['origin-dyn-01']);
});

it('asks again in the next request or job, so a node spun down stops being known', function (): void {
    $resolver = ArrayNodeResolver::bind([dynamicNode()]);
    $registry = app(NodeRegistry::class);

    expect($registry->has('origin-dyn-01'))->toBeTrue();

    $resolver->forget('origin-dyn-01');

    // The same request still holds its answer.
    expect($registry->has('origin-dyn-01'))->toBeTrue();

    // What Octane does per request and a queue worker per job.
    app()->forgetScopedInstances();

    expect($registry->has('origin-dyn-01'))->toBeFalse()
        ->and($resolver->lookups())->toBe(['origin-dyn-01', 'origin-dyn-01']);
});

it('never memoizes a miss', function (): void {
    $resolver = ArrayNodeResolver::bind();
    $registry = app(NodeRegistry::class);

    expect($registry->has('origin-dyn-01'))->toBeFalse();

    $resolver->put(dynamicNode());

    expect($registry->has('origin-dyn-01'))->toBeTrue()
        ->and($resolver->lookups())->toBe(['origin-dyn-01', 'origin-dyn-01']);
});

it('names a node neither config nor the resolver knows', function (): void {
    ArrayNodeResolver::bind();

    expect(fn () => app(NodeRegistry::class)->get('nobody'))
        ->toThrow(InvalidConfigurationException::class, 'No node [nobody] is configured under [tmesis.nodes.nobody] or known to the node resolver.');
});

it('wraps a throwing resolver without repeating its message', function (): void {
    app()->instance(NodeResolver::class, new ThrowingNodeResolver);
    $registry = app(NodeRegistry::class);

    try {
        $registry->has('origin-dyn-01');
        $this->fail('Expected a NodeResolverException.');
    } catch (NodeResolverException $e) {
        expect($e->getMessage())->toBe('The node resolver ['.ThrowingNodeResolver::class.'] failed finding node [origin-dyn-01]: RuntimeException.')
            ->and($e->getMessage())->not->toContain('hunter2')
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }

    expect(fn () => $registry->names())->toThrow(NodeResolverException::class, 'failed listing its nodes');
});

it('refuses a resolver that answers with another node', function (): void {
    $resolver = new class implements NodeResolver
    {
        public function find(string $name): ?NodeConfig
        {
            return dynamicNode('someone-else');
        }

        public function all(): iterable
        {
            return [];
        }
    };

    app()->instance(NodeResolver::class, $resolver);

    expect(fn () => app(NodeRegistry::class)->has('origin-dyn-01'))
        ->toThrow(NodeResolverException::class, 'answered node [someone-else] when asked for [origin-dyn-01]');
});

it('resolves the class tmesis.nodes_resolver names', function (): void {
    config()->set('tmesis.nodes_resolver', ConfiguredNodeResolver::class);
    $registry = app(NodeRegistry::class);

    expect($registry->resolver())->toBeInstanceOf(ConfiguredNodeResolver::class)
        ->and($registry->has('cfg-node'))->toBeTrue()
        ->and($registry->webhookSecret('cfg-node'))->toBe('cfg-secret');
});

it('prefers a bound resolver over the configured class', function (): void {
    config()->set('tmesis.nodes_resolver', ConfiguredNodeResolver::class);
    ArrayNodeResolver::bind([dynamicNode()]);

    expect(app(NodeRegistry::class)->resolver())->toBeInstanceOf(ArrayNodeResolver::class);
});

it('refuses a configured class that is not a resolver', function (string $class): void {
    config()->set('tmesis.nodes_resolver', $class);

    expect(fn () => app(NodeRegistry::class)->has('anything'))
        ->toThrow(InvalidConfigurationException::class, 'The config key [tmesis.nodes_resolver] is invalid');
})->with([
    'not a resolver' => [NotADecider::class],
    'no such class' => ['App\\Tmesis\\NoSuchResolver'],
]);

it('treats a blank tmesis.nodes_resolver as none', function (): void {
    config()->set('tmesis.nodes_resolver', '');

    expect(app(NodeRegistry::class)->resolver())->toBeNull();
});

it('takes the default node from the resolver', function (): void {
    ArrayNodeResolver::bind([dynamicNode()]);
    config()->set('tmesis.default', 'origin-dyn-01');

    Http::fake(['*' => Http::response(test()->fixtureJson('responses/getHealth.json'))]);

    expect(app(NodeRegistry::class)->default()->name)->toBe('origin-dyn-01');

    Tmesis::node()->getHealth();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://10.0.0.9:7000/')
        && $request->header('Authorization') === ['Bearer dyn-token']);
});

it('calls a resolver node by name through Tmesis::node()', function (): void {
    ArrayNodeResolver::bind([dynamicNode()]);

    Http::fake(['*' => Http::response(test()->fixtureJson('responses/getHealth.json'))]);

    $node = Tmesis::node('origin-dyn-01');
    $node->getHealth();

    expect($node->config()->name)->toBe('origin-dyn-01');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.0.0.9:7000/api/v1/health'
        && $request->header('Authorization') === ['Bearer dyn-token']);
});

it('names the resolver in the guard for a resolver node with no url', function (): void {
    ArrayNodeResolver::bind([new NodeConfig('edge-pull-01', '', null, 'secret')]);

    expect(app(NodeRegistry::class)->webhookSecret('edge-pull-01'))->toBe('secret')
        ->and(fn () => Tmesis::node('edge-pull-01')->getHealth())
        ->toThrow(InvalidConfigurationException::class, 'tmesis.nodes_resolver[edge-pull-01].url');
});

it('serves a resolver node from the fake', function (): void {
    $fake = Tmesis::fake()->withHealth(test()->fixtureJson('responses/getHealth.json'));
    ArrayNodeResolver::bind([dynamicNode()]);

    $node = Tmesis::node('origin-dyn-01');

    expect($node->config()->name)->toBe('origin-dyn-01')
        ->and($node->getHealth()->status())->toBeString();

    $fake->assertCalled('getHealth');
});
