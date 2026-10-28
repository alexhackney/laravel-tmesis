<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\InvalidKeytermsException;
use AlexHackney\Tmesis\Hooks\NoKeyterms;
use AlexHackney\Tmesis\Testing\FakeTmesis;
use AlexHackney\Tmesis\Tests\Doubles\ArrayKeytermsProvider;
use AlexHackney\Tmesis\Tests\Doubles\NotADecider;
use AlexHackney\Tmesis\Tests\TestCase;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    ArrayKeytermsProvider::reset();
});

it('registers the keyterms route even when it is switched off, because the node reads a 404 as none', function (): void {
    expect(config('tmesis.hooks.keyterms.enabled'))->toBeFalse()
        ->and(Route::getRoutes()->getByName('tmesis.hooks.keyterms')?->uri())->toBe('webhooks/hooks/keyterms');
});

it('answers a signed request on the switched-off route 503 keyterms_disabled, which keeps the node\'s list, and never asks the provider', function (): void {
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
    ArrayKeytermsProvider::$answer = ['UFC'];
    Log::spy();

    $response = FakeTmesis::keytermsHook('show-2041')->deliverTo();

    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['error' => ['code' => 'keyterms_disabled']])
        ->and(ArrayKeytermsProvider::$requests)->toBe([]);

    FakeTmesis::keytermsHook('show-2041')->assertKeptInForce();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'hooks.keyterms.enabled is false')
        && $context === ['node' => TestCase::NODE, 'delivery' => $context['delivery']]);
});

it('verifies the signature before saying the route is off, so an unsigned caller learns nothing', function (): void {
    FakeTmesis::keytermsHook()->withSecret('not-the-secret')->assertStatus(401);
    FakeTmesis::keytermsHook()->fromNode('nobody')->withSecret('x')->assertStatus(401);

    $this->call('GET', route('tmesis.hooks.keyterms').'?stream=s&session=x&lang=en')
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'bad_signature']]);
});

it('reads the switch per request, so config()->set() in a test turns the route on without a reboot', function (): void {
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
    ArrayKeytermsProvider::$answer = ['UFC'];

    FakeTmesis::keytermsHook('show-2041')->assertStatus(503);

    config()->set('tmesis.hooks.keyterms.enabled', true);

    FakeTmesis::keytermsHook('show-2041')->assertKeyterms(['UFC']);
});

it('registers GET {prefix}/hooks/keyterms on its own switch', function (): void {
    $this->withKeytermsHookEnabled();

    $route = Route::getRoutes()->getByName('tmesis.hooks.keyterms');

    expect($route?->uri())->toBe('webhooks/hooks/keyterms')
        ->and($route?->methods())->toContain('GET')
        ->and(Route::has('tmesis.hooks.publish'))->toBeFalse();
});

it('answers none from the default provider, as a 200 the node reads as none', function (): void {
    $this->withKeytermsHookEnabled();

    expect(config('tmesis.hooks.keyterms.provider'))->toBe(NoKeyterms::class);

    $response = FakeTmesis::keytermsHook('show-2041')->deliverTo();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('{"keyterms":[]}');
});

it('hands the provider the node, stream, session, language and delivery, and answers its list', function (): void {
    $this->withKeytermsHookEnabled();
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
    ArrayKeytermsProvider::$answer = ['Hector Rodriguez', 'Conor McGregor', 'UFC', 'T-Mobile Arena', 'Las Vegas'];

    FakeTmesis::keytermsHook('show-2041')
        ->forSession('01JAAAAAAAAAAAAAAAAAAAAAAA')
        ->inLanguage('es-MX')
        ->withDeliveryId('01JBBBBBBBBBBBBBBBBBBBBBBB')
        ->assertKeyterms(['Hector Rodriguez', 'Conor McGregor', 'UFC', 'T-Mobile Arena', 'Las Vegas']);

    $request = ArrayKeytermsProvider::$requests[0];

    expect($request->node)->toBe(TestCase::NODE)
        ->and($request->stream)->toBe('show-2041')
        ->and($request->session)->toBe('01JAAAAAAAAAAAAAAAAAAAAAAA')
        ->and($request->lang)->toBe('es-MX')
        ->and($request->deliveryId)->toBe('01JBBBBBBBBBBBBBBBBBBBBBBB');
});

it('verifies with the keyterms hook secret over an empty body, never the webhook or publish hook secret', function (): void {
    $this->withKeytermsHookEnabled();

    FakeTmesis::keytermsHook()->assertStatus(200);
    FakeTmesis::keytermsHook()->withSecret(TestCase::WEBHOOK_SECRET)->assertStatus(401);
    FakeTmesis::keytermsHook()->withSecret(TestCase::HOOK_SECRET)->assertStatus(401);
});

it('falls back to the webhook secret when the node keeps no keyterms hook secret', function (): void {
    $this->withKeytermsHookEnabled();
    config()->set('tmesis.nodes.'.TestCase::NODE.'.keyterms_hook_secret', null);

    FakeTmesis::keytermsHook()->assertStatus(200);
    FakeTmesis::keytermsHook()->withSecret(TestCase::KEYTERMS_SECRET)->assertStatus(401);
});

it('ignores a body: the signature is over the timestamp and nothing else', function (): void {
    $this->withKeytermsHookEnabled();
    $timestamp = (string) now()->getTimestamp();
    $body = '{"event":"stream.live"}';

    $headers = fn (string $signedBody): array => [
        'HTTP_X_TMESIS_EVENT' => 'keyterms.hook',
        'HTTP_X_TMESIS_DELIVERY' => '01JCCCCCCCCCCCCCCCCCCCCCCC',
        'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
        'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign(TestCase::KEYTERMS_SECRET, $timestamp, $signedBody),
        'HTTP_X_TMESIS_NODE' => TestCase::NODE,
    ];
    $url = route('tmesis.hooks.keyterms').'?stream=s&session=x&lang=en';

    // A signature over a body (a replayed webhook's, say) never verifies here.
    $this->call('GET', $url, [], [], [], $headers($body), $body)->assertUnauthorized();
    $this->call('GET', $url, [], [], [], $headers(''), $body)->assertOk();
});

it('refuses a stale timestamp, an unknown node and a missing signature with the receiver\'s 401s', function (): void {
    $this->withKeytermsHookEnabled();

    FakeTmesis::keytermsHook()->at(now()->subMinutes(10))->assertStatus(401);
    FakeTmesis::keytermsHook()->fromNode('nobody')->withSecret('x')->assertStatus(401);
});

it('answers 400 to a request that is not a keyterms hook', function (): void {
    $this->withKeytermsHookEnabled();

    FakeTmesis::keytermsHook()->asEvent('publish.verify')->assertStatus(400);

    $timestamp = (string) now()->getTimestamp();
    $this->call('GET', route('tmesis.hooks.keyterms').'?stream=s&lang=en', [], [], [], [
        'HTTP_X_TMESIS_EVENT' => 'keyterms.hook',
        'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
        'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign(TestCase::KEYTERMS_SECRET, $timestamp, ''),
        'HTTP_X_TMESIS_NODE' => TestCase::NODE,
    ])->assertStatus(400)->assertJsonPath('error.code', 'bad_request');
});

it('normalises what the node would fix the same way: trim and case-insensitive duplicates', function (): void {
    $this->withKeytermsHookEnabled();
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
    ArrayKeytermsProvider::$answer = ['  UFC ', "\u{00A0}ufc", 'Las Vegas', 'LAS VEGAS', 'T-Mobile Arena'];

    FakeTmesis::keytermsHook()->assertKeyterms(['UFC', 'Las Vegas', 'T-Mobile Arena']);
});

it('answers 500 rather than a list the node would ignore whole, and reports it', function (array $answer, string $message): void {
    $this->withKeytermsHookEnabled();
    Log::spy();
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (InvalidKeytermsException $e) use (&$reported): bool {
        $reported[] = $e->getMessage();

        return false;
    });
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
    ArrayKeytermsProvider::$answer = $answer;

    $response = FakeTmesis::keytermsHook()->assertKeptInForce()->deliverTo();

    expect($response->getStatusCode())->toBe(500)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['error' => ['code' => 'invalid_keyterms']]);

    Log::shouldHaveReceived('error')->withArgs(fn (string $line, array $context): bool => str_contains($line, 'would ignore') && str_contains($context['reason'], $message));

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain($message);
})->with([
    'too long' => [[str_repeat('a', 51)], 'keyterms[0] is 51 characters, at most 50'],
    'blank' => [['UFC', " \u{00A0}"], 'keyterms[1] is empty'],
    'a control character' => [["UFC\u{0007}"], 'keyterms[0] carries a control character'],
    'not a string' => [['UFC', 7], 'keyterms[1] is not a string'],
    'not UTF-8' => [["\xff\xfe"], 'keyterms[0] is not valid UTF-8'],
    'too many, duplicates included' => [array_fill(0, 101, 'UFC'), 'at most 100 keyterms, got 101'],
]);

it('answers 503 when the provider throws, never an empty list, and never logs the message', function (): void {
    $this->withKeytermsHookEnabled();
    Log::spy();
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
    ArrayKeytermsProvider::$throws = true;

    $response = FakeTmesis::keytermsHook('show-2041')->assertKeptInForce()->deliverTo();

    expect($response->getStatusCode())->toBe(503)
        ->and((string) $response->getContent())->not->toContain('SECRET_PASSWORD');

    Log::shouldHaveReceived('error')->withArgs(function (string $line, array $context): bool {
        return $context['exception'] === RuntimeException::class
            && $context['stream'] === 'show-2041'
            && ! str_contains((string) json_encode($context), 'SECRET_PASSWORD');
    });
});

it('answers 503 when the configured provider is not one', function (): void {
    $this->withKeytermsHookEnabled();
    config()->set('tmesis.hooks.keyterms.provider', NotADecider::class);

    FakeTmesis::keytermsHook()->assertStatus(503);
});

it('counts characters, not bytes, as the node does', function (): void {
    $this->withKeytermsHookEnabled();
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
    ArrayKeytermsProvider::$answer = [str_repeat('é', 50)];

    FakeTmesis::keytermsHook()->assertKeyterms([str_repeat('é', 50)]);
});
