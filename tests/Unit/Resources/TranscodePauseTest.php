<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobPauseResponse;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Exceptions\AuthenticationException;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\EncoderUnavailableException;
use AlexHackney\Tmesis\Exceptions\FleetOwnedException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The told jobs pause routes (tmesis v0.9.0, handover 3.5, API.md 9.12a), and the
 * job submit's 503 encoder_unavailable (handover 3.7).
 */

const PAUSE_URL = 'http://127.0.0.1:7000/api/v1/transcode/pause';

/**
 * @param  list<array{path: string, message: string}>|null  $details
 * @return array<string, mixed>
 */
function pauseError(string $code, string $message = 'refused', ?array $details = null, ?string $milestone = null): array
{
    return ['error' => ['code' => $code, 'message' => $message, 'details' => $details, 'request_id' => 'req_01J8ZC', 'milestone' => $milestone]];
}

function pauseJobSpec(): Spec
{
    return Spec::make(JobSource::destinationRef('tsp-media', 'uploads/abc.mov'), 'video-941-v2')->ladder('vod-1080p')
        ->target(JobTarget::hlsLadder('tsp-media', 'videos/941'));
}

it('PUTs the pause and reads the stored pause back', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/setTranscodePause.json'))]);

    $pause = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('show: fight night 12', '2026-08-28T19:00:00Z'));

    expect($pause)->toBeInstanceOf(JobPauseResponse::class)
        ->and($pause->paused())->toBeTrue()
        ->and($pause->source())->toBe('api');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === PAUSE_URL
        && $request->body() === '{"until":"2026-08-28T19:00:00Z","reason":"show: fight night 12"}'
        && $request->hasHeader('Content-Type', 'application/json'));
});

it('sends until as JSON null for a pause until cleared', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getTranscodePause.json'))]);

    Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('maintenance'));

    Http::assertSent(fn (Request $request): bool => $request->body() === '{"until":null,"reason":"maintenance"}');
});

it('reads a PUT of a past until as {paused: false}', function (): void {
    Http::fake(['*' => Http::response(['paused' => false])]);

    $pause = Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('too late', '2001-01-01T00:00:00Z'));

    expect($pause->paused())->toBeFalse()
        ->and($pause->reason())->toBeNull();
});

it('GETs the pause, active and inactive', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push($this->fixtureJson('responses/getTranscodePause.json'))
        ->push(['paused' => false])]);

    $active = Tmesis::node()->transcodeJobs()->getTranscodePause();
    $inactive = Tmesis::node()->transcodeJobs()->getTranscodePause();

    expect($active->paused())->toBeTrue()
        ->and($active->source())->toBe('fleet')
        ->and($active->until())->toBeNull()
        ->and($inactive->paused())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === PAUSE_URL
        && $request->body() === '');
});

it('DELETEs the pause with no body and reads the empty 204', function (): void {
    Http::fake(['*' => Http::response('', 204)]);

    Tmesis::node()->transcodeJobs()->clearTranscodePause();
    Tmesis::node()->transcodeJobs()->clearTranscodePause();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === PAUSE_URL
        && $request->body() === '');
});

it('maps 409 fleet_owned on both writes to FleetOwnedException naming jobs_pause', function (Closure $call): void {
    Http::fake(['*' => Http::response(pauseError(
        'fleet_owned',
        "the fleet owns this; change it in the control plane's desired state",
        [['path' => 'jobs_pause', 'message' => "is owned by the fleet's desired state (jobs_pause)"]],
    ), 409)]);

    try {
        $call();
        $this->fail('No exception was thrown.');
    } catch (FleetOwnedException $e) {
        expect($e)->toBeInstanceOf(ConflictException::class)
            ->and($e->code())->toBe('fleet_owned')
            ->and($e->status())->toBe(409)
            ->and($e->ownedPaths())->toBe(['jobs_pause']);
    }
})->with([
    'set' => [fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('x'))],
    'clear' => [fn () => Tmesis::node()->transcodeJobs()->clearTranscodePause()],
]);

it('maps the pause routes\' other refusals', function (int $status, string $code, string $class, Closure $call): void {
    Http::fake(['*' => Http::response(pauseError($code, milestone: $status === 501 ? 'M3' : null), $status)]);

    expect($call)->toThrow($class);
})->with([
    '422 set' => [422, 'validation_failed', ValidationException::class, fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('x'))],
    '503 set' => [503, 'journal_unavailable', UnavailableException::class, fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('x'))],
    '503 clear' => [503, 'journal_unavailable', UnavailableException::class, fn () => Tmesis::node()->transcodeJobs()->clearTranscodePause()],
    '401 get' => [401, 'unauthorized', AuthenticationException::class, fn () => Tmesis::node()->transcodeJobs()->getTranscodePause()],
]);

it('surfaces a node with no job engine as 501 on all three routes', function (Closure $call): void {
    Http::fake(['*' => Http::response(pauseError('not_implemented', 'this build has no job runner; it lands at M3', milestone: 'M3'), 501)]);

    try {
        $call();
        $this->fail('No exception was thrown.');
    } catch (NotImplementedException $e) {
        expect($e->milestone())->toBe('M3');
    }
})->with([
    'set' => [fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('x'))],
    'get' => [fn () => Tmesis::node()->transcodeJobs()->getTranscodePause()],
    'clear' => [fn () => Tmesis::node()->transcodeJobs()->clearTranscodePause()],
]);

it('maps a job submit\'s 503 encoder_unavailable to EncoderUnavailableException, not retryable here', function (): void {
    Http::fake(['*' => Http::response(pauseError('encoder_unavailable', 'the pinned jobs video encoder is not usable on this box: h264_nvenc: no device'), 503)]);

    try {
        Tmesis::node()->transcodeJobs()->createTranscodeJob(pauseJobSpec());
        $this->fail('No exception was thrown.');
    } catch (EncoderUnavailableException $e) {
        expect($e)->toBeInstanceOf(UnavailableException::class)
            ->and($e->code())->toBe('encoder_unavailable')
            ->and($e->retryableHere())->toBeFalse()
            ->and($e->retryAfter())->toBeNull()
            ->and($e->getMessage())->toContain('h264_nvenc');
    }
});

it('keeps a job submit\'s 503 draining a plain, retryable UnavailableException', function (): void {
    Http::fake(['*' => Http::response(pauseError('draining'), 503, ['Retry-After' => '30'])]);

    try {
        Tmesis::node()->transcodeJobs()->createTranscodeJob(pauseJobSpec());
        $this->fail('No exception was thrown.');
    } catch (UnavailableException $e) {
        expect($e)->not->toBeInstanceOf(EncoderUnavailableException::class)
            ->and($e->retryAfter())->toBe(30);
    }
});
