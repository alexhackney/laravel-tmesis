<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\InitialAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\MasterInfo;
use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\MasterTarget;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The told master and the operator purge (tmesis v0.8.0, docs/API.md 12a.8 and 12a.5b),
 * through the real client.
 */

const MP_STREAM = 'show-2041-main';

const MP_SOURCE_A = '01J8ZCK5R7V2QNX8RW4TKB6PD2';

const MP_SOURCE_B = '01J8ZCM1T8V3QNX7RW2KPB4DF9';

const MP_INVENTORY = '9f2c1d5e6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f';

const MP_JOB = 'job_01J8ZCA2R7F3H9NQ4KTX8VD5YB';

/**
 * @return array<string, mixed>
 */
function mpError(string $code): array
{
    return ['error' => ['code' => $code, 'message' => 'refused', 'details' => null, 'request_id' => 'req_01J8ZC', 'milestone' => null]];
}

/**
 * @return array<string, mixed>
 */
function mpBody(Request $request): array
{
    /** @var array<string, mixed> */
    return json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR) ?: [];
}

it('posts a master to the stream\'s masters route and reads the queued generation back', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createMaster.json'), 202)]);

    $request = MasterRequest::make(
        [MP_SOURCE_B, MP_SOURCE_A],
        PlanTarget::recordingMaster('tsp-media', 'shows/2041/show-2041-main-master.mp4', '01J8ZCQ3P6N1QWX8VM4RKB2PD7'),
        '01J8ZCQ3K9R4TWX2VN6MPB8DF1',
    )->withReason('Show 2041 ended; combine the parts');

    $status = Tmesis::node()->artifacts()->createMaster(MP_STREAM, $request);

    Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
        && $sent->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/masters'
        && mpBody($sent) === [
            'request_id' => '01J8ZCQ3K9R4TWX2VN6MPB8DF1',
            // As listed: the node joins them in recording start order, not this one.
            'sources' => [MP_SOURCE_B, MP_SOURCE_A],
            'target' => [
                'id' => '01J8ZCQ3P6N1QWX8VM4RKB2PD7',
                'artifact' => 'recording_master',
                'destination_ref' => 'tsp-media',
                'key' => 'shows/2041/show-2041-main-master.mp4',
            ],
            'reason' => 'Show 2041 ended; combine the parts',
        ]);

    $master = $status->master();

    expect($status->created)->toBeTrue()
        ->and($status->kind())->toBe(ArtifactStatus::KIND_RECORDING_MASTER)
        ->and($status->isFinalized())->toBeFalse()
        ->and($status->purgeRequestedAt())->toBeNull()
        ->and($master)->toBeInstanceOf(MasterInfo::class)
        ->and($master?->state())->toBe(MasterInfo::STATE_QUEUED)
        ->and($master?->isFinished())->toBeFalse()
        ->and($master?->sources())->toBe([MP_SOURCE_A, MP_SOURCE_B])
        ->and($master?->captureContainer())->toBe('mpegts')
        ->and($master?->error())->toBeNull()
        ->and($master?->target())->toBeInstanceOf(MasterTarget::class)
        ->and($master?->target()->key())->toBe('shows/2041/show-2041-main-master.mp4')
        ->and($master?->target()->authorization())->toBe(MasterTarget::AUTHORIZATION_CONFIGURED)
        ->and($master?->target()->credentialProfile())->toBe('tsp-media');
});

it('omits the reason it was not given, and tells a retry from the first request', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createMaster.json'), 200)]);

    $status = Tmesis::node()->artifacts()->createMaster(MP_STREAM, MasterRequest::make(
        [MP_SOURCE_A],
        PlanTarget::recordingMaster('tsp-media', 'k.mp4'),
    ));

    expect($status->created)->toBeFalse();

    Http::assertSent(fn (Request $sent): bool => ! array_key_exists('reason', mpBody($sent))
        && is_string(mpBody($sent)['request_id'] ?? null)
        && strlen((string) mpBody($sent)['request_id']) === 26);
});

it('reads master() as null on every kind but recording_master', function (): void {
    expect(ArtifactStatus::fromArray($this->fixtureJson('responses/getArtifact.json'))->master())->toBeNull();
});

it('maps the master refusals to their exceptions with the node\'s code', function (int $status, string $code, string $class): void {
    Http::fake(['*' => Http::response(mpError($code), $status)]);

    try {
        Tmesis::node()->artifacts()->createMaster(MP_STREAM, MasterRequest::make([MP_SOURCE_A], PlanTarget::recordingMaster('tsp-media', 'k.mp4')));
        $this->fail("{$code} threw nothing.");
    } catch (ConflictException|ValidationException|UnavailableException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->code())->toBe($code);
    }
})->with([
    'master_disabled' => [409, 'master_disabled', ConflictException::class],
    'artifact_not_finalized' => [409, 'artifact_not_finalized', ConflictException::class],
    'request_conflict' => [409, 'request_conflict', ConflictException::class],
    'invalid_source' => [422, 'invalid_source', ValidationException::class],
    'sources_incompatible' => [422, 'sources_incompatible', ValidationException::class],
    'master_unavailable' => [503, 'master_unavailable', UnavailableException::class],
    'journal_unavailable' => [503, 'journal_unavailable', UnavailableException::class],
]);

it('purges a stream generation with a release body, and tells a first purge from a duplicate', function (int $status, bool $recorded): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/purgeArtifact.json'), $status)]);

    $decision = Tmesis::node()->artifacts()->purgeArtifact(
        MP_STREAM,
        MP_SOURCE_A,
        ReleaseRequest::make(2, MP_INVENTORY, 'Copied to tsp-media; free the disk now', '01J8ZCR2N5T8QWX1VM7RKB3PD4'),
    );

    expect($decision->recorded)->toBe($recorded)
        ->and($decision->purgeRequestedAt()?->format('H:i'))->toBe('22:20')
        ->and($decision->purgeReason())->toBe('Copied to tsp-media; free the disk now');

    Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
        && $sent->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/artifacts/'.MP_SOURCE_A.'/purge'
        && mpBody($sent) === [
            'request_id' => '01J8ZCR2N5T8QWX1VM7RKB3PD4',
            'plan_revision' => 2,
            'inventory_sha256' => MP_INVENTORY,
            'reason' => 'Copied to tsp-media; free the disk now',
        ]);
})->with([
    '202 recorded' => [202, true],
    '200 duplicate' => [200, false],
]);

it('purges an abandoned generation with revision 0 and an empty inventory', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/purgeArtifact.json'), 202)]);

    Tmesis::node()->artifacts()->purgeArtifact(MP_STREAM, MP_SOURCE_A, ReleaseRequest::purgeAbandoned('Crashed window', '01J8ZCR2N5T8QWX1VM7RKB3PD9'));

    Http::assertSent(fn (Request $sent): bool => $sent->body() === '{"request_id":"01J8ZCR2N5T8QWX1VM7RKB3PD9","plan_revision":0,"inventory_sha256":"","reason":"Crashed window"}');
});

it('purges a job generation under the job\'s artifacts', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/purgeJobArtifact.json'), 202)]);

    $decision = Tmesis::node()->jobArtifacts()->purgeJobArtifact(MP_JOB, MP_SOURCE_A, ReleaseRequest::make(1, MP_INVENTORY, 'Delivered; purge the transcoder'));

    expect($decision->purgeReason())->toBe('Delivered; purge the transcoder');

    Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://127.0.0.1:7000/api/v1/transcode/jobs/'.MP_JOB.'/artifacts/'.MP_SOURCE_A.'/purge');
});

it('maps the purge conflicts to ConflictException with the node\'s code', function (string $code): void {
    Http::fake(['*' => Http::response(mpError($code), 409)]);

    try {
        Tmesis::node()->artifacts()->purgeArtifact(MP_STREAM, MP_SOURCE_A, ReleaseRequest::make(1, MP_INVENTORY, 'now'));
        $this->fail("{$code} threw nothing.");
    } catch (ConflictException $e) {
        expect($e->code())->toBe($code);
    }
})->with(['artifact_held', 'transfer_incomplete', 'release_conflict', 'artifact_not_finalized']);

it('refuses a master body the node refuses on the request alone, before any request', function (Closure $build, string $key): void {
    Http::fake();

    expect($build)->toThrow(InvalidConfigurationException::class, $key);

    Http::assertNothingSent();
})->with([
    'no sources' => [fn () => MasterRequest::make([], PlanTarget::recordingMaster('d', 'k.mp4')), '[sources]'],
    '65 sources' => [fn () => MasterRequest::make(array_fill(0, 65, MP_SOURCE_A), PlanTarget::recordingMaster('d', 'k.mp4')), '[sources]'],
    'not a master target' => [fn () => MasterRequest::make([MP_SOURCE_A], PlanTarget::recordingIngest('d', 'k.ts')), '[target.artifact]'],
    'initial authorizations' => [fn () => MasterRequest::make([MP_SOURCE_A], PlanTarget::recordingMaster(
        SignedDestination::make('us-east-1', 'b', authorizations: [InitialAuthorization::make(
            'master.mp4',
            'k.mp4',
            AuthorizationBundle::make(put: SignedRequest::make('PUT', 'https://b.example.net/k.mp4?X-Amz-Signature=s', [], '2026-09-22T22:30:00.000Z')),
        )]),
        'k.mp4',
    )), '[target.signed.authorizations]'],
    'a reason over 1024 bytes' => [fn () => MasterRequest::make([MP_SOURCE_A], PlanTarget::recordingMaster('d', 'k.mp4'))->withReason(str_repeat('x', 1025)), '[reason]'],
    'an abandoned purge without a reason' => [fn () => ReleaseRequest::purgeAbandoned(''), '[reason]'],
]);
