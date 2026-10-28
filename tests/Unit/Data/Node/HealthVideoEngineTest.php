<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthVideoEngine;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

/*
 * The encoder pins (tmesis v0.9.0, handover 3.4): `requested` is the config value
 * and `usable` false ONLY for a pin that failed at boot, encoder then naming the pin.
 */

/**
 * @return array<string, mixed>
 */
function videoEngineBody(array $overrides = []): array
{
    return [
        'encoder' => 'h264_nvenc',
        'preset' => 'p2',
        'accelerator' => 'nvenc',
        'placement' => 'discrete',
        'fell_back_with_device_available' => false,
        'reason' => 'h264_nvenc is compiled in',
        'requested' => 'auto',
        'usable' => true,
        ...$overrides,
    ];
}

it('reads an auto choice as usable and not pinned', function (): void {
    $engine = HealthVideoEngine::fromArray(videoEngineBody());

    expect($engine->requested())->toBe(HealthVideoEngine::REQUESTED_AUTO)
        ->and($engine->pinned())->toBeFalse()
        ->and($engine->usable())->toBeTrue()
        ->and($engine->encoder())->toBe('h264_nvenc');
});

it('reads a failed pin as unusable, the encoder naming the pin', function (): void {
    $engine = HealthVideoEngine::fromArray(videoEngineBody([
        'encoder' => 'h264_qsv',
        'accelerator' => 'qsv',
        'placement' => 'unknown',
        'requested' => 'h264_qsv',
        'usable' => false,
        'reason' => 'pinned to h264_qsv by encode.video_encoder, but no /dev/dri render node is present',
    ]));

    expect($engine->pinned())->toBeTrue()
        ->and($engine->usable())->toBeFalse()
        ->and($engine->encoder())->toBe($engine->requested());
});

it('reads a body from a node before v0.9.0 as unpinned and usable', function (): void {
    $body = videoEngineBody();
    unset($body['requested'], $body['usable']);

    $engine = HealthVideoEngine::fromArray($body);

    expect($engine->requested())->toBe('')
        ->and($engine->pinned())->toBeFalse()
        ->and($engine->usable())->toBeTrue();
});

it('reads every requested value the schema names', function (string $requested): void {
    expect(HealthVideoEngine::fromArray(videoEngineBody(['requested' => $requested]))->requested())->toBe($requested);
})->with(HealthVideoEngine::REQUESTED);

it('holds the requested constants equal to the schema enum', function (): void {
    /** @var array<string, mixed> $document */
    $document = ContractDir::readJson(ContractDir::openapi());
    $enum = $document['components']['schemas']['HealthVideoEngine']['properties']['requested']['enum'];

    $constants = [];

    foreach ((new ReflectionClass(HealthVideoEngine::class))->getConstants() as $name => $value) {
        if (str_starts_with($name, 'REQUESTED_') && is_string($value)) {
            $constants[] = $value;
        }
    }

    expect(HealthVideoEngine::REQUESTED)->toEqualCanonicalizing($enum)
        ->and($constants)->toEqualCanonicalizing($enum);
});
