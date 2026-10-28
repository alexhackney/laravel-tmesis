<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthTranscription;
use AlexHackney\Tmesis\Data\Node\HealthVideoEngine;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

/*
 * The full health body's tmesis v0.9.0 fields (handover 3.4 and 3.6): the jobs
 * encoder beside the live one, the two encoder degraded reasons, and video_engine
 * null on a transcoder.
 */

/**
 * @return list<string>
 */
function healthDegradedReasonsEnum(): array
{
    /** @var array<string, mixed> $document */
    $document = ContractDir::readJson(ContractDir::openapi());

    return $document['components']['schemas']['HealthFull']['properties']['degraded_reasons']['items']['enum'];
}

it('reads both encoders from the full body', function (): void {
    $health = HealthFull::fromArray($this->fixtureJson('responses/getHealth.json'));

    expect($health->videoEngine())->toBeInstanceOf(HealthVideoEngine::class)
        ->and($health->videoEngine()?->requested())->toBe(HealthVideoEngine::REQUESTED_AUTO)
        ->and($health->videoEngine()?->usable())->toBeTrue()
        ->and($health->jobsVideoEngine())->toBeInstanceOf(HealthVideoEngine::class)
        ->and($health->jobsVideoEngine()?->encoder())->toBe('libx264')
        ->and($health->jobsVideoEngine()?->requested())->toBe(HealthVideoEngine::REQUESTED_LIBX264)
        ->and($health->jobsVideoEngine()?->pinned())->toBeTrue()
        ->and($health->jobsVideoEngine()?->usable())->toBeTrue();
});

it('tolerates a transcoder with no live encoder', function (): void {
    $health = HealthFull::fromArray([
        ...$this->fixtureJson('responses/getHealth.json'),
        'node_role' => 'transcoder',
        'video_engine' => null,
    ]);

    expect($health->nodeRole())->toBe(HealthFull::ROLE_TRANSCODER)
        ->and($health->videoEngine())->toBeNull()
        ->and($health->jobsVideoEngine())->not->toBeNull();
});

it('reads a node with jobs off as no jobs and no jobs encoder', function (): void {
    $health = HealthFull::fromArray([
        ...$this->fixtureJson('responses/getHealth.json'),
        'jobs' => null,
        'capacity' => null,
        'jobs_video_engine' => null,
    ]);

    expect($health->jobs())->toBeNull()
        ->and($health->capacity())->toBeNull()
        ->and($health->jobsVideoEngine())->toBeNull()
        ->and($health->videoEngine())->not->toBeNull();
});

it('reads an absent jobs encoder as null', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    unset($body['jobs_video_engine']);

    expect(HealthFull::fromArray($body)->jobsVideoEngine())->toBeNull();
});

it('reads transcription entries and treats an absent pre-v0.9.1 list as empty', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $health = HealthFull::fromArray($body);
    unset($body['transcription']);

    expect($health->transcription())->toHaveCount(1)
        ->and($health->transcription()[0])->toBeInstanceOf(HealthTranscription::class)
        ->and($health->transcription()[0]->role())->toBe(HealthTranscription::ROLE_LIVE)
        ->and(HealthFull::fromArray($body)->transcription())->toBe([])
        ->and(HealthFull::fromArray([...$body, 'transcription' => 'invalid'])->transcription())->toBe([])
        ->and(HealthFull::fromArray([...$body, 'transcription' => [['role' => 'live'], null]])->transcription())->toHaveCount(1);
});

it('reads the two encoder degraded reasons', function (string $reason): void {
    $health = HealthFull::fromArray([
        ...$this->fixtureJson('responses/getHealth.json'),
        'status' => 'degraded',
        'degraded_reasons' => [$reason],
    ]);

    expect($health->degradedReasons())->toBe([$reason])
        ->and($health->degradedBecause($reason))->toBeTrue()
        ->and($health->degradedBecause(HealthFull::DEGRADED_DISK_FLOOR_NEAR))->toBeFalse()
        ->and($health->ready())->toBeTrue();
})->with([HealthFull::DEGRADED_ENCODER_UNAVAILABLE, HealthFull::DEGRADED_JOBS_ENCODER_UNAVAILABLE]);

it('names no degraded reason on a healthy node', function (): void {
    $health = HealthFull::fromArray($this->fixtureJson('responses/getHealth.json'));

    expect($health->degradedReasons())->toBe([])
        ->and($health->degradedBecause(HealthFull::DEGRADED_ENCODER_UNAVAILABLE))->toBeFalse();
});

it('holds the degraded reason constants equal to the schema enum', function (): void {
    $constants = [];

    foreach ((new ReflectionClass(HealthFull::class))->getConstants() as $name => $value) {
        if (str_starts_with($name, 'DEGRADED_') && is_string($value)) {
            $constants[] = $value;
        }
    }

    expect(HealthFull::DEGRADED_REASONS)->toEqualCanonicalizing(healthDegradedReasonsEnum())
        ->and($constants)->toEqualCanonicalizing(healthDegradedReasonsEnum());
});
