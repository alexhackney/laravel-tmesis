<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthLiveCapacity;
use AlexHackney\Tmesis\Support\Rfc3339;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

/*
 * Health `live_capacity` (tmesis v0.9.0, handover 3.6): the live ledger beside the
 * JOB slots `capacity` keeps meaning. encode_slots_total is NULL whenever admission
 * is not on, never 0 for unknown.
 */

/**
 * The enum of one HealthLiveCapacity property in the bundled openapi.
 *
 * @return list<string>
 */
function liveCapacityEnum(string $property): array
{
    /** @var array<string, mixed> $document */
    $document = ContractDir::readJson(ContractDir::openapi());

    return $document['components']['schemas']['HealthLiveCapacity']['properties'][$property]['enum'];
}

/**
 * @return array<string, mixed>
 */
function liveCapacity(array $overrides = []): array
{
    return [
        'admission' => 'on',
        'source' => 'measured',
        'unit_ladder' => 'production',
        'encode_slots_total' => 7,
        'encode_slots_used' => 3,
        'device_sessions_total' => 12,
        'device_sessions_used' => 4,
        'caption_sessions_total' => 2,
        'caption_sessions_used' => 1,
        'measured_at' => '2026-08-20T14:02:11Z',
        ...$overrides,
    ];
}

it('reads a measured box with admission on', function (): void {
    $live = HealthLiveCapacity::fromArray(liveCapacity());

    expect($live->admission())->toBe(HealthLiveCapacity::ADMISSION_ON)
        ->and($live->admissionOn())->toBeTrue()
        ->and($live->applicable())->toBeTrue()
        ->and($live->source())->toBe(HealthLiveCapacity::SOURCE_MEASURED)
        ->and($live->unitLadder())->toBe('production')
        ->and($live->encodeSlotsTotal())->toBe(7)
        ->and($live->encodeSlotsUsed())->toBe(3)
        ->and($live->encodeSlotsFree())->toBe(4)
        ->and($live->deviceSessionsTotal())->toBe(12)
        ->and($live->deviceSessionsUsed())->toBe(4)
        ->and($live->captionSessionsTotal())->toBe(2)
        ->and($live->captionSessionsUsed())->toBe(1)
        ->and($live->measuredAtRaw())->toBe('2026-08-20T14:02:11Z')
        ->and($live->measuredAt())->toEqual(Rfc3339::parse('2026-08-20T14:02:11Z'))
        ->and($live->toArray())->toBe(liveCapacity());
});

it('never reads 0 for an unknown limit while admission is off', function (): void {
    $live = HealthLiveCapacity::fromArray(liveCapacity([
        'admission' => 'off',
        'source' => 'unmeasured',
        'encode_slots_total' => null,
        'encode_slots_used' => 2,
        'device_sessions_total' => null,
        'device_sessions_used' => 2,
        'measured_at' => null,
    ]));

    expect($live->admissionOn())->toBeFalse()
        ->and($live->applicable())->toBeTrue()
        ->and($live->encodeSlotsTotal())->toBeNull()
        ->and($live->encodeSlotsUsed())->toBe(2)
        ->and($live->encodeSlotsFree())->toBeNull()
        ->and($live->deviceSessionsTotal())->toBeNull()
        ->and($live->measuredAtRaw())->toBeNull()
        ->and($live->measuredAt())->toBeNull();
});

it('reads a null captioned capacity when no self-hosted provider serves it', function (): void {
    $live = HealthLiveCapacity::fromArray(liveCapacity([
        'caption_sessions_total' => null,
        'caption_sessions_used' => null,
    ]));

    expect($live->captionSessionsTotal())->toBeNull()
        ->and($live->captionSessionsUsed())->toBeNull();
});

it('reads a stale record\'s time while admission is off', function (): void {
    $live = HealthLiveCapacity::fromArray(liveCapacity(['admission' => 'off', 'source' => 'stale', 'encode_slots_total' => null]));

    expect($live->source())->toBe(HealthLiveCapacity::SOURCE_STALE)
        ->and($live->encodeSlotsTotal())->toBeNull()
        ->and($live->measuredAt())->not->toBeNull();
});

it('reads a node that encodes no live stream as not applicable', function (): void {
    $live = HealthLiveCapacity::fromArray([
        'admission' => 'not_applicable',
        'source' => 'not_applicable',
        'unit_ladder' => '',
        'encode_slots_total' => null,
        'encode_slots_used' => 0,
        'device_sessions_total' => null,
        'device_sessions_used' => 1,
        'measured_at' => null,
    ]);

    expect($live->applicable())->toBeFalse()
        ->and($live->admissionOn())->toBeFalse()
        ->and($live->source())->toBe(HealthLiveCapacity::SOURCE_NOT_APPLICABLE)
        ->and($live->unitLadder())->toBe('')
        ->and($live->encodeSlotsTotal())->toBeNull()
        ->and($live->deviceSessionsUsed())->toBe(1);
});

it('never reads a negative free count when a costlier ladder overshoots', function (): void {
    expect(HealthLiveCapacity::fromArray(liveCapacity(['encode_slots_used' => 9]))->encodeSlotsFree())->toBe(0);
});

it('decodes an empty object to empty values rather than a guess', function (): void {
    $live = HealthLiveCapacity::fromArray([]);

    expect($live->admission())->toBe('')
        ->and($live->source())->toBe('')
        ->and($live->encodeSlotsTotal())->toBeNull()
        ->and($live->encodeSlotsUsed())->toBe(0)
        ->and($live->deviceSessionsTotal())->toBeNull()
        ->and($live->captionSessionsTotal())->toBeNull()
        ->and($live->captionSessionsUsed())->toBeNull()
        ->and($live->measuredAt())->toBeNull();
});

it('refuses a measured_at that is not RFC3339 UTC', function (): void {
    HealthLiveCapacity::fromArray(liveCapacity(['measured_at' => '2026-08-20 14:02:11']))->measuredAt();
})->throws(InvalidArgumentException::class);

it('holds the admission constants equal to the schema enum', function (): void {
    expect(HealthLiveCapacity::ADMISSIONS)->toEqualCanonicalizing(liveCapacityEnum('admission'))
        ->and(liveCapacityConstants('ADMISSION_'))->toEqualCanonicalizing(liveCapacityEnum('admission'));
});

it('holds the source constants equal to the schema enum', function (): void {
    expect(HealthLiveCapacity::SOURCES)->toEqualCanonicalizing(liveCapacityEnum('source'))
        ->and(liveCapacityConstants('SOURCE_'))->toEqualCanonicalizing(liveCapacityEnum('source'));
});

it('reads every source the schema names', function (string $source): void {
    expect(HealthLiveCapacity::fromArray(liveCapacity(['source' => $source]))->source())->toBe($source);
})->with(HealthLiveCapacity::SOURCES);

it('is reached from the full health body and never null', function (): void {
    $health = HealthFull::fromArray($this->fixtureJson('responses/getHealth.json'));

    expect($health->liveCapacity()->admissionOn())->toBeTrue()
        ->and($health->liveCapacity()->encodeSlotsTotal())->toBe(7)
        // A deepgram live box: no self-hosted captioned ceiling.
        ->and($health->liveCapacity()->captionSessionsTotal())->toBeNull()
        ->and($health->liveCapacity()->captionSessionsUsed())->toBeNull()
        ->and($health->capacity()?->encodeSlotsTotal())->toBe(6);

    $body = $this->fixtureJson('responses/getHealth.json');
    unset($body['live_capacity']);

    expect(HealthFull::fromArray($body)->liveCapacity()->admission())->toBe('');
});

/**
 * @return list<string>
 */
function liveCapacityConstants(string $prefix): array
{
    $out = [];

    foreach ((new ReflectionClass(HealthLiveCapacity::class))->getConstants() as $name => $value) {
        if (str_starts_with($name, $prefix) && is_string($value)) {
            $out[] = $value;
        }
    }

    return $out;
}
