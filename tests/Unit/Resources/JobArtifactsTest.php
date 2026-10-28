<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const JOBART_JOB = 'job_01J8ZCA2R7F3H9NQ4KTX8VD5YB';

const JOBART_GENERATION = '01J8ZCK5R7V2QNX8RW4TKB6PD2';

const JOBART_OBJECT = '01J8ZCK9T4N2QWX6VM3RKB9PD8';

const JOBART_INVENTORY = '9f2c1d5e6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f';

const JOBART_BASE = 'http://127.0.0.1:7000/api/v1/transcode/jobs/job_01J8ZCA2R7F3H9NQ4KTX8VD5YB/artifacts';

it('sends every job artifact operation to its job-scoped route with the stream route\'s body', function (string $operation, string $method, string $path, Closure $call, int $status): void {
    Http::fake(['*' => Http::response($this->fixtureJson("responses/{$operation}.json"), $status)]);

    $call(Tmesis::node()->jobArtifacts());

    Http::assertSent(fn (Request $request): bool => $request->method() === $method && $request->url() === JOBART_BASE.$path);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/streams/'));
})->with([
    'listJobArtifacts' => ['listJobArtifacts', 'GET', '?limit=10&cursor=c', fn ($r) => $r->listJobArtifacts(JOBART_JOB, 10, 'c'), 200],
    'getJobArtifact' => ['getJobArtifact', 'GET', '/'.JOBART_GENERATION, fn ($r) => $r->getJobArtifact(JOBART_JOB, JOBART_GENERATION), 200],
    'getJobArtifactInventory' => ['getJobArtifactInventory', 'GET', '/'.JOBART_GENERATION.'/inventory', fn ($r) => $r->getJobArtifactInventory(JOBART_JOB, JOBART_GENERATION), 200],
    'getJobTransferPlan' => ['getJobTransferPlan', 'GET', '/'.JOBART_GENERATION.'/transfer-plan', fn ($r) => $r->getJobTransferPlan(JOBART_JOB, JOBART_GENERATION), 200],
    'replaceJobTransferPlan' => ['replaceJobTransferPlan', 'PUT', '/'.JOBART_GENERATION.'/transfer-plan', fn ($r) => $r->replaceJobTransferPlan(JOBART_JOB, JOBART_GENERATION, PlanRequest::make(JOBART_INVENTORY, 1)->withTargets(PlanTarget::jobAudio('tsp-media', 'videos/941/audio.m4a'))), 200],
    'listJobTransfers' => ['listJobTransfers', 'GET', '/'.JOBART_GENERATION.'/transfers', fn ($r) => $r->listJobTransfers(JOBART_JOB, JOBART_GENERATION), 200],
    'listJobTransferParts' => ['listJobTransferParts', 'GET', '/'.JOBART_GENERATION.'/transfers/'.JOBART_OBJECT.'/parts', fn ($r) => $r->listJobTransferParts(JOBART_JOB, JOBART_GENERATION, JOBART_OBJECT), 200],
    'supplyJobTransferAuthorization' => ['supplyJobTransferAuthorization', 'PUT', '/'.JOBART_GENERATION.'/transfers/'.JOBART_OBJECT.'/authorization', fn ($r) => $r->supplyJobTransferAuthorization(JOBART_JOB, JOBART_GENERATION, JOBART_OBJECT, AuthorizationRequest::make(
        1, JOBART_INVENTORY, 't', 'videos/941/audio.m4a', 1, null,
        AuthorizationBundle::make(put: SignedRequest::make('PUT', 'https://tsp-assets.example.net/k?X-Amz-Signature=x', [], '2026-09-22T22:30:00.000Z')),
    )), 200],
    'releaseJobArtifact' => ['releaseJobArtifact', 'POST', '/'.JOBART_GENERATION.'/release', fn ($r) => $r->releaseJobArtifact(JOBART_JOB, JOBART_GENERATION, ReleaseRequest::make(1, JOBART_INVENTORY, 'copied')), 202],
    'overrideJobArtifact' => ['overrideJobArtifact', 'POST', '/'.JOBART_GENERATION.'/override', fn ($r) => $r->overrideJobArtifact(JOBART_JOB, JOBART_GENERATION, OverrideRequest::discard(1, JOBART_INVENTORY, 'superseded', ['t'])), 202],
]);

it('reads a job generation: no stream, the job instead', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getJobArtifact.json'))]);

    $generation = Tmesis::node()->jobArtifacts()->getJobArtifact(JOBART_JOB, JOBART_GENERATION);

    expect($generation->isJobGeneration())->toBeTrue()
        ->and($generation->jobId())->toBe(JOBART_JOB)
        ->and($generation->stream())->toBeNull()
        ->and($generation->session())->toBeNull()
        ->and($generation->sessionEpoch())->toBeNull()
        ->and($generation->kind())->toBe(ArtifactStatus::KIND_JOB_AUDIO)
        ->and($generation->recordingId())->toBeNull();
});

it('carries the created and recorded flags from the status codes, as the stream routes do', function (): void {
    Http::fakeSequence()
        ->push($this->fixtureJson('responses/replaceJobTransferPlan.json'), 201)
        ->push($this->fixtureJson('responses/replaceJobTransferPlan.json'), 200)
        ->push($this->fixtureJson('responses/releaseJobArtifact.json'), 202)
        ->push($this->fixtureJson('responses/releaseJobArtifact.json'), 200);

    $resource = Tmesis::node()->jobArtifacts();
    $plan = PlanRequest::make(JOBART_INVENTORY, 0);
    $release = ReleaseRequest::make(1, JOBART_INVENTORY, 'copied');

    expect($resource->replaceJobTransferPlan(JOBART_JOB, JOBART_GENERATION, $plan)->created)->toBeTrue()
        ->and($resource->replaceJobTransferPlan(JOBART_JOB, JOBART_GENERATION, $plan)->created)->toBeFalse()
        ->and($resource->releaseJobArtifact(JOBART_JOB, JOBART_GENERATION, $release)->recorded)->toBeTrue()
        ->and($resource->releaseJobArtifact(JOBART_JOB, JOBART_GENERATION, $release)->recorded)->toBeFalse();
});

it('walks the four paged job reads lazily', function (): void {
    $generation = $this->fixtureJson('responses/getJobArtifact.json');
    $entry = $this->fixtureJson('responses/getJobArtifactInventory.json')['items'][0];
    $object = $this->fixtureJson('responses/listJobTransfers.json')['items'][0];

    Http::fake([
        '*/parts*' => Http::response(['items' => [], 'limit' => 100, 'next_cursor' => null]),
        '*/transfers*' => Http::response(['items' => [$object], 'limit' => 100, 'next_cursor' => null]),
        '*/inventory*' => Http::response(['items' => [$entry], 'limit' => 100, 'next_cursor' => null]),
        '*' => Http::response(['items' => [$generation, $generation], 'limit' => 100, 'next_cursor' => null]),
    ]);

    $resource = Tmesis::node()->jobArtifacts();

    $lazy = $resource->allJobArtifacts(JOBART_JOB);
    Http::assertNothingSent();

    expect($lazy->all())->toHaveCount(2)
        ->and($resource->allJobArtifactInventory(JOBART_JOB, JOBART_GENERATION)->all())->toHaveCount(1)
        ->and($resource->allJobTransfers(JOBART_JOB, JOBART_GENERATION)->all())->toHaveCount(1)
        ->and($resource->allJobTransferParts(JOBART_JOB, JOBART_GENERATION, JOBART_OBJECT)->all())->toBe([]);
});

it('refuses a page size that is not positive, before any request', function (): void {
    Http::fake();

    $resource = Tmesis::node()->jobArtifacts();

    expect(fn () => $resource->listJobArtifacts(JOBART_JOB, 0))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $resource->allJobTransfers(JOBART_JOB, JOBART_GENERATION, -1))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $resource->getJobArtifactInventory(JOBART_JOB, JOBART_GENERATION, 0))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $resource->listJobTransferParts(JOBART_JOB, JOBART_GENERATION, JOBART_OBJECT, 0))->toThrow(InvalidConfigurationException::class);

    Http::assertNothingSent();
});

it('maps a generation of another job to a 404 and the artifact conflicts to ConflictException', function (): void {
    Http::fakeSequence()
        ->push(['error' => ['code' => 'not_found', 'message' => 'no', 'details' => null, 'request_id' => 'r', 'milestone' => null]], 404)
        ->push(['error' => ['code' => 'transfer_incomplete', 'message' => 'no', 'details' => null, 'request_id' => 'r', 'milestone' => null]], 409);

    $resource = Tmesis::node()->jobArtifacts();

    expect(fn () => $resource->getJobArtifact('job_OTHER', JOBART_GENERATION))->toThrow(NotFoundException::class);

    try {
        $resource->releaseJobArtifact(JOBART_JOB, JOBART_GENERATION, ReleaseRequest::make(1, JOBART_INVENTORY, 'copied'));
        $this->fail('expected a conflict');
    } catch (ConflictException $e) {
        expect($e->code())->toBe('transfer_incomplete');
    }
});

it('keeps the stream routes on the stream paths after the extraction', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getTransferPlan.json'))]);

    Tmesis::node()->artifacts()->getTransferPlan('show-2041-main', JOBART_GENERATION);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/artifacts/'.JOBART_GENERATION.'/transfer-plan');
});
