<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;
use AlexHackney\Tmesis\Keys\FencingEpoch;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\SessionEpoch;

it('assumes a 64 bit int', function (): void {
    expect(PHP_INT_SIZE)->toBe(8);
});

it('parses the decimal form', function (): void {
    expect(KeyEpoch::fromString('0')->value)->toBe(0)
        ->and(KeyEpoch::fromString('7')->toString())->toBe('7')
        ->and(KeyEpoch::fromString('9223372036854775807')->value)->toBe(PHP_INT_MAX);
});

it('refuses a malformed epoch', function (string $value): void {
    expect(fn () => KeyEpoch::fromString($value))->toThrow(MalformedStreamKeyException::class);
})->with([
    'leading zero' => ['07'],
    'explicit sign' => ['+7'],
    'negative' => ['-7'],
    'one over the int64 ceiling' => ['9223372036854775808'],
    'twenty digits' => ['99999999999999999999'],
    'empty' => [''],
    'not a number' => ['seven'],
    'whitespace' => [' 7'],
]);

it('refuses a negative int', function (): void {
    expect(fn () => KeyEpoch::fromInt(-1))->toThrow(MalformedStreamKeyException::class);
});

it('will not let one epoch stand in for another', function (): void {
    // docs/API.md 8.3 names three things "epoch". They have no shared parent and no
    // interface precisely so this is a TypeError rather than a silent authorisation.
    $needsKeyEpoch = static fn (KeyEpoch $epoch): string => $epoch->toString();

    expect($needsKeyEpoch(KeyEpoch::fromInt(7)))->toBe('7');

    /** @phpstan-ignore-next-line intentionally the wrong type */
    expect(fn () => $needsKeyEpoch(SessionEpoch::fromInt(7)))->toThrow(TypeError::class);
    /** @phpstan-ignore-next-line intentionally the wrong type */
    expect(fn () => $needsKeyEpoch(FencingEpoch::fromInt(7)))->toThrow(TypeError::class);

    expect(class_parents(KeyEpoch::class))->toBe([])
        ->and(class_implements(KeyEpoch::class))->toBe([]);
});
