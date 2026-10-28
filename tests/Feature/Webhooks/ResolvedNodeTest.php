<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeResolver;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Events\StreamLive;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Testing\ArrayNodeResolver;
use AlexHackney\Tmesis\Tests\Doubles\ThrowingNodeResolver;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const DYN_NODE = 'origin-dyn-01';
const DYN_SECRET = 'dyn-webhook-secret';
const DYN_HOOK_SECRET = 'dyn-hook-secret';

function dynNode(): NodeConfig
{
    return new NodeConfig(DYN_NODE, 'http://10.0.0.9:7000', 'dyn-token', DYN_SECRET, DYN_HOOK_SECRET);
}

/**
 * @return array<string, mixed>
 */
function asDynNode(array $payload): array
{
    $payload['node'] = DYN_NODE;

    return $payload;
}

it('accepts a signed delivery from a resolver node, and 401s once the node is gone', function (): void {
    Event::fake();
    $resolver = ArrayNodeResolver::bind([dynNode()]);
    $headers = ['HTTP_X_TMESIS_NODE' => DYN_NODE];

    $this->signedPost('stream.published', asDynNode($this->exampleAsTestNode('stream.published')), $headers, DYN_SECRET)
        ->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    // The node is spun down and its row removed; the next request asks again.
    $resolver->forget(DYN_NODE);
    app()->forgetScopedInstances();

    $this->signedPost('stream.live', asDynNode($this->exampleAsTestNode('stream.live')), $headers, DYN_SECRET)
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'bad_signature']]);

    Event::assertNotDispatched(StreamLive::class);
});

it('verifies a resolver node with its own secret, never another node\'s', function (): void {
    Event::fake();
    ArrayNodeResolver::bind([dynNode()]);

    $this->signedPost(
        'stream.published',
        asDynNode($this->exampleAsTestNode('stream.published')),
        ['HTTP_X_TMESIS_NODE' => DYN_NODE],
        TestCase::WEBHOOK_SECRET,
    )->assertUnauthorized();

    Event::assertNotDispatched(StreamPublished::class);
});

it('answers 503 when the resolver throws, and logs without the secret or the thrown message', function (): void {
    Event::fake();
    Exceptions::fake();
    Log::spy();
    app()->instance(NodeResolver::class, new ThrowingNodeResolver);

    $this->signedPost('stream.published', asDynNode($this->exampleAsTestNode('stream.published')), ['HTTP_X_TMESIS_NODE' => DYN_NODE], DYN_SECRET)
        ->assertStatus(503)
        ->assertExactJson(['error' => ['code' => 'node_lookup_failed']]);

    Event::assertNotDispatched(StreamPublished::class);
    Exceptions::assertNothingReported();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode([$message, $context], JSON_THROW_ON_ERROR);

        return $context['node'] === DYN_NODE
            && $context['reason'] === 'node_lookup_failed'
            && str_contains($context['error'], ThrowingNodeResolver::class)
            && ! str_contains($encoded, 'hunter2')
            && ! str_contains($encoded, 'Connection refused')
            && ! str_contains($encoded, DYN_SECRET);
    });
});

it('still accepts a configured node while the resolver is down', function (): void {
    Event::fake();
    app()->instance(NodeResolver::class, new ThrowingNodeResolver);

    $this->signedPost('stream.published', $this->exampleAsTestNode('stream.published'))->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('answers 503 when the configured resolver class is not a resolver', function (): void {
    Log::spy();
    config()->set('tmesis.nodes_resolver', 'App\\Tmesis\\NoSuchResolver');

    $this->signedPost('stream.published', asDynNode($this->exampleAsTestNode('stream.published')), ['HTTP_X_TMESIS_NODE' => DYN_NODE], DYN_SECRET)
        ->assertStatus(503);

    Log::shouldHaveReceived('error')->once();
});

it('answers a signed publish hook from a resolver node, with its hook secret', function (): void {
    $this->withHooksEnabled();
    ArrayNodeResolver::bind([dynNode()]);

    $body = asDynNode($this->fixtureJson('contract/docs/contract/examples/publish.verify.json'));

    $this->signedHookPost($body, ['HTTP_X_TMESIS_NODE' => DYN_NODE], DYN_HOOK_SECRET)
        ->assertOk()
        ->assertExactJson(['allow' => true]);

    $this->signedHookPost($body, ['HTTP_X_TMESIS_NODE' => DYN_NODE], DYN_SECRET)
        ->assertUnauthorized();
});

it('answers 503 to a hook when the resolver throws', function (): void {
    $this->withHooksEnabled();
    app()->instance(NodeResolver::class, new ThrowingNodeResolver);

    $body = asDynNode($this->fixtureJson('contract/docs/contract/examples/publish.verify.json'));

    $this->signedHookPost($body, ['HTTP_X_TMESIS_NODE' => DYN_NODE], DYN_HOOK_SECRET)
        ->assertStatus(503);
});

it('runs health --all over config and resolver nodes', function (): void {
    ArrayNodeResolver::bind([dynNode(), new NodeConfig('edge-pull-01', '', null, 'secret')]);
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $this->artisan('tmesis:health --all')
        ->expectsOutputToContain('Skipping [edge-pull-01]: no admin url.')
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://127.0.0.1:7000/'));
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://10.0.0.9:7000/'));
});

it('fails health --all when the resolver throws, without its message', function (): void {
    app()->instance(NodeResolver::class, new ThrowingNodeResolver);

    $this->artisan('tmesis:health --all')
        ->doesntExpectOutputToContain('hunter2')
        ->assertFailed();
});

it('runs the doctor over resolver nodes, naming the resolver in the key', function (): void {
    ArrayNodeResolver::bind([
        new NodeConfig(DYN_NODE, 'http://10.0.0.9:7000', 'dyn-token', DYN_SECRET."\n"),
        new NodeConfig('edge-pull-01', '', null, 'secret'),
    ]);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain(ArrayNodeResolver::class)
        ->expectsOutputToContain('[tmesis.nodes_resolver['.DYN_NODE.'].webhook_secret] has leading or trailing whitespace')
        ->expectsOutputToContain('receive only')
        ->doesntExpectOutputToContain(DYN_SECRET)
        ->assertFailed();
});

it('passes the doctor with healthy resolver nodes, and checks them live', function (): void {
    ArrayNodeResolver::bind([dynNode(), new NodeConfig('edge-pull-01', '', null, 'secret')]);
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $this->artisan('tmesis:doctor --live')
        ->expectsOutputToContain('live '.DYN_NODE)
        ->expectsOutputToContain('skipped: no admin url')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://10.0.0.9:7000/'));
});

it('fails the doctor when the resolver throws, without its message', function (): void {
    app()->instance(NodeResolver::class, new ThrowingNodeResolver);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('node resolver')
        ->doesntExpectOutputToContain('hunter2')
        ->assertFailed();
});
