<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;
use AlexHackney\Tmesis\Exceptions\StreamKeySignatureException;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\StreamKey;
use AlexHackney\Tmesis\Keys\StreamKeyMinter;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

pest()->group('vectors-keys');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/**
 * @return array<string, mixed>
 */
function keysVectors(): array
{
    static $vectors = null;

    return $vectors ??= ContractDir::readJson(ContractDir::vectors('keys'));
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function keysCases(): array
{
    $out = [];

    foreach (keysVectors()['cases'] as $case) {
        $out[$case['name']] = [$case];
    }

    return $out;
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function keysNegatives(): array
{
    $out = [];

    foreach (keysVectors()['negative'] as $case) {
        $out[$case['name']] = [$case];
    }

    return $out;
}

it('is the vector file this package was built against', function (): void {
    $vectors = keysVectors();

    expect($vectors['version'])->toBe(1)
        ->and($vectors['kind'])->toBe('tmesis.stream-key-vectors')
        // Per row, so a truncated copy cannot pass by having fewer rows to satisfy.
        ->and($vectors['cases'])->toHaveCount(12)
        ->and($vectors['negative'])->toHaveCount(29)
        ->and(PHP_INT_SIZE)->toBe(8, 'The epoch ceiling check assumes a 64 bit int.');
});

it('mints the vector key', function (array $case): void {
    $secret = hex2bin($case['secret_hex']);
    expect($secret)->toBeString();

    $epoch = KeyEpoch::fromString($case['epoch_string']);
    $key = app(StreamKeyMinter::class)->mint($case['stream'], $epoch, (string) $secret);

    expect($key->signedString())->toBe($case['signed'])
        ->and($key->mac)->toBe($case['mac'])
        ->and($key->toString())->toBe($case['key']);
})->with(keysCases());

it('refuses the vector negative', function (array $case): void {
    if ($case['error'] === 'malformed') {
        expect(fn () => StreamKey::parse($case['key']))
            ->toThrow(MalformedStreamKeyException::class);

        return;
    }

    // bad_signature rows parse cleanly and fail only against the secret.
    $key = StreamKey::parse($case['key']);
    $secret = (string) hex2bin($case['secret_hex']);

    expect(fn () => app(StreamKeyMinter::class)->verify($key, $secret))
        ->toThrow(StreamKeySignatureException::class);
})->with(keysNegatives());
