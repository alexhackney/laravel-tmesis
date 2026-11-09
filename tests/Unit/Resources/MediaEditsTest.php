<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Jobs\EditSpec;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\MediaEditRequest;
use AlexHackney\Tmesis\Exceptions\BadRequestException;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\EncoderUnavailableException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\PayloadTooLargeException;
use AlexHackney\Tmesis\Exceptions\RateLimitException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Exceptions\UnsupportedMediaTypeException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const EDITS_URL = 'http://127.0.0.1:7000/api/v1/media/edits';

function clipRequest(): MediaEditRequest
{
    return MediaEditRequest::make(
        JobSource::prefix('tsp-vod', 'replays/941'),
        EditSpec::make()->trim(120.0, 148.5),
        JobTarget::mediaEdit('tsp-clips', 'clips/941/c1.mp4', '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
        'clip-5521',
    );
}

it('submits an edit to the media edits route and reads a 202 as created, Location unread', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createMediaEdit.json'), 202, ['Location' => '/api/v1/transcode/jobs/job_01J8ZCA2R7F3H9NQ4KTX8VD5YC'])]);

    $accepted = Tmesis::node()->transcodeJobs()->createMediaEdit(clipRequest());

    expect($accepted->created)->toBeTrue()
        ->and($accepted->id())->toBe('job_01J8ZCA2R7F3H9NQ4KTX8VD5YC')
        ->and($accepted->state())->toBe(JobStatus::STATE_QUEUED)
        ->and($accepted->kind())->toBe('media_edit')
        ->and($accepted->idempotencyKey())->toBe('clip-5521')
        ->and($accepted->cut()->mode())->toBe('keyframe')
        ->and($accepted->operations()[0]->op())->toBe('trim');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === EDITS_URL
        && $request->data() === clipRequest()->toArray());
});

it('reads a 200 as the idempotent repeat of a known key, in any state', function (): void {
    Http::fake(['*' => Http::response([...$this->fixtureJson('responses/createMediaEdit.json'), 'state' => 'completed'], 200)]);

    $accepted = Tmesis::node()->transcodeJobs()->createMediaEdit(clipRequest());

    expect($accepted->created)->toBeFalse()
        ->and($accepted->state())->toBe(JobStatus::STATE_COMPLETED);
});

it('maps the edit refusals to named exceptions', function (int $status, string $code, string $class): void {
    Http::fake(['*' => Http::response(['error' => ['code' => $code, 'message' => 'refused', 'details' => null, 'request_id' => 'req_01J8ZC', 'milestone' => null]], $status)]);

    try {
        Tmesis::node()->transcodeJobs()->createMediaEdit(clipRequest());
        $this->fail('expected a refusal');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->code())->toBe($code);
    }
})->with([
    'an empty body' => [400, 'bad_request', BadRequestException::class],
    'an edge' => [409, 'role_unsupported', ConflictException::class],
    'an overlap with an open job or edit' => [409, 'destination_busy', ConflictException::class],
    'a body over 1 MiB' => [413, 'payload_too_large', PayloadTooLargeException::class],
    'a content type that is not JSON' => [415, 'unsupported_media_type', UnsupportedMediaTypeException::class],
    'a source the node does not resolve' => [422, 'source_not_supported', ValidationException::class],
    'the removed destination field' => [422, 'destination_not_supported', ValidationException::class],
    'an output that sits in an input' => [422, 'validation_failed', ValidationException::class],
    'a full queue' => [429, 'queue_full', RateLimitException::class],
    'no job runner' => [501, 'not_implemented', NotImplementedException::class],
    'a draining node' => [503, 'draining', UnavailableException::class],
    'an unusable encoder' => [503, 'encoder_unavailable', EncoderUnavailableException::class],
]);

it('sends no request when an edit the client can see is wrong', function (): void {
    Http::fake();

    expect(fn () => Tmesis::node()->transcodeJobs()->createMediaEdit(
        MediaEditRequest::make(JobSource::prefix('tsp-vod', 'replays/941'), EditSpec::make(), JobTarget::mediaEdit('tsp-clips', 'k.mp4'), 'clip-1'),
    ))->toThrow(InvalidConfigurationException::class);

    Http::assertNothingSent();
});

it('treats a swept job as gone: a 404 on a known job id is a NotFoundException', function (): void {
    Http::fake(['*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'no such job', 'details' => null, 'request_id' => 'req_01J8ZC', 'milestone' => null]], 404)]);

    expect(fn () => Tmesis::node()->transcodeJobs()->getTranscodeJob('job_01J8ZCA2R7F3H9NQ4KTX8VD5YC'))->toThrow(NotFoundException::class);
});
