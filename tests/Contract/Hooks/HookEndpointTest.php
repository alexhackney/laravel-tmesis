<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Events\PublishHookDecided;
use AlexHackney\Tmesis\Hooks\AllowAllDecider;
use AlexHackney\Tmesis\Hooks\PublishHookDecider;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Doubles\NotADecider;
use AlexHackney\Tmesis\Tests\Doubles\RecordingDecider;
use AlexHackney\Tmesis\Tests\Doubles\SlowDecider;
use AlexHackney\Tmesis\Tests\Doubles\ThrowingDecider;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

pest()->group('hook-endpoint');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

beforeEach(function (): void {
    RecordingDecider::reset();

    $this->withHooksEnabled();
});

/**
 * The generated publish.verify example, as the test node would send it: the example
 * names origin-atl-01 and the harness signs as TestCase::NODE, so `node` is set to
 * match the header (in memory; the example is never edited).
 *
 * @return array<string, mixed>
 */
function hookBody(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode(
        (string) file_get_contents(ContractDir::example('publish.verify')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $payload['node'] = TestCase::NODE;

    return $payload;
}

it('answers a signed publish.verify with an allow from the default decider', function (): void {
    expect(config('tmesis.hooks.decider'))->toBe(AllowAllDecider::class);

    $response = $this->signedHookPost(hookBody());

    $response->assertOk()->assertExactJson(['allow' => true]);
});

it('never answers with an empty body and never omits allow', function (): void {
    // Both are DENY-shaped failures at the node: an empty body carries no JSON
    // document at all and lands on the on_timeout posture, and a 200 {} decodes into
    // a decision that did not say allow, which is a deny. See docs/WEBHOOKS.md 12.4.
    foreach ([AllowAllDecider::class, RecordingDecider::class, ThrowingDecider::class, NotADecider::class] as $decider) {
        config()->set('tmesis.hooks.decider', $decider);

        $response = $this->signedHookPost(hookBody());

        $body = $response->getContent();

        expect($body)->not->toBe('')
            ->and($response->getStatusCode())->toBe(200);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);

        expect($decoded)->toHaveKey('allow')
            ->and($decoded['allow'])->toBeBool();
    }
});

it('uses the decider the config names, and hands it the typed request', function (): void {
    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::$answer = HookDecision::deny('this show was cancelled');

    $this->signedHookPost(hookBody())
        ->assertOk()
        ->assertExactJson(['allow' => false, 'reason' => 'this show was cancelled']);

    expect(RecordingDecider::$calls)->toBe(1)
        ->and(RecordingDecider::$last?->stream())->toBe('show-2041-main')
        ->and(RecordingDecider::$last?->keyType())->toBe('signed')
        ->and(RecordingDecider::$last?->keyEpoch()->value)->toBe(7)
        ->and(RecordingDecider::$last?->takeover())->toBeFalse();
});

it('carries a patch through when the decider sets one', function (): void {
    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::$answer = HookDecision::allow(patch: ['ladder' => 'broadcast-720p']);

    $this->signedHookPost(hookBody())
        ->assertOk()
        ->assertExactJson(['allow' => true, 'patch' => ['ladder' => 'broadcast-720p']]);
});

it('renders a StreamSpec patch as the PATCH body: labels an object, relays [{id, url}]', function (): void {
    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::$answer = HookDecision::allow(patch: StreamSpec::make()
        ->labels(['show' => 'show-2041', 'site' => 'atl'])
        ->relays([RelayInput::make('rtmp://origin-atl-01.tsp24.com:1935/src/secret-key', 'origin')]));

    $response = $this->signedHookPost(hookBody())->assertOk();

    $response->assertExactJson([
        'allow' => true,
        'patch' => [
            'labels' => ['show' => 'show-2041', 'site' => 'atl'],
            'relays' => [['id' => 'origin', 'url' => 'rtmp://origin-atl-01.tsp24.com:1935/src/secret-key']],
        ],
    ]);

    // The node decodes the patch as a PATCH body, and `labels` is an object there.
    $raw = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
    expect($raw->patch->labels)->toBeInstanceOf(stdClass::class)
        ->and($raw->patch->relays)->toBeArray();
});

it('renders an emptied label set in a StreamSpec patch as {}, never []', function (): void {
    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::$answer = HookDecision::allow(patch: StreamSpec::make()->labels([]));

    $content = (string) $this->signedHookPost(hookBody())->assertOk()->getContent();

    expect($content)->toBe('{"allow":true,"patch":{"labels":{}}}');
});

it('passes an array patch through verbatim', function (): void {
    $patch = ['labels' => ['show' => 'x'], 'relays' => [['id' => 'origin', 'url' => 'srt://o:9000?passphrase=p']], 'future_field' => 1];

    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::$answer = HookDecision::allow(patch: $patch);

    $this->signedHookPost(hookBody())->assertOk()->assertExactJson(['allow' => true, 'patch' => $patch]);
});

it('redacts every relay url in a patch, for spec and array patches alike', function (HookDecision $decision): void {
    $redacted = $decision->toRedactedArray();

    expect($redacted['patch']['relays'][0]['url'])->toBe('rtmp://origin-atl-01.tsp24.com:1935')
        ->and($redacted['patch']['relays'][0]['id'])->toBe('origin')
        ->and($redacted['allow'])->toBeTrue()
        ->and(json_encode($redacted))->not->toContain('secret-key')
        // The answer itself is unredacted: the node needs the credential.
        ->and(json_encode($decision->toArray()))->toContain('secret-key');
})->with([
    'spec' => fn () => HookDecision::allow(patch: StreamSpec::make()
        ->relays([RelayInput::make('rtmp://origin-atl-01.tsp24.com:1935/src/secret-key', 'origin')])),
    'array' => fn () => HookDecision::allow(patch: ['relays' => [
        ['id' => 'origin', 'url' => 'rtmp://origin-atl-01.tsp24.com:1935/src/secret-key'],
    ]]),
]);

it('leaves a decision with no relays unchanged when redacting', function (): void {
    expect(HookDecision::deny('no')->toRedactedArray())->toBe(['allow' => false, 'reason' => 'no'])
        ->and(HookDecision::allow(patch: ['ladder' => 'x'])->toRedactedArray())
        ->toBe(['allow' => true, 'patch' => ['ladder' => 'x']]);
});

it('never dumps a relay credential from a decision', function (): void {
    $decision = HookDecision::allow(patch: ['relays' => [['id' => 'origin', 'url' => 'rtmp://o:1935/src/secret-key']]]);

    expect(print_r($decision, true))->not->toContain('secret-key')
        ->and(print_r(new PublishHookDecided(HookRequest::fromArray(hookBody()), $decision, 1.0), true))->not->toContain('secret-key');
});

it('hands PublishHookDecided listeners a redacted decision, and logs no patch', function (): void {
    Event::fake([PublishHookDecided::class]);
    Log::spy();

    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    config()->set('tmesis.hooks.slow_ms', 0);
    RecordingDecider::$answer = HookDecision::allow(patch: StreamSpec::make()
        ->relays([RelayInput::make('rtmp://origin-atl-01.tsp24.com:1935/src/secret-key', 'origin')]));

    $this->signedHookPost(hookBody())->assertOk();

    Event::assertDispatched(PublishHookDecided::class, function (PublishHookDecided $event): bool {
        // == and not ===: RelayInput writes url before id.
        return $event->redactedDecision() == [
            'allow' => true,
            'patch' => ['relays' => [['id' => 'origin', 'url' => 'rtmp://origin-atl-01.tsp24.com:1935']]],
        ];
    });

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('keeps the patch out of the slow-decider log line', function (): void {
    // A slow decider that answers a credential-carrying patch, bound by a container
    // key: the config names what the container resolves, not only a class.
    app()->instance('tests.slow-patch-decider', new class implements PublishHookDecider
    {
        public function decide(HookRequest $request): HookDecision
        {
            usleep(SlowDecider::SLEEP_MICROSECONDS);

            return HookDecision::allow(patch: ['relays' => [['id' => 'origin', 'url' => 'rtmp://o:1935/src/secret-key']]]);
        }
    });

    config()->set('tmesis.hooks.decider', 'tests.slow-patch-decider');
    config()->set('tmesis.hooks.slow_ms', 1);

    Log::spy();

    $this->signedHookPost(hookBody())->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => ! str_contains((string) json_encode($context), 'secret-key')
            && ! str_contains($message, 'secret-key')
    );
});

it('answers the configured posture with a 200 when the decider throws', function (bool $onError) {
    config()->set('tmesis.hooks.decider', ThrowingDecider::class);
    config()->set('tmesis.hooks.on_error', $onError);

    Log::spy();

    $this->signedHookPost(hookBody())
        ->assertOk()
        ->assertExactJson(['allow' => $onError, 'reason' => 'decider failed']);

    // report() logs the exception as well, so this names the line rather than
    // counting every error the framework wrote.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => $message === 'Tmesis publish hook decider failed.')
        ->once();
})->with([
    'on_error deny' => [false],
    'on_error allow' => [true],
]);

it('answers the configured posture when the decider class is not a decider', function (): void {
    config()->set('tmesis.hooks.decider', NotADecider::class);
    config()->set('tmesis.hooks.on_error', false);

    $this->signedHookPost(hookBody())
        ->assertOk()
        ->assertExactJson(['allow' => false, 'reason' => 'decider failed']);
});

it('answers the configured posture when the decider class does not exist', function (): void {
    config()->set('tmesis.hooks.decider', 'App\\Hooks\\ADeciderNobodyWrote');
    config()->set('tmesis.hooks.on_error', true);

    $this->signedHookPost(hookBody())
        ->assertOk()
        ->assertExactJson(['allow' => true, 'reason' => 'decider failed']);
});

it('refuses an event name that is not publish.verify, before the decider runs', function (): void {
    config()->set('tmesis.hooks.decider', RecordingDecider::class);

    // publish.takeover was registered and consulted by nothing, and was removed at
    // 2026-09-02 (docs/WEBHOOKS.md 12). Nothing but publish.verify is answered here.
    $this->signedHookPost(hookBody(), ['HTTP_X_TMESIS_EVENT' => 'publish.takeover'])
        ->assertStatus(400);

    expect(RecordingDecider::$calls)->toBe(0);
});

it('rejects a bad signature before the decider runs', function (): void {
    config()->set('tmesis.hooks.decider', RecordingDecider::class);

    $this->signedHookPost(hookBody(), ['HTTP_X_TMESIS_SIGNATURE' => 'sha256='.str_repeat('0', 64)])
        ->assertStatus(401);

    expect(RecordingDecider::$calls)->toBe(0);
});

it('verifies with the hook secret, not the webhook secret', function (): void {
    // The node's ingest.verify_hook.secret MAY differ from its webhooks.secret. The
    // suite configures two different values so this test can tell them apart.
    expect(TestCase::HOOK_SECRET)->not->toBe(TestCase::WEBHOOK_SECRET);

    $this->signedHookPost(hookBody(), secret: TestCase::WEBHOOK_SECRET)->assertStatus(401);
    $this->signedHookPost(hookBody(), secret: TestCase::HOOK_SECRET)->assertOk();
});

it('falls back to the webhook secret when no hook secret is configured', function (): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.hook_secret', null);

    $this->signedHookPost(hookBody(), secret: TestCase::WEBHOOK_SECRET)->assertOk();
});

it('refuses a body that will not decode', function (): void {
    config()->set('tmesis.hooks.decider', RecordingDecider::class);

    $this->signedHookPostRaw('not json at all')->assertStatus(400);

    expect(RecordingDecider::$calls)->toBe(0);
});

it('dispatches PublishHookDecided after the answer has been sent', function (): void {
    Event::fake([PublishHookDecided::class]);

    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::$answer = HookDecision::deny('not tonight');

    $this->signedHookPost(hookBody())->assertOk();

    Event::assertDispatched(PublishHookDecided::class, function (PublishHookDecided $event): bool {
        return $event->stream() === 'show-2041-main'
            && $event->allowed() === false
            && $event->keyFragment() === 'show-2041-main.7.a5e4...2ff2'
            && $event->elapsedMs >= 0.0;
    });
});

it('dispatches nothing when the request was refused', function (): void {
    Event::fake([PublishHookDecided::class]);

    $this->signedHookPost(hookBody(), ['HTTP_X_TMESIS_SIGNATURE' => 'sha256='.str_repeat('0', 64)])
        ->assertStatus(401);

    Event::assertNotDispatched(PublishHookDecided::class);
});

it('does not dedupe: the same delivery answered twice is answered twice', function (): void {
    // A hook is answered, not recorded. The node retries the publish handshake, not
    // the hook, and a receiver that deduped one would leave the second handshake
    // waiting for an answer that never comes.
    config()->set('tmesis.hooks.decider', RecordingDecider::class);

    $this->signedHookPost(hookBody())->assertOk()->assertExactJson(['allow' => true]);
    $this->signedHookPost(hookBody())->assertOk()->assertExactJson(['allow' => true]);

    expect(RecordingDecider::$calls)->toBe(2);
});

it('logs a warning when the decider is slower than the budget', function (): void {
    config()->set('tmesis.hooks.decider', SlowDecider::class);
    config()->set('tmesis.hooks.slow_ms', 1);

    Log::spy();

    $this->signedHookPost(hookBody())->assertOk()->assertExactJson(['allow' => true]);

    // The budget is a budget, not a promise: the request is still answered, and
    // nothing here asserts a wall clock threshold as though the cap were ours.
    Log::shouldHaveReceived('warning')->once();
});

it('validates the incoming body against the bundled schema when the switch is on', function (): void {
    config()->set('tmesis.contract.validate_incoming', true);
    config()->set('tmesis.hooks.decider', RecordingDecider::class);

    Log::spy();

    $this->signedHookPost(hookBody())->assertOk();

    Log::shouldNotHaveReceived('error');
    expect(RecordingDecider::$calls)->toBe(1);
});

it('answers anyway, loudly, when the incoming body fails its schema', function (): void {
    config()->set('tmesis.contract.validate_incoming', true);
    config()->set('tmesis.hooks.decider', RecordingDecider::class);

    $body = hookBody();
    unset($body['takeover']);

    Log::spy();

    // A body that stopped matching the pinned schema is a repin signal. Refusing to
    // decide about a live publish is not how this package raises one.
    $this->signedHookPost($body)->assertOk()->assertExactJson(['allow' => true]);

    Log::shouldHaveReceived('error')->once();
    expect(RecordingDecider::$calls)->toBe(1);
});

it('registers no route at all when hooks are disabled', function (): void {
    $this->hooksEnabled = false;
    $this->reloadApplication();

    expect(app('router')->has('tmesis.hooks.publish'))->toBeFalse();
});
