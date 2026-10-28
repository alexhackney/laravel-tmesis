<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Artifacts\ArtifactPage;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryPage;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\JobArtifacts;

/**
 * A job's generations, answered from FakeTmesis's artifact ledger under the job's
 * owner key: the same rules as FakeArtifactsResource, so a release of a job generation
 * counts toward `assertEveryFinalizedGenerationReleased()`.
 *
 * Recorded calls name `jobId` where the stream routes name `streamId`, and never
 * carry a signed URL or header value.
 */
class FakeJobArtifactsResource extends JobArtifacts
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function listJobArtifacts(string $jobId, ?int $limit = null, ?string $cursor = null): ArtifactPage
    {
        Artifacts::guardLimit($limit);

        $this->fake->record('listJobArtifacts', $this->node, ['jobId' => $jobId, 'limit' => $limit, 'cursor' => $cursor]);

        return $this->ledger()->listArtifacts(FakeArtifactLedger::jobOwner($jobId), $limit, $cursor);
    }

    public function getJobArtifact(string $jobId, string $generation): ArtifactStatus
    {
        $this->fake->record('getJobArtifact', $this->node, ['jobId' => $jobId, 'generation' => $generation]);

        return $this->ledger()->getArtifact(FakeArtifactLedger::jobOwner($jobId), $generation);
    }

    public function getJobArtifactInventory(string $jobId, string $generation, ?int $limit = null, ?string $cursor = null): InventoryPage
    {
        Artifacts::guardLimit($limit);

        $this->fake->record('getJobArtifactInventory', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->ledger()->getInventory(FakeArtifactLedger::jobOwner($jobId), $generation, $limit, $cursor);
    }

    public function getJobTransferPlan(string $jobId, string $generation): PlanStatus
    {
        $this->fake->record('getJobTransferPlan', $this->node, ['jobId' => $jobId, 'generation' => $generation]);

        return $this->ledger()->getPlan(FakeArtifactLedger::jobOwner($jobId), $generation);
    }

    public function replaceJobTransferPlan(string $jobId, string $generation, PlanRequest $plan): PlanStatus
    {
        $this->fake->record('replaceJobTransferPlan', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'plan' => FakeArtifactLedger::redactPlan($plan),
        ]);

        return $this->ledger()->replacePlan(FakeArtifactLedger::jobOwner($jobId), $generation, $plan);
    }

    public function listJobTransfers(string $jobId, string $generation, ?int $limit = null, ?string $cursor = null): TransferPage
    {
        Artifacts::guardLimit($limit);

        $this->fake->record('listJobTransfers', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->ledger()->listTransfers(FakeArtifactLedger::jobOwner($jobId), $generation, $limit, $cursor);
    }

    public function listJobTransferParts(string $jobId, string $generation, string $objectId, ?int $limit = null, ?string $cursor = null): PartPage
    {
        Artifacts::guardLimit($limit);

        $this->fake->record('listJobTransferParts', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'objectId' => $objectId,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->ledger()->listParts(FakeArtifactLedger::jobOwner($jobId), $generation, $objectId, $limit, $cursor);
    }

    public function supplyJobTransferAuthorization(string $jobId, string $generation, string $objectId, AuthorizationRequest $authorization): ObjectStatus
    {
        $this->fake->record('supplyJobTransferAuthorization', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'objectId' => $objectId,
            'plan_revision' => $authorization->planRevision(),
            'target_id' => $authorization->targetId(),
            'object_key' => $authorization->objectKey(),
            'authorization_revision' => $authorization->authorizationRevision(),
            'supplied' => $authorization->requests()->supplied(),
        ]);

        return $this->ledger()->authorize(FakeArtifactLedger::jobOwner($jobId), $generation, $objectId, $authorization);
    }

    public function releaseJobArtifact(string $jobId, string $generation, ReleaseRequest $release): DecisionStatus
    {
        $this->fake->record('releaseJobArtifact', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'decision' => $release->toArray(),
        ]);

        return $this->ledger()->release(FakeArtifactLedger::jobOwner($jobId), $generation, $release);
    }

    public function purgeJobArtifact(string $jobId, string $generation, ReleaseRequest $purge): DecisionStatus
    {
        $this->fake->record('purgeJobArtifact', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'decision' => $purge->toArray(),
        ]);

        return $this->ledger()->purge(FakeArtifactLedger::jobOwner($jobId), $generation, $purge);
    }

    public function overrideJobArtifact(string $jobId, string $generation, OverrideRequest $override): DecisionStatus
    {
        $this->fake->record('overrideJobArtifact', $this->node, [
            'jobId' => $jobId,
            'generation' => $generation,
            'decision' => $override->toArray(),
        ]);

        return $this->ledger()->override(FakeArtifactLedger::jobOwner($jobId), $generation, $override);
    }

    private function ledger(): FakeArtifactLedger
    {
        return $this->fake->artifactLedger();
    }
}
