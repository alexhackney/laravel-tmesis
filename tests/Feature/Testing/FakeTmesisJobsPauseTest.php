<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobPauseResponse;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\EncoderUnavailableException;
use AlexHackney\Tmesis\Exceptions\FleetOwnedException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\AssertionFailedError;

/*
 * FakeTmesis's told jobs pause (tmesis v0.9.0, handover 3.5) and the job submit's
 * 503 encoder_unavailable (handover 3.7).
 */

beforeEach(function (): void {
    Date::setTestNow('2026-08-28T12:00:00.409Z');
});

afterEach(function (): void {
    Date::setTestNow();
});

it('reads exactly {paused: false} before any pause', function (): void {
    Tmesis::fake();

    $pause = Tmesis::node()->transcodeJobs()->getTranscodePause();

    expect($pause->paused())->toBeFalse()
        ->and($pause->toArray())->toBe(['paused' => false]);
});

it('stores a pause from the API and answers it on set and get', function (): void {
    $fake = Tmesis::fake();

    $set = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('show: fight night 12', '2026-08-28T15:00:00-04:00'));
    $get = Tmesis::node()->transcodeJobs()->getTranscodePause();

    expect($set->toArray())->toBe([
        'paused' => true,
        // Stored in UTC; a zero fraction echoes as none, as the node's RFC3339Nano does.
        'until' => '2026-08-28T19:00:00Z',
        'reason' => 'show: fight night 12',
        'source' => JobPauseResponse::SOURCE_API,
        'set_at' => '2026-08-28T12:00:00.409Z',
    ])->and($get->toArray())->toBe($set->toArray());

    $fake->assertJobsPaused(JobPauseResponse::SOURCE_API)
        ->assertCalled('setTranscodePause', fn (array $args): bool => $args['pause'] === ['until' => '2026-08-28T15:00:00-04:00', 'reason' => 'show: fight night 12'])
        ->assertCalled('getTranscodePause');
});

it('replaces a pause in place', function (): void {
    Tmesis::fake();

    Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('first'));
    $second = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('second', '2026-08-28T19:00:00Z'));

    expect($second->reason())->toBe('second')
        ->and(Tmesis::node()->transcodeJobs()->getTranscodePause()->untilRaw())->toBe('2026-08-28T19:00:00Z');
});

it('stores a past until and reads it as {paused: false}', function (): void {
    $fake = Tmesis::fake();

    $set = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('too late', '2026-08-28T11:00:00Z'));

    expect($set->toArray())->toBe(['paused' => false]);

    $fake->assertJobsNotPaused();
});

it('expires a pause on Laravel\'s clock', function (): void {
    $fake = Tmesis::fake();

    Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('show', '2026-08-28T19:00:00Z'));
    $fake->assertJobsPaused();

    Date::setTestNow('2026-08-28T19:00:00Z');

    expect(Tmesis::node()->transcodeJobs()->getTranscodePause()->paused())->toBeFalse();
});

it('holds a pause until cleared with a null until, and clears idempotently', function (): void {
    $fake = Tmesis::fake();

    Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('maintenance'));
    Date::setTestNow('2030-01-01T00:00:00Z');

    $fake->assertJobsPaused();
    expect(Tmesis::node()->transcodeJobs()->getTranscodePause()->until())->toBeNull();

    Tmesis::node()->transcodeJobs()->clearTranscodePause();
    Tmesis::node()->transcodeJobs()->clearTranscodePause();

    $fake->assertJobsNotPaused()->assertCalledCount('clearTranscodePause', 2);
});

it('refuses both writes 409 fleet_owned on an enrolled box, and still reads', function (): void {
    $fake = Tmesis::fake()
        ->fleetOwnsJobsPause()
        ->pauseJobsByFleet(JobPauseRequest::make('show: fight night 12', '2026-08-28T19:00:00Z'));

    foreach ([
        fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('x')),
        fn () => Tmesis::node()->transcodeJobs()->clearTranscodePause(),
    ] as $call) {
        try {
            $call();
            $this->fail('No exception was thrown.');
        } catch (FleetOwnedException $e) {
            expect($e)->toBeInstanceOf(ConflictException::class)
                ->and($e->code())->toBe('fleet_owned')
                ->and($e->ownedPaths())->toBe(['jobs_pause']);
        }
    }

    $read = Tmesis::node()->transcodeJobs()->getTranscodePause();

    expect($read->paused())->toBeTrue()
        ->and($read->source())->toBe(JobPauseResponse::SOURCE_FLEET)
        ->and($read->reason())->toBe('show: fight night 12');

    // A revision without jobs_pause clears it; leaving the fleet frees the routes.
    $fake->pauseJobsByFleet(null)->fleetOwnsJobsPause(false)->assertJobsNotPaused();

    Tmesis::node()->transcodeJobs()->clearTranscodePause();
});

it('fails the pause assertions when they do not hold', function (): void {
    $fake = Tmesis::fake();

    expect(fn () => $fake->assertJobsPaused())->toThrow(AssertionFailedError::class);

    Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('x'));

    expect(fn () => $fake->assertJobsNotPaused())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertJobsPaused(JobPauseResponse::SOURCE_FLEET))->toThrow(AssertionFailedError::class);
});

it('answers a job submit 503 encoder_unavailable when told to', function (): void {
    $fake = Tmesis::fake()->jobsEncoderUnavailable();

    $spec = Spec::make(JobSource::destinationRef('tsp-media', 'uploads/abc.mov'), 'video-941-v2')
        ->ladder('vod-1080p')
        ->target(JobTarget::hlsLadder('tsp-media', 'videos/941'));

    try {
        Tmesis::node()->transcodeJobs()->createTranscodeJob($spec);
        $this->fail('No exception was thrown.');
    } catch (EncoderUnavailableException $e) {
        expect($e)->toBeInstanceOf(UnavailableException::class)
            ->and($e->code())->toBe('encoder_unavailable')
            ->and($e->status())->toBe(503)
            ->and($e->retryableHere())->toBeFalse()
            ->and($e->getMessage())->toStartWith('the pinned jobs video encoder is not usable on this box: ');
    }

    $fake->assertCalled('createTranscodeJob');
});

/**
 * The fake's health body, as the wire would carry it, held to HealthFull.
 */
function assertFakeHealthValid(): void
{
    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode(Tmesis::node()->getHealth()->toArray()), false),
        '/components/schemas/HealthFull',
        'The fake\'s health',
    );
}

it('answers a default health that is a valid v0.9.0 HealthFull', function (): void {
    Tmesis::fake();

    $raw = Tmesis::node()->getHealth()->toArray();

    expect($raw['admission'])->toBe('open')
        ->and($raw['jobs_video_engine'])->toBeNull()
        ->and($raw['live_capacity']['admission'])->toBe('not_applicable')
        ->and($raw['jobs'])->toBeNull();

    assertFakeHealthValid();
});

it('carries the fake\'s active pause on a canned jobs object, and null otherwise', function (): void {
    $fake = Tmesis::fake()->withHealth($this->fixtureJson('responses/getHealth.json'));

    expect(Tmesis::node()->getHealth()->toArray()['jobs']['paused'])->toBeNull();
    assertFakeHealthValid();

    Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('show: fight night 12', '2026-08-28T19:00:00Z'));

    expect(Tmesis::node()->getHealth()->toArray()['jobs']['paused'])->toBe([
        'until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12', 'source' => 'api',
    ]);
    assertFakeHealthValid();

    $fake->pauseJobsByFleet(JobPauseRequest::untilCleared('fleet hold'));
    expect(Tmesis::node()->getHealth()->toArray()['jobs']['paused'])->toBe(['until' => null, 'reason' => 'fleet hold', 'source' => 'fleet']);
    assertFakeHealthValid();

    // Expired: back to the canned value.
    $fake->pauseJobsByFleet(JobPauseRequest::make('over', '2026-08-28T12:00:00Z'));
    expect(Tmesis::node()->getHealth()->toArray()['jobs']['paused'])->toBeNull();
});

it('keeps a canned jobs.paused when the fake holds no pause, and fills an absent one with null', function (): void {
    $canned = $this->fixtureJson('responses/getHealth.json');
    $canned['jobs']['paused'] = ['until' => null, 'reason' => 'canned', 'source' => 'api'];

    Tmesis::fake()->withHealth($canned);
    expect(Tmesis::node()->getHealth()->toArray()['jobs']['paused']['reason'])->toBe('canned');

    unset($canned['jobs']['paused']);
    Tmesis::fake()->withHealth($canned);
    expect(Tmesis::node()->getHealth()->toArray()['jobs'])->toHaveKey('paused')
        ->and(Tmesis::node()->getHealth()->toArray()['jobs']['paused'])->toBeNull();
    assertFakeHealthValid();
});

it('echoes until in UTC with its fraction whatever offset was sent, as the node does', function (): void {
    Tmesis::fake();

    $set = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('show', '2026-08-28T15:00:00.409-04:00'));

    expect($set->untilRaw())->toBe('2026-08-28T19:00:00.409Z')
        ->and(Tmesis::node()->getHealth()->toArray()['jobs'] ?? null)->toBeNull();
});

it('keeps a sub-second until exact against a clock with a fraction', function (): void {
    // The clock reads 12:00:00.409Z: a pause until 12:00:00.900Z is still in force.
    $fake = Tmesis::fake();

    $set = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('blink', '2026-08-28T12:00:00.900Z'));

    expect($set->paused())->toBeTrue()
        ->and($set->untilRaw())->toBe('2026-08-28T12:00:00.9Z');
    $fake->assertJobsPaused(JobPauseResponse::SOURCE_API);

    Date::setTestNow('2026-08-28T12:00:00.900Z');
    $fake->assertJobsNotPaused();
});

it('echoes until and set_at the way Go marshals RFC3339Nano: trimmed, and no fraction when zero', function (string $clock, string $until, string $untilEcho, string $setAtEcho): void {
    Date::setTestNow($clock);
    Tmesis::fake();

    $set = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('show', $until));

    expect($set->untilRaw())->toBe($untilEcho)
        ->and($set->toArray()['set_at'])->toBe($setAtEcho);
})->with([
    'zero fractions' => ['2026-08-28T12:00:00Z', '2026-08-28T19:00:00.000Z', '2026-08-28T19:00:00Z', '2026-08-28T12:00:00Z'],
    'trailing zeros' => ['2026-08-28T12:00:00.250Z', '2026-08-28T19:00:00.120000Z', '2026-08-28T19:00:00.12Z', '2026-08-28T12:00:00.25Z'],
    'microseconds' => ['2026-08-28T12:00:00.000001Z', '2026-08-28T19:00:00.123456789Z', '2026-08-28T19:00:00.123456Z', '2026-08-28T12:00:00.000001Z'],
]);

it('answers 501 naming M3 on every transcode route of a box with no job engine', function (Closure $call): void {
    Tmesis::fake()->withoutJobEngine();

    try {
        $call();
        test()->fail('Expected a NotImplementedException.');
    } catch (NotImplementedException $e) {
        expect($e->milestone())->toBe('M3');
    }
})->with([
    'set' => [fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('x'))],
    'get' => [fn () => Tmesis::node()->transcodeJobs()->getTranscodePause()],
    'clear' => [fn () => Tmesis::node()->transcodeJobs()->clearTranscodePause()],
    'list' => [fn () => Tmesis::node()->transcodeJobs()->listTranscodeJobs()],
    'get job' => [fn () => Tmesis::node()->transcodeJobs()->getTranscodeJob('01J8Z9P4R6K2NWX7VM5TQB3HD8')],
]);
