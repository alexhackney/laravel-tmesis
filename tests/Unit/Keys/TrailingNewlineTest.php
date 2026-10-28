<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;
use AlexHackney\Tmesis\Keys\FencingEpoch;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\SessionEpoch;
use AlexHackney\Tmesis\Keys\StreamId;
use AlexHackney\Tmesis\Keys\StreamKey;
use AlexHackney\Tmesis\Support\Rfc3339;

// `$` in a PCRE pattern also matches before a final "\n", so every identifier here is
// anchored with `\z`: "live\n" is not a stream id and "1\n" is not epoch 1.

it('refuses a stream id with a trailing newline', function (): void {
    expect(StreamId::isValid("live\n"))->toBeFalse()
        ->and(StreamId::isValid('live'))->toBeTrue()
        ->and(fn () => StreamId::assert("live\n"))->toThrow(MalformedStreamKeyException::class);
});

it('refuses an epoch with a trailing newline', function (string $class): void {
    expect(fn () => $class::fromString("1\n"))->toThrow(MalformedStreamKeyException::class)
        ->and($class::fromString('1')->value)->toBe(1);
})->with([KeyEpoch::class, SessionEpoch::class, FencingEpoch::class]);

it('refuses a stream key whose mac carries a trailing newline', function (): void {
    expect(fn () => StreamKey::parse('live.1.'.str_repeat('a', 64)."\n"))
        ->toThrow(MalformedStreamKeyException::class);
});

it('refuses a timestamp with a trailing newline', function (): void {
    expect(fn () => Rfc3339::parse("2026-09-26T12:00:00Z\n"))->toThrow(InvalidArgumentException::class);
});
