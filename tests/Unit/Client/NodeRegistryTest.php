<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Testing\ArrayNodeResolver;
use AlexHackney\Tmesis\Tests\TestCase;

it('resolves the default node', function (): void {
    $node = app(NodeRegistry::class)->default();

    expect($node->name)->toBe(TestCase::NODE)
        ->and($node->url)->toBe('http://127.0.0.1:7000')
        ->and($node->token)->toBe('test-token');
});

it('strips a trailing slash from the url', function (): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', 'http://127.0.0.1:7000/');

    expect(app(NodeRegistry::class)->url(TestCase::NODE))->toBe('http://127.0.0.1:7000');
});

it('throws naming the key for an unknown node', function (): void {
    expect(fn () => app(NodeRegistry::class)->get('nobody'))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.nodes.nobody');
});

it('treats an empty string from the environment as absent', function (string $key, string $call): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.'.$key, '');

    expect(fn () => app(NodeRegistry::class)->{$call}(TestCase::NODE))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.nodes.'.TestCase::NODE.'.'.$key);
})->with([
    'url' => ['url', 'url'],
    'token' => ['token', 'token'],
    'webhook secret' => ['webhook_secret', 'webhookSecret'],
]);

it('falls back to the webhook secret for the hook secret', function (): void {
    $registry = app(NodeRegistry::class);

    // The suite configures a hook secret that is NOT the webhook secret, so that the
    // hook leg proves which one the route verifies with. Blank it to reach the
    // fallback, which is the shape almost every deployment has.
    config()->set('tmesis.nodes.'.TestCase::NODE.'.hook_secret', null);

    expect($registry->hookSecret(TestCase::NODE))->toBe(TestCase::WEBHOOK_SECRET);

    config()->set('tmesis.nodes.'.TestCase::NODE.'.hook_secret', 'hooksec');

    expect($registry->hookSecret(TestCase::NODE))->toBe('hooksec')
        ->and($registry->webhookSecret(TestCase::NODE))->toBe(TestCase::WEBHOOK_SECRET);
});

it('never trims a secret', function (): void {
    // A trailing newline is a DIFFERENT secret and changes every HMAC. The vector
    // file has a case row and a negative row proving exactly this.
    config()->set('tmesis.nodes.'.TestCase::NODE.'.webhook_secret', "whsec_tmesis_docs_example\n");

    expect(app(NodeRegistry::class)->webhookSecret(TestCase::NODE))
        ->toBe("whsec_tmesis_docs_example\n");
});

it('reads config lazily, so a change after resolution is seen', function (): void {
    $registry = app(NodeRegistry::class);

    expect($registry->webhookSecret(TestCase::NODE))->toBe(TestCase::WEBHOOK_SECRET);

    config()->set('tmesis.nodes.'.TestCase::NODE.'.webhook_secret', 'other');

    expect($registry->webhookSecret(TestCase::NODE))->toBe('other');
});

it('lists node names and answers has()', function (): void {
    $registry = app(NodeRegistry::class);

    expect($registry->names())->toBe([TestCase::NODE])
        ->and($registry->has(TestCase::NODE))->toBeTrue()
        ->and($registry->has('nobody'))->toBeFalse();
});

it('reads the key secrets', function (): void {
    config()->set('tmesis.keys.previous_secrets', ['old-one', '', 'old-two']);

    $registry = app(NodeRegistry::class);

    expect($registry->keySecret())->toBe(TestCase::KEY_SECRET)
        ->and($registry->previousKeySecrets())->toBe(['old-one', 'old-two']);
});

/*
 * the shipped `nodes.default` stub has a loopback url and no credential.
 * In an app whose nodes all come from a resolver it is not a node, and walking it
 * sent the doctor and `tmesis:health --all` to a box that is not there.
 */
function withShippedStub(): void
{
    config()->set('tmesis.nodes.default', [
        'url' => 'http://127.0.0.1:7000',
        'token' => null,
        'webhook_secret' => null,
        'hook_secret' => null,
    ]);
}

it('leaves an inert configured node out of names() when a resolver is set', function (): void {
    withShippedStub();
    ArrayNodeResolver::bind([new NodeConfig('edge-dyn-01', 'http://10.0.0.9:7000', 'dyn-token', 'secret')]);

    expect(app(NodeRegistry::class)->names())->toBe([TestCase::NODE, 'edge-dyn-01']);
});

it('still answers the inert node by name', function (): void {
    withShippedStub();
    ArrayNodeResolver::bind([]);

    expect(app(NodeRegistry::class)->get('default')->url)->toBe('http://127.0.0.1:7000')
        ->and(app(NodeRegistry::class)->has('default'))->toBeTrue();
});

it('lists the inert node when there is no resolver', function (): void {
    withShippedStub();

    expect(app(NodeRegistry::class)->names())->toBe([TestCase::NODE, 'default']);
});

it('keeps a configured node that carries any one credential', function (string $key): void {
    withShippedStub();
    config()->set("tmesis.nodes.default.{$key}", 'something');
    ArrayNodeResolver::bind([]);

    expect(app(NodeRegistry::class)->names())->toContain('default');
})->with(['token', 'webhook_secret', 'hook_secret']);

it('does not let a resolver node reappear under a name config shadows', function (): void {
    // get('default') answers config, so listing the resolver's row under that name
    // would walk the stub anyway.
    withShippedStub();
    ArrayNodeResolver::bind([new NodeConfig('default', 'http://10.0.0.9:7000', 'dyn-token', 'secret')]);

    expect(app(NodeRegistry::class)->names())->toBe([TestCase::NODE]);
});
