<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Support\Rfc3339;

it('parses the contract timestamp forms', function (string $value, string $expected): void {
    expect(Rfc3339::parse($value)->format('Y-m-d\TH:i:s.u'))->toBe($expected);
})->with([
    'no fraction' => ['2026-08-28T19:12:01Z', '2026-08-28T19:12:01.000000'],
    'milliseconds' => ['2026-08-28T19:12:01.482Z', '2026-08-28T19:12:01.482000'],
    'microseconds' => ['2026-08-28T19:12:01.482123Z', '2026-08-28T19:12:01.482123'],
    'nanoseconds truncated' => ['2026-08-28T19:12:01.482123456Z', '2026-08-28T19:12:01.482123'],
    'explicit zero offset' => ['2026-08-28T19:12:01.482+00:00', '2026-08-28T19:12:01.482000'],
]);

it('refuses anything that is not UTC', function (string $value): void {
    // The contract says UTC. Accepting +01:00 would let a wrong-zone timestamp
    // through as a right one.
    expect(fn () => Rfc3339::parse($value))->toThrow(InvalidArgumentException::class);
})->with([
    '+01:00',
    '2026-08-28T19:12:01+01:00',
    '2026-08-28 19:12:01Z',
    '2026-08-28T19:12:01',
    '',
]);

it('formats with millisecond precision and a Z', function (): void {
    $at = new DateTimeImmutable('2026-08-28T19:12:01.482123Z');

    expect(Rfc3339::format($at))->toBe('2026-08-28T19:12:01.482Z');
});
