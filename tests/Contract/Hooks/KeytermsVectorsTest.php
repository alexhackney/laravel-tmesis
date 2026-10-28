<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;
use AlexHackney\Tmesis\Support\Keyterms;
use AlexHackney\Tmesis\Testing\FakeTmesis;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Tests\Doubles\ArrayKeytermsProvider;

pest()->group('vectors-keyterms');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

beforeEach(function (): void {
    ArrayKeytermsProvider::reset();

    $this->withKeytermsHookEnabled();
    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);
});

/**
 * `test/vectors/keyterms.json` (tmesis v0.8.0): worked keyterms hook RESPONSES, with the
 * list in force after each. Hand-written upstream like hook.json, because the control
 * plane authors these bytes; the node's Go test serves each through a real
 * KeytermsHook.Fetch, and this leg serves each through the package's route.
 *
 * @return array<string, mixed>
 */
function keytermsVectors(): array
{
    static $vectors = null;

    return $vectors ??= ContractDir::readJson(ContractDir::vectors('keyterms'));
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function keytermsVectorCases(): array
{
    $out = [];

    foreach (keytermsVectors()['responses'] as $case) {
        $out[$case['name']] = [$case];
    }

    return $out;
}

/**
 * What the node does with an answer (`internal/webhook/keytermshook.go` Fetch, then
 * `captions.NormalizeKeyterms`): the list in force afterwards, or null when the answer
 * is a failure and the previous list stays. `204` and `404` are none; another non-2xx
 * is a failure; a 2xx body that does not decode is a failure; a decoded body with no
 * `keyterms` is none; a list that breaks a rule is a failure, never applied in part.
 *
 * @return list<string>|null
 */
function nodeReadsKeyterms(int $status, ?string $body): ?array
{
    if ($status === 204 || $status === 404) {
        return [];
    }

    if ($status < 200 || $status >= 300) {
        return null;
    }

    $decoded = json_decode((string) $body, true);

    if (! is_array($decoded)) {
        return null;
    }

    $terms = $decoded['keyterms'] ?? [];

    if (! is_array($terms)) {
        return null;
    }

    return Keyterms::normalize($terms);
}

it('is the keyterms vector file this package was built against', function (): void {
    $vectors = keytermsVectors();

    expect($vectors)->toHaveKeys(['_comment', 'responses'])
        ->and($vectors['responses'])->toHaveCount(4);

    // A row with a body names the response schema; a 204 or 404 carries none.
    foreach ($vectors['responses'] as $case) {
        expect($case)->toHaveKeys(['name', 'status', 'keyterms']);

        if (array_key_exists('body', $case)) {
            expect($case['schema'])->toBe(KeytermsHookRequest::RESPONSE_SCHEMA);
        } else {
            expect($case['status'])->toBeIn([204, 404]);
        }
    }
});

it('validates every vector body against keyterms.hook.response', function (array $case): void {
    if (! array_key_exists('body', $case)) {
        expect($case['status'])->toBeIn([204, 404]);

        return;
    }

    $body = json_decode((string) json_encode($case['body']), false, 512, JSON_THROW_ON_ERROR);

    SchemaValidator::forSchema(KeytermsHookRequest::RESPONSE_SCHEMA)->assertValid($body, context: "The [{$case['name']}] vector");
})->with(keytermsVectorCases());

it('reads every vector the way the node does', function (array $case): void {
    $body = array_key_exists('body', $case) ? (string) json_encode($case['body']) : null;

    expect(nodeReadsKeyterms($case['status'], $body))->toBe($case['keyterms']);
})->with(keytermsVectorCases());

it('reproduces every vector\'s list in force through the route, with an answer that validates', function (array $case): void {
    // A 204 or 404 vector is none; the route says none as 200 {"keyterms": []}, which
    // the node reads the same way.
    ArrayKeytermsProvider::$answer = $case['body']['keyterms'] ?? [];

    $response = FakeTmesis::keytermsHook('show-2041')->deliverTo();
    $content = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(200)
        ->and(nodeReadsKeyterms($response->getStatusCode(), $content))->toBe($case['keyterms']);

    SchemaValidator::forSchema(KeytermsHookRequest::RESPONSE_SCHEMA)
        ->assertValid(json_decode($content, false, 512, JSON_THROW_ON_ERROR), context: "The route's answer for [{$case['name']}]");

    if (array_key_exists('body', $case)) {
        expect(json_decode($content, true))->toBe($case['body']);
    }
})->with(keytermsVectorCases());

it('never answers anything the node would apply when the provider fails or breaks a rule', function (): void {
    ArrayKeytermsProvider::$throws = true;
    $failed = FakeTmesis::keytermsHook()->deliverTo();

    ArrayKeytermsProvider::reset();
    ArrayKeytermsProvider::$answer = [str_repeat('x', 51)];
    $invalid = FakeTmesis::keytermsHook()->deliverTo();

    // Null is "the list in force stays": the node never reads either as none.
    expect(nodeReadsKeyterms($failed->getStatusCode(), (string) $failed->getContent()))->toBeNull()
        ->and(nodeReadsKeyterms($invalid->getStatusCode(), (string) $invalid->getContent()))->toBeNull();
});
