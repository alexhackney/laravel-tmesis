<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\MasterInfo;
use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

/*
 * FakeTmesis for the tmesis v0.8.0 surface: the told master, the operator purge and the
 * stream's caption keyterms.
 */

const FM_STREAM = 'show-2041-main';

const FM_PART_0 = '01J8ZCK5R7V2QNX8RW4TKB6PD2';

const FM_PART_1 = '01J8ZCM1T8V3QNX7RW2KPB4DF9';

const FM_REPLAY = '01J8ZCN2T8V3QNX7RW2KPB4DF0';

function fmValid(object $dto, string $component): void
{
    /** @var array<string, mixed> $raw */
    $raw = $dto->toArray();

    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($raw), false), "/components/schemas/{$component}", "The fake's {$component}");
}

function fmMaster(?string $requestId = '01J8ZCQ3K9R4TWX2VN6MPB8DF1'): MasterRequest
{
    return MasterRequest::make(
        [FM_PART_1, FM_PART_0],
        PlanTarget::recordingMaster('tsp-media', 'shows/2041/master.mp4', '01J8ZCQ3P6N1QWX8VM4RKB2PD7'),
        $requestId,
    )->withReason('Show 2041 ended');
}

beforeEach(function (): void {
    $this->fake = Tmesis::fake()
        ->artifactFinalized(FM_STREAM, FM_PART_0, ArtifactStatus::KIND_RECORDING_INGEST)
        ->artifactFinalized(FM_STREAM, FM_PART_1, ArtifactStatus::KIND_RECORDING_INGEST)
        ->artifactFinalized(FM_STREAM, FM_REPLAY);
});

it('queues a master over finalized parts, joined in recording order, and holds the parts master_pending', function (): void {
    Http::fake();

    $status = Tmesis::node()->artifacts()->createMaster(FM_STREAM, fmMaster());
    fmValid($status, 'ArtifactStatus');

    expect($status->created)->toBeTrue()
        ->and($status->kind())->toBe(ArtifactStatus::KIND_RECORDING_MASTER)
        ->and($status->master()?->state())->toBe(MasterInfo::STATE_QUEUED)
        ->and($status->master()?->sources())->toBe([FM_PART_0, FM_PART_1])
        ->and($status->master()?->target()->key())->toBe('shows/2041/master.mp4')
        ->and($status->master()?->reason())->toBe('Show 2041 ended')
        ->and(Tmesis::node()->artifacts()->getArtifact(FM_STREAM, FM_PART_0)->holdReasons())->toContain('master_pending');

    $this->fake->assertCalled('createMaster')
        ->assertMasterRequested(FM_STREAM, fn (array $master): bool => $master['target']['key'] === 'shows/2041/master.mp4'
            && $master['sources'] === [FM_PART_0, FM_PART_1]);

    Http::assertNothingSent();
});

it('answers an exact retry with the same generation and a changed body with request_conflict', function (): void {
    $request = fmMaster();
    $first = Tmesis::node()->artifacts()->createMaster(FM_STREAM, $request);
    $retry = Tmesis::node()->artifacts()->createMaster(FM_STREAM, $request);

    expect($retry->created)->toBeFalse()
        ->and($retry->id())->toBe($first->id());

    $other = MasterRequest::make([FM_PART_0], PlanTarget::recordingMaster('tsp-media', 'other.mp4'), $request->requestId());

    expect(fn () => Tmesis::node()->artifacts()->createMaster(FM_STREAM, $other))
        ->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('request_conflict'));
});

it('refuses sources it cannot use with invalid_source, naming each', function (): void {
    try {
        Tmesis::node()->artifacts()->createMaster(FM_STREAM, MasterRequest::make(
            [FM_PART_0, FM_REPLAY, '01J8ZCZZZZZZZZZZZZZZZZZZZZ', FM_PART_0],
            PlanTarget::recordingMaster('tsp-media', 'k.mp4'),
        ));
        $this->fail('invalid sources were accepted');
    } catch (ValidationException $e) {
        expect($e->code())->toBe('invalid_source')
            ->and(array_map(fn ($d): string => $d->path().' '.$d->message(), $e->details()))->toBe([
                'sources[1] not recording_ingest',
                'sources[2] unknown',
                'sources[3] listed twice',
            ]);
    }
});

it('finishes a master: finalized, its told target filed as revision 1, the parts free to purge', function (): void {
    $master = Tmesis::node()->artifacts()->createMaster(FM_STREAM, fmMaster());

    $this->fake->startMaster(FM_STREAM, $master->id());

    expect(Tmesis::node()->artifacts()->getArtifact(FM_STREAM, $master->id())->master()?->state())->toBe(MasterInfo::STATE_RUNNING);

    $this->fake->finishMaster(FM_STREAM, $master->id());

    $done = Tmesis::node()->artifacts()->getArtifact(FM_STREAM, $master->id());
    $plan = Tmesis::node()->artifacts()->getTransferPlan(FM_STREAM, $master->id());
    fmValid($done, 'ArtifactStatus');

    expect($done->isFinalized())->toBeTrue()
        ->and($done->master()?->state())->toBe(MasterInfo::STATE_COMPLETED)
        ->and($done->planRevision())->toBe(1)
        ->and($plan->targets()[0]->key())->toBe('shows/2041/master.mp4')
        ->and(Tmesis::node()->artifacts()->getArtifact(FM_STREAM, FM_PART_0)->holdReasons())->not->toContain('master_pending');

    // Nothing auto-releases: the master is a generation like any other.
    $this->fake->completeTransfer(FM_STREAM, $master->id(), '01J8ZCQ3P6N1QWX8VM4RKB2PD7');
    Tmesis::node()->artifacts()->releaseArtifact(FM_STREAM, $master->id(), ReleaseRequest::forPlan($plan, 'Master copied'));

    $this->fake->assertReleased(FM_STREAM, $master->id());
});

it('fails a master with its error and frees the parts', function (): void {
    $master = Tmesis::node()->artifacts()->createMaster(FM_STREAM, fmMaster());

    $this->fake->failMaster(FM_STREAM, $master->id(), 'sources_incompatible at the remux');

    $failed = Tmesis::node()->artifacts()->getArtifact(FM_STREAM, $master->id());

    expect($failed->master()?->state())->toBe(MasterInfo::STATE_FAILED)
        ->and($failed->master()?->error())->toBe('sources_incompatible at the remux')
        ->and($failed->master()?->isFinished())->toBeTrue();
});

it('refuses to purge a part a pending master holds, with artifact_held', function (): void {
    Tmesis::node()->artifacts()->createMaster(FM_STREAM, fmMaster());

    $inventory = (string) Tmesis::node()->artifacts()->getArtifact(FM_STREAM, FM_PART_0)->inventorySha256();

    expect(fn () => Tmesis::node()->artifacts()->purgeArtifact(FM_STREAM, FM_PART_0, ReleaseRequest::make(0, $inventory, 'now')))
        ->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('artifact_held'));
});

it('purges: releases the generation, records the purge, drops the retention hold, and answers a duplicate', function (): void {
    $artifacts = Tmesis::node()->artifacts();
    $inventory = (string) $artifacts->getArtifact(FM_STREAM, FM_REPLAY)->inventorySha256();
    $purge = ReleaseRequest::make(0, $inventory, 'Copied elsewhere; free the disk');

    $decision = $artifacts->purgeArtifact(FM_STREAM, FM_REPLAY, $purge);
    fmValid($decision, 'DecisionStatus');

    $status = $artifacts->getArtifact(FM_STREAM, FM_REPLAY);
    fmValid($status, 'ArtifactStatus');

    expect($decision->recorded)->toBeTrue()
        ->and($decision->action())->toBe(DecisionStatus::ACTION_RELEASE)
        ->and($decision->purgeReason())->toBe('Copied elsewhere; free the disk')
        ->and($decision->purgeRequestedAt())->not->toBeNull()
        ->and($status->isPurged())->toBeTrue()
        ->and($status->holdReasons())->not->toContain('retention_not_elapsed')
        ->and($artifacts->purgeArtifact(FM_STREAM, FM_REPLAY, $purge)->recorded)->toBeFalse();

    $this->fake->assertPurged(FM_STREAM, FM_REPLAY)->assertReleased(FM_STREAM, FM_REPLAY);

    expect(fn () => $artifacts->purgeArtifact(FM_STREAM, FM_REPLAY, ReleaseRequest::make(0, $inventory, 'again')))
        ->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('release_conflict'));
});

it('purges a generation that already has a release, and an abandoned one with purgeAbandoned()', function (): void {
    $artifacts = Tmesis::node()->artifacts();
    $inventory = (string) $artifacts->getArtifact(FM_STREAM, FM_PART_1)->inventorySha256();
    $artifacts->releaseArtifact(FM_STREAM, FM_PART_1, ReleaseRequest::make(0, $inventory, 'released'));

    $artifacts->purgeArtifact(FM_STREAM, FM_PART_1, ReleaseRequest::make(0, $inventory, 'purge it'));
    $this->fake->assertPurged(FM_STREAM, FM_PART_1);

    $this->fake->artifactAbandoned(FM_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PZZ');
    $discard = $artifacts->purgeArtifact(FM_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PZZ', ReleaseRequest::purgeAbandoned('Crashed window'));

    expect($discard->action())->toBe(DecisionStatus::ACTION_DISCARD);
    $this->fake->assertPurged(FM_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PZZ');
});

it('fails assertPurged on a generation only released', function (): void {
    $inventory = (string) Tmesis::node()->artifacts()->getArtifact(FM_STREAM, FM_REPLAY)->inventorySha256();
    Tmesis::node()->artifacts()->releaseArtifact(FM_STREAM, FM_REPLAY, ReleaseRequest::make(0, $inventory, 'released'));

    expect(fn () => $this->fake->assertPurged(FM_STREAM, FM_REPLAY))->toThrow(AssertionFailedError::class);
});

it('purges a job generation through jobArtifacts()', function (): void {
    $this->fake->artifactFinalizedForJob('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB', '01J8ZCP1T8V3QNX7RW2KPB4DF0', ArtifactStatus::KIND_JOB_MP4);
    $inventory = (string) Tmesis::node()->jobArtifacts()->getJobArtifact('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB', '01J8ZCP1T8V3QNX7RW2KPB4DF0')->inventorySha256();

    Tmesis::node()->jobArtifacts()->purgeJobArtifact('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB', '01J8ZCP1T8V3QNX7RW2KPB4DF0', ReleaseRequest::make(0, $inventory, 'Delivered'));

    $this->fake->assertCalled('purgeJobArtifact')->assertJobArtifactPurged('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB', '01J8ZCP1T8V3QNX7RW2KPB4DF0');

    expect(fn () => Tmesis::node()->artifacts()->purgeArtifact(FM_STREAM, '01J8ZCP1T8V3QNX7RW2KPB4DF0', ReleaseRequest::make(0, $inventory, 'x')))
        ->toThrow(NotFoundException::class);
});

it('records a signed master target without a credential, because MasterRequest refuses initial authorizations', function (): void {
    Tmesis::node()->artifacts()->createMaster(FM_STREAM, MasterRequest::make(
        [FM_PART_0],
        PlanTarget::recordingMaster(SignedDestination::make('us-east-1', 'backup', 'https://archive.example.net'), 'backup/master.mp4'),
    ));

    $this->fake->assertMasterRequested(FM_STREAM, fn (array $master): bool => $master['target']['authorization'] === 'signed'
        && $master['target']['credential_profile'] === null
        && $master['target']['bucket'] === 'backup');
});

it('stores the stream\'s caption keyterms as the node does, and clears them', function (): void {
    $record = Tmesis::node()->streams()->createStream('show-2042-main', StreamSpec::make()->captionsKeyterms([' Ilia Topuria', 'Atlanta', 'atlanta']));
    fmValid($record->captions(), 'CaptionsRecord');

    expect($record->captions()->keyterms())->toBe(['Ilia Topuria', 'Atlanta'])
        ->and(Tmesis::node()->streams()->createStream('show-2043-main', StreamSpec::make())->captions()->keyterms())->toBe([]);

    // A next_segment change, so a PATCH reports it pending, as the node does.
    $patch = Tmesis::node()->streams()->updateStream('show-2042-main', StreamSpec::make()->captionsKeyterms(null));

    expect($patch->pending())->toHaveKey('captions.keyterms');
});

it('refuses to purge or discard a queued or running master, and discards a failed one (review 6)', function (): void {
    $artifacts = Tmesis::node()->artifacts();
    $master = $artifacts->createMaster(FM_STREAM, fmMaster());

    $refused = fn () => $artifacts->purgeArtifact(FM_STREAM, $master->id(), ReleaseRequest::purgeAbandoned('not yet'));

    expect($refused)->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('artifact_not_finalized'));

    $this->fake->startMaster(FM_STREAM, $master->id());

    expect($refused)->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('artifact_not_finalized'))
        ->and(fn () => $artifacts->overrideArtifact(FM_STREAM, $master->id(), OverrideRequest::discardAbandoned('not yet')))
        ->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('artifact_not_finalized'));

    $this->fake->assertNotReleased(FM_STREAM, $master->id());

    $this->fake->failMaster(FM_STREAM, $master->id());

    expect($artifacts->purgeArtifact(FM_STREAM, $master->id(), ReleaseRequest::purgeAbandoned('failed remux'))->action())
        ->toBe(DecisionStatus::ACTION_DISCARD);
    $this->fake->assertPurged(FM_STREAM, $master->id());
});

it('registers a finished master only when its told target completes (review 7)', function (): void {
    $artifacts = Tmesis::node()->artifacts();
    $master = $artifacts->createMaster(FM_STREAM, fmMaster());
    $this->fake->finishMaster(FM_STREAM, $master->id());

    $finished = $artifacts->getArtifact(FM_STREAM, $master->id());
    fmValid($finished, 'ArtifactStatus');

    expect($finished->isFinalized())->toBeTrue()
        ->and($finished->registeredAt())->toBeNull()
        ->and($finished->retentionExpiresAt())->toBeNull()
        ->and($finished->holdReasons())->toContain('retention_clock_unknown')
        ->and($finished->holdReasons())->not->toContain('retention_not_elapsed');

    $this->fake->completeTransfer(FM_STREAM, $master->id(), '01J8ZCQ3P6N1QWX8VM4RKB2PD7');

    $registered = $artifacts->getArtifact(FM_STREAM, $master->id());

    expect($registered->registeredAt())->not->toBeNull()
        ->and($registered->retentionExpiresAt())->not->toBeNull()
        ->and($registered->holdReasons())->toContain('retention_not_elapsed')
        ->and($registered->holdReasons())->not->toContain('retention_clock_unknown');
});

it('resolves an untuned signed master target to the node\'s defaults, the same before and after completion (review 8)', function (): void {
    $artifacts = Tmesis::node()->artifacts();
    $master = $artifacts->createMaster(FM_STREAM, MasterRequest::make(
        [FM_PART_0],
        PlanTarget::recordingMaster(SignedDestination::make('us-east-1', 'backup'), 'backup/master.mp4', '01J8ZCQ3P6N1QWX8VM4RKB2PD8'),
    ));

    $accepted = $master->master()?->target();

    expect($accepted?->uploadConcurrency())->toBe(8)
        ->and($accepted?->partSizeBytes())->toBe(16 * 1048576)
        ->and($accepted?->addressing())->toBe('auto');

    $this->fake->finishMaster(FM_STREAM, $master->id());

    $planned = $artifacts->getTransferPlan(FM_STREAM, $master->id())->targets()[0];
    $completed = $artifacts->getArtifact(FM_STREAM, $master->id())->master()?->target();

    expect($planned->uploadConcurrency())->toBe($accepted?->uploadConcurrency())
        ->and($planned->partSizeBytes())->toBe($accepted?->partSizeBytes())
        ->and($completed?->toArray())->toBe($accepted?->toArray());
});

it('refuses a held generation purged with a revision or an inventory, with artifact_identity_mismatch (LTW1 F1)', function (): void {
    $artifacts = Tmesis::node()->artifacts();
    $this->fake->artifactAbandoned(FM_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PZZ');

    foreach ([ReleaseRequest::make(0, str_repeat('a', 64), 'wrong shape'), ReleaseRequest::make(1, str_repeat('a', 64), 'wrong shape')] as $purge) {
        expect(fn () => $artifacts->purgeArtifact(FM_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PZZ', $purge))
            ->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('artifact_identity_mismatch'));
    }

    $master = $artifacts->createMaster(FM_STREAM, fmMaster());

    expect(fn () => $artifacts->purgeArtifact(FM_STREAM, $master->id(), ReleaseRequest::make(0, str_repeat('a', 64), 'wrong shape')))
        ->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('artifact_identity_mismatch'));

    $this->fake->assertNotReleased(FM_STREAM, '01J8ZCK5R7V2QNX8RW4TKB6PZZ');
});

it('answers a master on a stream it does not know with 404, before anything else (LTW1 F2)', function (): void {
    $artifacts = Tmesis::node()->artifacts();

    expect(fn () => $artifacts->createMaster('no-such-stream', fmMaster()))
        ->toThrow(fn (NotFoundException $e) => expect($e->code())->toBe('not_found'));

    // A stream the fake holds but with no generations still reaches the source checks.
    Tmesis::node()->streams()->createStream('empty-stream');

    expect(fn () => $artifacts->createMaster('empty-stream', fmMaster('01J8ZCQ3K9R4TWX2VN6MPB8DF2')))
        ->toThrow(fn (ValidationException $e) => expect($e->code())->toBe('invalid_source'));
});

it('answers an exact purge retry with its decision even when a later hold stands (tmesis 0.8.1)', function (): void {
    $artifacts = Tmesis::node()->artifacts();
    $inventory = (string) $artifacts->getArtifact(FM_STREAM, FM_PART_1)->inventorySha256();
    $purge = ReleaseRequest::make(0, $inventory, 'Copied; free the disk');

    $first = $artifacts->purgeArtifact(FM_STREAM, FM_PART_1, $purge);

    // On the node a master can queue off the source in the seconds before its
    // retirement begins; the fake refuses a purged source, so the hold is seeded.
    $master = $artifacts->createMaster(FM_STREAM, MasterRequest::make([FM_PART_0], PlanTarget::recordingMaster('tsp-media', 'shows/2041/master.mp4')));
    $ledger = $this->fake->artifactLedger();
    Closure::bind(function () use ($master): void {
        $this->generations[$master->id()]['status']['master']['sources'][] = FM_PART_1;
    }, $ledger, $ledger::class)();

    expect($artifacts->getArtifact(FM_STREAM, FM_PART_1)->holdReasons())->toContain('master_pending');

    $retry = $artifacts->purgeArtifact(FM_STREAM, FM_PART_1, $purge);

    expect($retry->recorded)->toBeFalse()
        ->and($retry->requestId())->toBe($first->requestId())
        ->and($retry->purgeRequestedAt())->toEqual($first->purgeRequestedAt())
        ->and(fn () => $artifacts->purgeArtifact(FM_STREAM, FM_PART_1, ReleaseRequest::make(0, $inventory, 'a new request')))
        ->toThrow(fn (ConflictException $e) => expect($e->code())->toBe('artifact_held'));
});
