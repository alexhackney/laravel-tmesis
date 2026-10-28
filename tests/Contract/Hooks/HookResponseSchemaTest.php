<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Testing\PendingHook;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Tests\Doubles\RecordingDecider;
use AlexHackney\Tmesis\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

pest()->group('hook-endpoint');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

beforeEach(function (): void {
    RecordingDecider::reset();
});

/**
 * Every shape a decider can answer with (tmesis v0.6.0 made the response contract:
 * `publish.verify.response.schema.json`, WEBHOOKS.md 12.4).
 *
 * @return array<string, array{0: Closure(): HookDecision}>
 */
function hookDecisionShapes(): array
{
    return [
        'deny with a reason' => [static fn (): HookDecision => HookDecision::deny('show 2041 was cancelled')],
        'deny bare' => [static fn (): HookDecision => HookDecision::deny()],
        'allow bare' => [static fn (): HookDecision => HookDecision::allow()],
        'allow(false) with a reason' => [static fn (): HookDecision => HookDecision::allow(false, 'not tonight')],
        'allow with a StreamSpec patch, labels and one relay' => [static fn (): HookDecision => HookDecision::allow(
            patch: StreamSpec::make()
                ->labels(['tenant' => 'acme', 'show' => '2041'])
                ->relays([RelayInput::make('rtmp://origin-atl-01.example.com:1935/src/secret-key', 'origin')]),
        )],
        'allow with an emptied label set' => [static fn (): HookDecision => HookDecision::allow(patch: StreamSpec::make()->labels([]))],
        'allow with an array patch' => [static fn (): HookDecision => HookDecision::allow(patch: ['labels' => ['show' => '2041']])],
    ];
}

it('validates every HookDecision shape against publish.verify.response', function (Closure $make): void {
    $decision = $make();

    // Through the JSON the endpoint writes, decoded to objects, so an empty map is
    // checked as the node sees it.
    $body = json_decode((string) json_encode($decision->toArray()), false, 512, JSON_THROW_ON_ERROR);

    SchemaValidator::forSchema('publish.verify.response')->assertValid($body, context: 'The decision');

    // `patch` is untyped in the response schema; its description makes it the
    // StreamSpec PATCH body, so that is what it is held to.
    if (property_exists($body, 'patch')) {
        SchemaValidator::forOpenApi()->assertValid($body->patch, '/components/schemas/StreamSpec', 'The patch');
    }
})->with(hookDecisionShapes());

it('validates the generated publish.verify.response example', function (): void {
    $json = (string) file_get_contents(ContractDir::example('publish.verify.response'));

    SchemaValidator::forSchema('publish.verify.response')->assertValid(json_decode($json, false), context: 'The example');
});

it('refuses a body with no allow, and a field the schema does not name', function (): void {
    $validator = SchemaValidator::forSchema('publish.verify.response');

    // The two ways a hand-rolled answer goes wrong. HookDecision can produce neither.
    expect($validator->isValid((object) ['reason' => 'x']))->toBeFalse()
        ->and($validator->isValid((object) ['allow' => true, 'note' => 'x']))->toBeFalse();
});

it('validates what PendingHook::decision() decodes', function (Closure $make): void {
    /** @var TestCase $this */
    $this->withHooksEnabled();
    config()->set('tmesis.hooks.decider', RecordingDecider::class);
    RecordingDecider::$answer = $make();

    // Compared through JSON: decision() decodes to arrays, where an emptied label
    // set is [] however it went out.
    expect((new PendingHook)->decision())
        ->toBe(json_decode((string) json_encode($make()->toArray()), true));
})->with(hookDecisionShapes());

it('fails a consumer test when the answer is not a valid hook response', function (): void {
    // Hooks stay disabled, so the package registers no route and this one takes the
    // name: an endpoint answering with a body the node would read as a deny.
    // PendingHook is what a consumer's test drives, so this is the check a consumer
    // that answers the hook from its own controller gets for free.
    app('router')->post('tmesis-test/hooks/publish', static fn () => response()->json(['allowed' => true]))
        ->name('tmesis.hooks.publish');
    app('router')->getRoutes()->refreshNameLookups();

    expect(fn () => (new PendingHook)->decision())
        ->toThrow(AssertionFailedError::class, 'publish.verify.response');
});
