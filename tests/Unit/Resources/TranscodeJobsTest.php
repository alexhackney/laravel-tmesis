<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\RateLimitException;
use AlexHackney\Tmesis\Exceptions\ServerException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const JOBS_BASE = 'http://127.0.0.1:7000/api/v1/transcode/jobs';

const JOBS_ID = 'job_01J8ZCA2R7F3H9NQ4KTX8VD5YB';

function jobsSpec(): Spec
{
    return Spec::make(JobSource::destinationRef('tsp-media', 'uploads/abc.mov'), 'video-941-v2')
        ->ladder('vod-1080p')
        ->target(JobTarget::hlsLadder('tsp-media', 'videos/941', id: '01J8ZCK7T4N2QWX6VM3RKB9PD8'));
}

/**
 * @return array<string, mixed>
 */
function jobsError(string $code): array
{
    return ['error' => ['code' => $code, 'message' => 'refused', 'details' => null, 'request_id' => 'req_01J8ZC', 'milestone' => null]];
}

it('submits a job and reads a 202 as created', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createTranscodeJob.json'), 202)]);

    $accepted = Tmesis::node()->transcodeJobs()->createTranscodeJob(jobsSpec());

    expect($accepted->created)->toBeTrue()
        ->and($accepted->id())->toBe(JOBS_ID)
        ->and($accepted->state())->toBe(JobStatus::STATE_QUEUED)
        ->and($accepted->priority())->toBe('normal')
        ->and($accepted->idempotencyKey())->toBe('video-941-v2')
        ->and($accepted->submittedAt()->format('Y-m-d'))->toBe('2026-08-28');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === JOBS_BASE
        && $request->data() === jobsSpec()->toArray());
});

it('reads a 200 as the idempotent repeat of a known key, not a new job', function (): void {
    Http::fake(['*' => Http::response(['id' => JOBS_ID, 'state' => 'completed', 'priority' => 'normal', 'submitted_at' => '2026-08-28T19:31:12.044Z', 'idempotency_key' => 'video-941-v2'], 200)]);

    $accepted = Tmesis::node()->transcodeJobs()->createTranscodeJob(jobsSpec());

    expect($accepted->created)->toBeFalse()
        ->and($accepted->state())->toBe(JobStatus::STATE_COMPLETED);
});

it('maps the submission refusals to named exceptions', function (int $status, string $code, string $class): void {
    Http::fake(['*' => Http::response(jobsError($code), $status)]);

    try {
        Tmesis::node()->transcodeJobs()->createTranscodeJob(jobsSpec());
        $this->fail('expected a refusal');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->code())->toBe($code);
    }
})->with([
    'no job runner' => [501, 'not_implemented', NotImplementedException::class],
    'an edge' => [409, 'role_unsupported', ConflictException::class],
    'a prefix this node writes' => [409, 'destination_busy', ConflictException::class],
    'a reserved source' => [422, 'source_not_supported', ValidationException::class],
    'a later output' => [422, 'output_not_supported', ValidationException::class],
    'an origin destination' => [422, 'destination_not_supported', ValidationException::class],
    'a full queue' => [429, 'queue_full', RateLimitException::class],
]);

it('lists job summaries with the state filter, the page size and the cursor', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listTranscodeJobs.json'))]);

    $page = Tmesis::node()->transcodeJobs()->listTranscodeJobs('running', 50, 'opaque');

    expect($page->items())->toHaveCount(2)
        ->and($page->items()[0]->state())->toBe('running')
        ->and($page->items()[0]->stage())->toBe('encode')
        ->and($page->items()[0]->percent())->toBe(41.5)
        ->and($page->items()[0]->evictions())->toBe(1)
        ->and($page->items()[1]->errorCode())->toBe('source_unreadable')
        ->and($page->items()[1]->completedAt()?->format('H:i'))->toBe('19:52')
        ->and($page->limit())->toBe(100)
        ->and($page->nextCursor())->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === JOBS_BASE.'?state=running&limit=50&cursor=opaque');
});

it('refuses a state filter and a page size the node would refuse, before any request', function (): void {
    Http::fake();

    expect(fn () => Tmesis::node()->transcodeJobs()->listTranscodeJobs('pending'))->toThrow(InvalidConfigurationException::class, 'state')
        ->and(fn () => Tmesis::node()->transcodeJobs()->listTranscodeJobs(null, 0))->toThrow(InvalidConfigurationException::class, 'limit')
        ->and(fn () => Tmesis::node()->transcodeJobs()->allTranscodeJobs('pending'))->toThrow(InvalidConfigurationException::class, 'state');

    Http::assertNothingSent();
});

it('walks every job page lazily, and refuses a cursor answered twice', function (): void {
    $item = $this->fixtureJson('responses/listTranscodeJobs.json')['items'][0];

    Http::fakeSequence()
        ->push(['items' => [$item], 'limit' => 1, 'next_cursor' => 'c1'])
        ->push(['items' => [$item], 'limit' => 1, 'next_cursor' => null])
        ->push(['items' => [], 'limit' => 1, 'next_cursor' => 'same'])
        ->push(['items' => [], 'limit' => 1, 'next_cursor' => 'same']);

    $all = Tmesis::node()->transcodeJobs()->allTranscodeJobs('running', 1);

    Http::assertNothingSent();

    expect($all->all())->toHaveCount(2);

    Http::assertSent(fn (Request $request): bool => $request->url() === JOBS_BASE.'?state=running&limit=1&cursor=c1');

    expect(fn () => Tmesis::node()->transcodeJobs()->allTranscodeJobs()->all())->toThrow(ServerException::class);
});

it('reads a job with its progress, source and every output', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getTranscodeJob.json'))]);

    $job = Tmesis::node()->transcodeJobs()->getTranscodeJob(JOBS_ID);

    expect($job->id())->toBe(JOBS_ID)
        ->and($job->state())->toBe(JobStatus::STATE_RUNNING)
        ->and($job->isTerminal())->toBeFalse()
        ->and($job->node())->toBe('transcode-atl-04')
        ->and($job->attempts())->toBe(1)
        ->and($job->progress()->stage())->toBe('encode')
        ->and($job->progress()->etaSeconds())->toBe(402.0)
        ->and($job->source()->destinationRef())->toBe('tsp-media')
        ->and($job->source()->bytes())->toBe(8931245121)
        ->and($job->source()->url())->toBeNull()
        ->and($job->outputs())->toHaveCount(4)
        ->and($job->outputs()[0]->kind())->toBe('hls_ladder')
        ->and($job->outputs()[0]->generation())->toBeNull()
        ->and($job->outputs()[0]->state())->toBe('pending')
        ->and($job->error())->toBeNull()
        ->and($job->completedAt())->toBeNull()
        ->and($job->labels())->toBe(['video_id' => '941']);

    Http::assertSent(fn (Request $request): bool => $request->url() === JOBS_BASE.'/'.JOBS_ID);
});

it('escapes the job id in the path', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getTranscodeJob.json'))]);

    Tmesis::node()->transcodeJobs()->getTranscodeJob('job/../x');

    Http::assertSent(fn (Request $request): bool => $request->url() === JOBS_BASE.'/job%2F..%2Fx');
});

it('cancels a job, and a terminal one is job_not_cancellable', function (): void {
    Http::fakeSequence()
        ->push($this->fixtureJson('responses/cancelTranscodeJob.json'), 202)
        ->push(jobsError('job_not_cancellable'), 409)
        ->push(jobsError('not_found'), 404);

    $cancelled = Tmesis::node()->transcodeJobs()->cancelTranscodeJob(JOBS_ID);

    expect($cancelled->id())->toBe(JOBS_ID)
        ->and($cancelled->state())->toBe('cancelled');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === JOBS_BASE.'/'.JOBS_ID && $request->body() === '');

    expect(fn () => Tmesis::node()->transcodeJobs()->cancelTranscodeJob(JOBS_ID))->toThrow(ConflictException::class)
        ->and(fn () => Tmesis::node()->transcodeJobs()->cancelTranscodeJob(JOBS_ID))->toThrow(NotFoundException::class);
});

it('reaches the job resources through a request id too', function (): void {
    Http::fake([
        '*/artifacts/*' => Http::response($this->fixtureJson('responses/getJobArtifact.json')),
        '*' => Http::response($this->fixtureJson('responses/getTranscodeJob.json')),
    ]);

    Tmesis::node()->withRequestId('req-jobs-1')->transcodeJobs()->getTranscodeJob(JOBS_ID);
    Tmesis::node()->withRequestId('req-jobs-2')->jobArtifacts()->getJobArtifact(JOBS_ID, '01J8ZCK5R7V2QNX8RW4TKB6PD2');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Request-Id', 'req-jobs-1'));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Request-Id', 'req-jobs-2'));
});
