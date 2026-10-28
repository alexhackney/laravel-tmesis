<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\TestCase;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use Illuminate\Support\Carbon;

pest()->group('vectors-webhooks');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array<string, mixed>
 */
function webhookVectors(): array
{
    static $vectors = null;

    return $vectors ??= ContractDir::readJson(ContractDir::vectors('webhooks'));
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function webhookCases(): array
{
    $out = [];

    foreach (webhookVectors()['cases'] as $case) {
        $out[$case['name']] = [$case];
    }

    return $out;
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function webhookNegatives(): array
{
    $out = [];

    foreach (webhookVectors()['negative'] as $case) {
        $out[$case['name']] = [$case];
    }

    return $out;
}

it('is the vector file this package was built against', function (): void {
    $vectors = webhookVectors();

    expect($vectors['version'])->toBe(1)
        ->and($vectors['kind'])->toBe('tmesis.webhook-signature-vectors')
        ->and($vectors['cases'])->toHaveCount(6)
        ->and($vectors['negative'])->toHaveCount(6);
});

it('signs the vector case', function (array $case): void {
    $secret = (string) hex2bin($case['secret_hex']);
    $body = (string) hex2bin($case['body_hex']);

    // Length first: a mismatch here finds the bug before any MAC is computed.
    expect(strlen(WebhookSignature::signedString($case['timestamp'], $body)))
        ->toBe($case['signed_string_length']);

    expect(WebhookSignature::sign($secret, $case['timestamp'], $body))->toBe($case['signature'])
        ->and(WebhookSignature::verify($secret, $case['timestamp'], $body, $case['signature']))->toBeTrue();
})->with(webhookCases());

it('accepts the vector case through the real route', function (array $case): void {
    $secret = (string) hex2bin($case['secret_hex']);
    $body = (string) hex2bin($case['body_hex']);

    // The registry reads config lazily on every call, so resolving it BEFORE the
    // set() below must still see the new secret. Resolve it first on purpose.
    $registry = app(NodeRegistry::class);

    config()->set('tmesis.nodes.'.TestCase::NODE.'.webhook_secret', $secret);

    expect($registry->webhookSecret(TestCase::NODE))->toBe($secret);

    Carbon::setTestNow(Carbon::createFromTimestampUTC((int) $case['timestamp']));

    $response = $this->call('POST', route('tmesis.webhooks.receive'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TMESIS_EVENT' => 'stream.published',
        'HTTP_X_TMESIS_DELIVERY' => '01J8ZC9GQ2M4YE7XW1RN0P6VTB',
        'HTTP_X_TMESIS_TIMESTAMP' => $case['timestamp'],
        'HTTP_X_TMESIS_SIGNATURE' => $case['signature'],
        'HTTP_X_TMESIS_NODE' => TestCase::NODE,
        'HTTP_X_TMESIS_ATTEMPT' => '1',
    ], $body);

    $response->assertNoContent();
})->with(webhookCases());

it('never produces the vector negative', function (array $case): void {
    $vectors = webhookVectors();
    $reference = $vectors['cases'][0];

    $secret = (string) hex2bin($reference['secret_hex']);
    $body = (string) hex2bin($reference['body_hex']);

    expect(WebhookSignature::sign($secret, $reference['timestamp'], $body))
        ->not->toBe($case['signature'], $case['mistake']);
})->with(webhookNegatives());

it('rejects a request carrying the vector negative signature', function (array $case): void {
    $reference = webhookVectors()['cases'][0];
    $body = (string) hex2bin($reference['body_hex']);

    config()->set(
        'tmesis.nodes.'.TestCase::NODE.'.webhook_secret',
        (string) hex2bin($reference['secret_hex'])
    );

    Carbon::setTestNow(Carbon::createFromTimestampUTC((int) $reference['timestamp']));

    $response = $this->call('POST', route('tmesis.webhooks.receive'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TMESIS_EVENT' => 'stream.published',
        'HTTP_X_TMESIS_DELIVERY' => '01J8ZC9GQ2M4YE7XW1RN0P6VTB',
        'HTTP_X_TMESIS_TIMESTAMP' => $reference['timestamp'],
        'HTTP_X_TMESIS_SIGNATURE' => $case['signature'],
        'HTTP_X_TMESIS_NODE' => TestCase::NODE,
        'HTTP_X_TMESIS_ATTEMPT' => '1',
    ], $body);

    $response->assertUnauthorized();
    expect($response->json('error.code'))->toBe('bad_signature');
})->with(webhookNegatives());
