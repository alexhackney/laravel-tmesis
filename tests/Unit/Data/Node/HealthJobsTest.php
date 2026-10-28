<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthJobs;
use AlexHackney\Tmesis\Data\Node\HealthJobsPause;
use AlexHackney\Tmesis\Support\Rfc3339;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

/*
 * Health `jobs.paused` (tmesis v0.9.0, handover 3.5): REQUIRED when jobs is an
 * object, null when no pause is active.
 */

it('reads an unpaused queue', function (): void {
    $jobs = HealthFull::fromArray($this->fixtureJson('responses/getHealth.json'))->jobs();

    expect($jobs)->toBeInstanceOf(HealthJobs::class)
        ->and($jobs?->paused())->toBeNull()
        ->and($jobs?->isPaused())->toBeFalse()
        ->and($jobs?->hasFreeSlot())->toBeTrue();
});

it('reads an absent paused as no pause', function (): void {
    $jobs = HealthJobs::fromArray(['queued' => 0, 'running' => 0, 'slots' => 1]);

    expect($jobs->paused())->toBeNull()
        ->and($jobs->isPaused())->toBeFalse();
});

it('reads a fleet pause with an end', function (): void {
    $jobs = HealthJobs::fromArray([
        'queued' => 2,
        'running' => 0,
        'slots' => 4,
        'paused' => ['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12', 'source' => 'fleet'],
    ]);

    $pause = $jobs->paused();

    expect($jobs->isPaused())->toBeTrue()
        ->and($jobs->hasFreeSlot())->toBeTrue()
        ->and($pause)->toBeInstanceOf(HealthJobsPause::class)
        ->and($pause?->untilRaw())->toBe('2026-08-28T19:00:00Z')
        ->and($pause?->until())->toEqual(Rfc3339::parse('2026-08-28T19:00:00Z'))
        ->and($pause?->reason())->toBe('show: fight night 12')
        ->and($pause?->source())->toBe(HealthJobsPause::SOURCE_FLEET)
        ->and($pause?->toArray())->toBe(['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12', 'source' => 'fleet']);
});

it('reads an api pause held until cleared', function (): void {
    $pause = HealthJobsPause::fromArray(['until' => null, 'reason' => 'maintenance', 'source' => 'api']);

    expect($pause->untilRaw())->toBeNull()
        ->and($pause->until())->toBeNull()
        ->and($pause->source())->toBe(HealthJobsPause::SOURCE_API);
});

it('decodes an empty pause to empty values', function (): void {
    $pause = HealthJobsPause::fromArray([]);

    expect($pause->until())->toBeNull()
        ->and($pause->reason())->toBe('')
        ->and($pause->source())->toBe('');
});

it('refuses an until that is not RFC3339 UTC', function (): void {
    HealthJobsPause::fromArray(['until' => '2026-08-28T19:00:00-05:00', 'reason' => 'r', 'source' => 'api'])->until();
})->throws(InvalidArgumentException::class);

it('holds the source constants equal to the schema enum', function (): void {
    /** @var array<string, mixed> $document */
    $document = ContractDir::readJson(ContractDir::openapi());
    $enum = $document['components']['schemas']['HealthJobsPause']['properties']['source']['enum'];

    $constants = [];

    foreach ((new ReflectionClass(HealthJobsPause::class))->getConstants() as $name => $value) {
        if (str_starts_with($name, 'SOURCE_') && is_string($value)) {
            $constants[] = $value;
        }
    }

    expect(HealthJobsPause::SOURCES)->toEqualCanonicalizing($enum)
        ->and($constants)->toEqualCanonicalizing($enum);
});
