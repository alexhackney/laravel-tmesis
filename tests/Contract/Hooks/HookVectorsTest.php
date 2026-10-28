<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

pest()->group('vectors-hook');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/**
 * `test/vectors/hook.json` (tmesis v0.6.0): worked publish.verify RESPONSES. Hand-written
 * upstream like fleet.json, because the control plane authors these bytes; the node's
 * Go tests hold it to the node's decoder, and this leg holds it to the package's
 * encoder. Unsigned on purpose: a hook response is authenticated by the TLS connection
 * the node opened, not by a body signature.
 *
 * @return array<string, mixed>
 */
function hookVectors(): array
{
    static $vectors = null;

    return $vectors ??= ContractDir::readJson(ContractDir::vectors('hook'));
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function hookVectorCases(): array
{
    $out = [];

    foreach (hookVectors()['responses'] as $case) {
        $out[$case['name']] = [$case];
    }

    return $out;
}

/**
 * How the package produces each vector body, by vector name. A decider writes these,
 * so they go through the public builders: `allow(patch: StreamSpec)` where the body
 * carries labels or relays, the plain factories otherwise. A vector with no entry
 * here fails the next test naming it, which is the point: a body the builder cannot
 * produce is a finding, not a skip.
 *
 * @return array<string, Closure(): HookDecision>
 */
function hookVectorBuilders(): array
{
    return [
        'allow, provisioning labels and the origin relay' => static fn (): HookDecision => HookDecision::allow(
            patch: StreamSpec::make()
                ->labels(['tenant' => 'acme', 'show' => '2041'])
                ->relays([RelayInput::make('rtmp://origin-atl-01.example.com:1935/src', 'origin')]),
        ),
        'allow with no patch' => static fn (): HookDecision => HookDecision::allow(),
        'deny with a reason' => static fn (): HookDecision => HookDecision::deny('show 2041 was cancelled'),
    ];
}

/**
 * Recursively sort string keys, so two bodies compare equal key order aside. Lists
 * keep their order: relay order is meaningful.
 */
function hookCanonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(hookCanonical(...), $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

it('is the hook vector file this package was built against', function (): void {
    $vectors = hookVectors();

    expect($vectors)->toHaveKeys(['_comment', 'responses'])
        ->and($vectors['responses'])->toBeArray()->not->toBeEmpty();

    // Every row names the response schema; a row for another schema would need a
    // leg of its own rather than being validated against the wrong one.
    foreach ($vectors['responses'] as $case) {
        expect($case)->toHaveKeys(['name', 'schema', 'body'])
            ->and($case['schema'])->toBe('publish.verify.response');
    }
});

it('has a builder for every vector and a vector for every builder', function (): void {
    $names = array_keys(hookVectorCases());
    $built = array_keys(hookVectorBuilders());

    sort($names);
    sort($built);

    expect($built)->toBe($names);
});

it('validates every vector body against publish.verify.response', function (array $case): void {
    $body = json_decode((string) json_encode($case['body']), false, 512, JSON_THROW_ON_ERROR);

    SchemaValidator::forSchema('publish.verify.response')->assertValid($body, context: "The [{$case['name']}] vector");

    // The response schema leaves `patch` untyped (its description says "the PATCH
    // /v1/streams/{id} body (StreamSpec), unknown fields refused"), so the patch is
    // held to the StreamSpec component here, which is what the node decodes it into.
    if (property_exists($body, 'patch')) {
        SchemaValidator::forOpenApi()->assertValid($body->patch, '/components/schemas/StreamSpec', "The [{$case['name']}] vector's patch");
    }
})->with(hookVectorCases());

it('reproduces every vector body through HookDecision, key order aside', function (array $case): void {
    $builders = hookVectorBuilders();

    expect($builders)->toHaveKey($case['name']);

    $decision = $builders[$case['name']]();

    expect(hookCanonical($decision->toArray()))->toBe(hookCanonical($case['body']));

    // And the JSON the endpoint writes decodes to the same document, objects kept as
    // objects: `labels` must reach the node as a map.
    expect(json_decode((string) json_encode($decision->toArray()), false))
        ->toEqual(json_decode((string) json_encode($case['body']), false));
})->with(hookVectorCases());

it('reproduces the generated example, which is the first vector rendered', function (): void {
    $example = ContractDir::readJson(ContractDir::example('publish.verify.response'));
    $first = hookVectors()['responses'][0];

    expect(hookCanonical($example))->toBe(hookCanonical($first['body']))
        ->and(hookCanonical(hookVectorBuilders()[$first['name']]()->toArray()))->toBe(hookCanonical($example));
});
