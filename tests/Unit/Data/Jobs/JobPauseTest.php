<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobPauseResponse;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

/*
 * The told jobs pause bodies (tmesis v0.9.0, handover 3.5, API.md 9.12a).
 */

it('renders a pause with an end as until and reason, always both', function (): void {
    $pause = JobPauseRequest::make('show: fight night 12', new DateTimeImmutable('2026-08-28T15:00:00-04:00'));

    // UTC to the second, as the node echoes it; a fraction only when the time has one.
    expect($pause->toArray())->toBe(['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12'])
        ->and($pause->until())->toBe('2026-08-28T19:00:00Z')
        ->and(JobPauseRequest::make('x', new DateTimeImmutable('2026-08-28T19:00:00.409Z'))->until())->toBe('2026-08-28T19:00:00.409000Z')
        ->and($pause->reason())->toBe('show: fight night 12');
});

it('sends until as null, never omitted, for a pause until cleared', function (): void {
    // `until` is a REQUIRED key: absent is refused by the schema, null is "until DELETE".
    $pause = JobPauseRequest::untilCleared('maintenance');

    expect($pause->toArray())->toBe(['until' => null, 'reason' => 'maintenance'])
        ->and(array_key_exists('until', $pause->toArray()))->toBeTrue()
        ->and(JobPauseRequest::make('x')->until())->toBeNull();
});

it('passes an RFC 3339 string through as given, any offset, as the node reads it', function (string $until): void {
    expect(JobPauseRequest::make('x', $until)->until())->toBe($until);
})->with([
    'Z' => '2026-08-28T19:00:00Z',
    'fraction' => '2026-08-28T19:00:00.409Z',
    'offset' => '2026-08-28T15:00:00-04:00',
    // A past time is accepted and simply inactive at the node: grammar only here.
    'past' => '2001-01-01T00:00:00Z',
]);

it('refuses an until that is not RFC 3339, with the node\'s sentence', function (string $until): void {
    expect(fn () => JobPauseRequest::make('x', $until))
        ->toThrow(InvalidConfigurationException::class, 'The config key [until] is invalid: must be an RFC 3339 time (2026-08-28T19:00:00Z), or null.');
})->with([
    'empty' => '',
    'date only' => '2026-08-28',
    'space' => '2026-08-28 19:00:00Z',
    'no zone' => '2026-08-28T19:00:00',
    'lower t' => '2026-08-28t19:00:00Z',
    'trailing newline' => "2026-08-28T19:00:00Z\n",
    // In grammar but out of range: Go's time.Parse refuses each, so the node's 422.
    'February 30' => '2026-02-30T19:00:00Z',
    'hour 25' => '2026-08-28T25:00:00Z',
    'minute 61' => '2026-08-28T19:61:00Z',
    'second 61' => '2026-08-28T19:00:61Z',
    'all three' => '2026-02-30T25:61:61Z',
    'offset hour 24' => '2026-08-28T19:00:00+24:00',
    'offset minute 60' => '2026-08-28T19:00:00+05:60',
]);

it('accepts February 29 in a leap year only', function (): void {
    expect(JobPauseRequest::make('x', '2028-02-29T00:00:00Z')->until())->toBe('2028-02-29T00:00:00Z')
        ->and(fn () => JobPauseRequest::make('x', '2026-02-29T00:00:00Z'))->toThrow(InvalidConfigurationException::class);
});

it('refuses a reason that is not valid UTF-8, before the body is encoded', function (): void {
    expect(fn () => JobPauseRequest::make("show \xC3\x28"))
        ->toThrow(InvalidConfigurationException::class, 'The config key [reason] is invalid: must be valid UTF-8.');
});

it('counts the reason in BYTES, 1 to 200', function (): void {
    expect(JobPauseRequest::make('x')->reason())->toBe('x')
        ->and(strlen(JobPauseRequest::make(str_repeat('a', 200))->reason()))->toBe(200)
        // 50 four-byte characters are exactly 200 bytes.
        ->and(strlen(JobPauseRequest::make(str_repeat("\u{1F3AC}", 50))->reason()))->toBe(200)
        ->and(JobPauseRequest::MAX_REASON_BYTES)->toBe(200);
});

it('refuses an empty reason and one over 200 bytes, multibyte included', function (string $reason): void {
    expect(fn () => JobPauseRequest::make($reason))
        ->toThrow(InvalidConfigurationException::class, 'The config key [reason] is invalid: must be 1 to 200 bytes');
})->with([
    'empty' => '',
    '201 ascii bytes' => str_repeat('a', 201),
    // 67 three-byte characters: 67 characters, 201 bytes.
    '67 characters, 201 bytes' => str_repeat("\u{20AC}", 67),
    '51 emoji, 204 bytes' => str_repeat("\u{1F3AC}", 51),
]);

it('validates every body it can render against the JobPauseRequest component', function (): void {
    $validator = SchemaValidator::forOpenApi();

    foreach ([
        JobPauseRequest::make('show: fight night 12', '2026-08-28T19:00:00Z'),
        JobPauseRequest::untilCleared(str_repeat("\u{1F3AC}", 50)),
    ] as $pause) {
        $validator->assertValid(json_decode((string) json_encode($pause->toArray()), false), '/components/schemas/JobPauseRequest', 'The pause body');
    }
});

it('reads the active shape with every field', function (): void {
    $pause = JobPauseResponse::fromArray($this->fixtureJson('responses/setTranscodePause.json'));

    expect($pause->paused())->toBeTrue()
        ->and($pause->untilRaw())->toBe('2026-08-28T19:00:00Z')
        ->and($pause->until()?->format('Y-m-d H:i:s'))->toBe('2026-08-28 19:00:00')
        ->and($pause->reason())->toBe('show: fight night 12')
        ->and($pause->source())->toBe(JobPauseResponse::SOURCE_API)
        ->and($pause->setAtRaw())->toBe('2026-08-28T12:00:00.409Z')
        ->and($pause->setAt()?->format('Y-m-d H:i:s.v'))->toBe('2026-08-28 12:00:00.409')
        ->and($pause->toArray())->toBe($this->fixtureJson('responses/setTranscodePause.json'));
});

it('reads a null until as a pause until cleared', function (): void {
    $pause = JobPauseResponse::fromArray($this->fixtureJson('responses/getTranscodePause.json'));

    expect($pause->paused())->toBeTrue()
        ->and($pause->untilRaw())->toBeNull()
        ->and($pause->until())->toBeNull()
        ->and($pause->source())->toBe(JobPauseResponse::SOURCE_FLEET);
});

it('reads exactly {paused: false} as inactive, every other field null', function (): void {
    $pause = JobPauseResponse::fromArray(['paused' => false]);

    expect($pause->paused())->toBeFalse()
        ->and($pause->until())->toBeNull()
        ->and($pause->reason())->toBeNull()
        ->and($pause->source())->toBeNull()
        ->and($pause->setAt())->toBeNull()
        ->and($pause->toArray())->toBe(['paused' => false])
        // An empty or malformed body is not a pause.
        ->and(JobPauseResponse::fromArray([])->paused())->toBeFalse()
        ->and(JobPauseResponse::fromArray(['paused' => 'true'])->paused())->toBeFalse();
});

it('tolerates a field a newer node adds', function (): void {
    $pause = JobPauseResponse::fromArray(['paused' => true, 'until' => null, 'reason' => 'x', 'source' => 'api', 'set_at' => '2026-08-28T12:00:00Z', 'set_by' => 'ops']);

    expect($pause->paused())->toBeTrue()
        ->and($pause->toArray()['set_by'])->toBe('ops');
});

it('holds both response shapes and the fixtures to the JobPauseResponse component', function (): void {
    $validator = SchemaValidator::forOpenApi();

    foreach (['responses/setTranscodePause.json', 'responses/getTranscodePause.json'] as $fixture) {
        $validator->assertValid(json_decode((string) json_encode($this->fixtureJson($fixture)), false), '/components/schemas/JobPauseResponse', $fixture);
    }

    $validator->assertValid((object) ['paused' => false], '/components/schemas/JobPauseResponse', 'The inactive shape');
});

it('holds SOURCES equal to the schema enum', function (): void {
    /** @var array<string, mixed> $component */
    $component = ContractDir::readJson(ContractDir::openapi())['components']['schemas']['JobPauseResponse'];

    expect(JobPauseResponse::SOURCES)->toEqualCanonicalizing($component['properties']['source']['enum'])
        ->and($component['required'])->toBe(['paused']);
});
