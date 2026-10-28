<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredJobsPause;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Carbon\CarbonImmutable;

/*
 * The told jobs pause in a desired state (tmesis v0.9.0, handover 3.5): the wire key is
 * always present, the pause moves the revision and nothing else does, and the two
 * fields are refused here because a bad one rejects the WHOLE revision at the node.
 */

function pausedDocument(?DesiredJobsPause $pause): DesiredDocument
{
    return DesiredDocument::make()
        ->stream(DesiredStream::make('show-2041-main', DesiredRecording::make(true, 72, null, 'tsp-archive')))
        ->jobsPause($pause);
}

it('always carries jobs_pause on the wire, null when there is none', function (): void {
    $wire = DesiredDocument::make()->toWire(1);

    expect($wire)->toHaveKey('jobs_pause')
        ->and($wire['jobs_pause'])->toBeNull()
        ->and(json_encode($wire))->toEndWith(',"open_posture":false,"jobs_pause":null}');
});

it('encodes a pause as until and reason, and nothing else', function (): void {
    $wire = pausedDocument(DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z'))->toWire(4);

    expect($wire['jobs_pause'])->toBe(['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12']);

    $open = pausedDocument(DesiredJobsPause::make('until cleared'))->toWire(4);

    expect($open['jobs_pause'])->toBe(['until' => null, 'reason' => 'until cleared']);
});

it('validates against both desired-state schemas with and without a pause', function (?DesiredJobsPause $pause): void {
    $desired = json_decode((string) json_encode(pausedDocument($pause)->toWire(7)), false);

    SchemaValidator::forSchema('fleet.heartbeat.response')->assertValid(
        (object) ['ack_event_ids' => [], 'desired_state' => $desired, 'commands' => []],
        context: 'A heartbeat answer carrying jobs_pause',
    );
})->with([
    'none' => [null],
    'until a time' => [fn () => DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z')],
    'until cleared' => [fn () => DesiredJobsPause::make('show: fight night 12')],
]);

it('keeps the pre-v0.9.0 canonical form for a document with no pause, so an upgrade bumps no node', function (): void {
    expect(DesiredDocument::make()->canonical())->toBe('{"streams":[],"revocations":[],"open_posture":false}')
        ->and(pausedDocument(null)->canonical())->not->toContain('jobs_pause');
});

it('moves the canonical form when the pause is set, changed or dropped, and only then', function (): void {
    $none = pausedDocument(null)->canonical();
    $pause = pausedDocument(DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z'))->canonical();

    expect($pause)->not->toBe($none)
        ->and(pausedDocument(DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z'))->canonical())->toBe($pause)
        ->and(pausedDocument(DesiredJobsPause::make('show: fight night 13', '2026-08-28T19:00:00Z'))->canonical())->not->toBe($pause)
        ->and(pausedDocument(DesiredJobsPause::make('show: fight night 12', '2026-08-28T20:00:00Z'))->canonical())->not->toBe($pause)
        ->and(pausedDocument(DesiredJobsPause::make('show: fight night 12'))->canonical())->not->toBe($pause)
        ->and(pausedDocument(DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z'))->jobsPause(null)->canonical())->toBe($none);
});

it('answers the pause it was given', function (): void {
    $pause = DesiredJobsPause::make('show');

    expect(DesiredDocument::make()->desiredJobsPause())->toBeNull()
        ->and(DesiredDocument::make()->jobsPause($pause)->desiredJobsPause())->toBe($pause);
});

it('counts the reason in BYTES, 1 to 200', function (): void {
    expect(DesiredJobsPause::make(str_repeat('a', 200))->reason)->toHaveLength(200)
        ->and(DesiredJobsPause::make('x')->reason)->toBe('x')
        // 66 three-byte characters are 198 bytes; one more is 201.
        ->and(DesiredJobsPause::make(str_repeat('€', 66))->reason)->toBe(str_repeat('€', 66))
        ->and(fn () => DesiredJobsPause::make(str_repeat('€', 67)))->toThrow(InvalidConfigurationException::class, 'must be 1 to 200 bytes, got 201')
        // 50 four-byte emoji are exactly 200 bytes, though only 50 characters.
        ->and(DesiredJobsPause::make(str_repeat('🎬', 50))->reason)->toBe(str_repeat('🎬', 50))
        ->and(fn () => DesiredJobsPause::make(str_repeat('a', 201)))->toThrow(InvalidConfigurationException::class, 'jobs_pause.reason')
        ->and(fn () => DesiredJobsPause::make(''))->toThrow(InvalidConfigurationException::class, 'got 0');
});

it('refuses a reason that is not UTF-8, which would not encode', function (): void {
    expect(fn () => DesiredJobsPause::make("show \xFF"))->toThrow(InvalidConfigurationException::class, 'must be valid UTF-8');
});

it('takes until as an RFC 3339 UTC string', function (string $until): void {
    expect(DesiredJobsPause::make('show', $until)->until)->toBe($until);
})->with([
    'whole seconds' => ['2026-08-28T19:00:00Z'],
    'milliseconds' => ['2026-08-28T19:00:00.409Z'],
    'nanoseconds' => ['2026-08-28T19:00:00.123456789Z'],
    'a time already past (accepted, simply inactive)' => ['2001-01-01T00:00:00Z'],
    'a leap day' => ['2028-02-29T23:59:59Z'],
]);

it('refuses an until that is not RFC 3339 UTC', function (string $until): void {
    expect(fn () => DesiredJobsPause::make('show', $until))->toThrow(InvalidConfigurationException::class, 'jobs_pause.until');
})->with([
    'empty' => [''],
    'an offset' => ['2026-08-28T19:00:00+00:00'],
    'no zone' => ['2026-08-28T19:00:00'],
    'a date' => ['2026-08-28'],
    'a space' => ['2026-08-28 19:00:00Z'],
    'lowercase z' => ['2026-08-28T19:00:00z'],
    'an impossible date' => ['2026-02-30T19:00:00Z'],
    'hour 24' => ['2026-08-28T24:00:00Z'],
    'a trailing newline' => ["2026-08-28T19:00:00Z\n"],
    'words' => ['tomorrow'],
]);

it('converts a DateTimeInterface to UTC', function (): void {
    $chicago = CarbonImmutable::parse('2026-08-28 14:00:00', 'America/Chicago');

    expect(DesiredJobsPause::make('show', $chicago)->until)->toBe('2026-08-28T19:00:00Z')
        ->and(DesiredJobsPause::make('show', new DateTimeImmutable('2026-08-28T19:00:00.409+00:00'))->until)->toBe('2026-08-28T19:00:00.409000Z')
        ->and(DesiredJobsPause::make('show')->until)->toBeNull();
});
