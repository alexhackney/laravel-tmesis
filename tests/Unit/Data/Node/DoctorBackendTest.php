<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\DoctorBackend;
use AlexHackney\Tmesis\Data\Node\DoctorResponse;

/*
 * Doctor's encoder pins (tmesis v0.9.0, handover 3.4): `requested` and `usable` on
 * both backends, and `jobs_backend` beside `backend`, null when jobs are off.
 */

it('reads both backends from the doctor body', function (): void {
    $doctor = DoctorResponse::fromArray($this->fixtureJson('responses/getDoctor.json'));

    expect($doctor->backend()->requested())->toBe('auto')
        ->and($doctor->backend()->usable())->toBeTrue()
        ->and($doctor->jobsBackend())->toBeInstanceOf(DoctorBackend::class)
        ->and($doctor->jobsBackend()?->active())->toBe('libx264')
        ->and($doctor->jobsBackend()?->requested())->toBe('libx264')
        ->and($doctor->jobsBackend()?->usable())->toBeTrue()
        ->and($doctor->jobsBackend()?->zeroCopy())->toBeNull()
        ->and($doctor->jobsBackend()?->fallbackReason())->toBeNull();
});

it('reads jobs off as no jobs backend', function (): void {
    $doctor = DoctorResponse::fromArray([...$this->fixtureJson('responses/getDoctor.json'), 'jobs_backend' => null]);

    expect($doctor->jobsBackend())->toBeNull();

    $body = $this->fixtureJson('responses/getDoctor.json');
    unset($body['jobs_backend']);

    expect(DoctorResponse::fromArray($body)->jobsBackend())->toBeNull();
});

it('reads a failed pin as unusable', function (): void {
    $backend = DoctorBackend::fromArray([
        'active' => 'h264_nvenc',
        'preset' => 'p2',
        'accelerator' => 'nvenc',
        'zero_copy' => null,
        'fallback_reason' => null,
        'requested' => 'h264_nvenc',
        'usable' => false,
    ]);

    expect($backend->usable())->toBeFalse()
        ->and($backend->active())->toBe($backend->requested());
});

it('reads a backend from a node before v0.9.0 as unrequested and usable', function (): void {
    $backend = DoctorBackend::fromArray(['active' => 'libx264', 'preset' => 'veryfast', 'accelerator' => 'none']);

    expect($backend->requested())->toBe('')
        ->and($backend->usable())->toBeTrue();
});
