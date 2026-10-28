<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthTranscription;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

it('reads every transcription field, including nullable timestamps and detail', function (): void {
    $entry = HealthTranscription::fromArray([
        'role' => 'live', 'provider' => 'deepgram', 'endpoint' => 'https://api.deepgram.com/v1/listen',
        'state' => 'down', 'since' => '2026-10-20T12:00:00Z', 'unavailable' => true, 'detail' => 'connection refused',
    ]);

    expect($entry->role())->toBe('live')
        ->and($entry->provider())->toBe('deepgram')
        ->and($entry->endpoint())->toBe('https://api.deepgram.com/v1/listen')
        ->and($entry->state())->toBe('down')
        ->and($entry->sinceRaw())->toBe('2026-10-20T12:00:00Z')
        ->and($entry->since()?->format(DATE_ATOM))->toBe('2026-10-20T12:00:00+00:00')
        ->and($entry->unavailable())->toBeTrue()
        ->and($entry->detail())->toBe('connection refused')
        ->and($entry->toArray()['role'])->toBe('live');
});

it('reads nullable since and detail as null', function (): void {
    $entry = HealthTranscription::fromArray(['since' => null, 'detail' => null]);

    expect($entry->sinceRaw())->toBeNull()
        ->and($entry->since())->toBeNull()
        ->and($entry->detail())->toBeNull()
        ->and($entry->unavailable())->toBeFalse();
});

it('refuses a since that is not RFC3339 UTC, and keeps the raw value', function (string $since): void {
    $entry = HealthTranscription::fromArray(['since' => $since]);

    expect($entry->sinceRaw())->toBe($since)
        ->and(fn () => $entry->since())->toThrow(InvalidArgumentException::class);
})->with([
    'not a timestamp' => ['yesterday'],
    'a nonzero offset' => ['2026-10-20T12:00:00+02:00'],
]);

it('holds role and state constants equal to the schema enums', function (): void {
    /** @var array<string, mixed> $document */
    $document = ContractDir::readJson(ContractDir::openapi());
    $schema = $document['components']['schemas']['HealthTranscription']['properties'];

    expect(HealthTranscription::ROLES)->toEqualCanonicalizing($schema['role']['enum'])
        ->and(HealthTranscription::STATES)->toEqualCanonicalizing($schema['state']['enum'])
        ->and(HealthTranscription::ROLE_FALLBACK)->toBe('fallback');
});
