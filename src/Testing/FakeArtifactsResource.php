<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Artifacts\ArtifactPage;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryPage;
use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use AlexHackney\Tmesis\Resources\Artifacts;

/**
 * Artifact reads and writes, answered from FakeTmesis's artifact ledger.
 *
 * The `all*()` walkers are the real resource's, following the fake's cursors. A
 * recorded call never carries a signed URL or header value: a plan's initial
 * authorizations and a refresh's bundle are recorded as the operations they supply.
 */
class FakeArtifactsResource extends Artifacts
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function listArtifacts(string $streamId, ?int $limit = null, ?string $cursor = null): ArtifactPage
    {
        self::guardLimit($limit);

        $this->fake->record('listArtifacts', $this->node, ['streamId' => $streamId, 'limit' => $limit, 'cursor' => $cursor]);

        return $this->fake->artifactLedger()->listArtifacts($streamId, $limit, $cursor);
    }

    public function getArtifact(string $streamId, string $generation): ArtifactStatus
    {
        $this->fake->record('getArtifact', $this->node, ['streamId' => $streamId, 'generation' => $generation]);

        return $this->fake->artifactLedger()->getArtifact($streamId, $generation);
    }

    public function getArtifactInventory(string $streamId, string $generation, ?int $limit = null, ?string $cursor = null): InventoryPage
    {
        self::guardLimit($limit);

        $this->fake->record('getArtifactInventory', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->fake->artifactLedger()->getInventory($streamId, $generation, $limit, $cursor);
    }

    public function getTransferPlan(string $streamId, string $generation): PlanStatus
    {
        $this->fake->record('getTransferPlan', $this->node, ['streamId' => $streamId, 'generation' => $generation]);

        return $this->fake->artifactLedger()->getPlan($streamId, $generation);
    }

    public function replaceTransferPlan(string $streamId, string $generation, PlanRequest $plan): PlanStatus
    {
        $this->fake->record('replaceTransferPlan', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'plan' => FakeArtifactLedger::redactPlan($plan),
        ]);

        return $this->fake->artifactLedger()->replacePlan($streamId, $generation, $plan);
    }

    public function listTransfers(string $streamId, string $generation, ?int $limit = null, ?string $cursor = null): TransferPage
    {
        self::guardLimit($limit);

        $this->fake->record('listTransfers', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->fake->artifactLedger()->listTransfers($streamId, $generation, $limit, $cursor);
    }

    public function listTransferParts(string $streamId, string $generation, string $objectId, ?int $limit = null, ?string $cursor = null): PartPage
    {
        self::guardLimit($limit);

        $this->fake->record('listTransferParts', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'objectId' => $objectId,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);

        return $this->fake->artifactLedger()->listParts($streamId, $generation, $objectId, $limit, $cursor);
    }

    public function supplyTransferAuthorization(string $streamId, string $generation, string $objectId, AuthorizationRequest $authorization): ObjectStatus
    {
        $this->fake->record('supplyTransferAuthorization', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'objectId' => $objectId,
            'plan_revision' => $authorization->planRevision(),
            'target_id' => $authorization->targetId(),
            'object_key' => $authorization->objectKey(),
            'authorization_revision' => $authorization->authorizationRevision(),
            'supplied' => $authorization->requests()->supplied(),
        ]);

        return $this->fake->artifactLedger()->authorize($streamId, $generation, $objectId, $authorization);
    }

    public function releaseArtifact(string $streamId, string $generation, ReleaseRequest $release): DecisionStatus
    {
        $this->fake->record('releaseArtifact', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'decision' => $release->toArray(),
        ]);

        return $this->fake->artifactLedger()->release($streamId, $generation, $release);
    }

    public function purgeArtifact(string $streamId, string $generation, ReleaseRequest $purge): DecisionStatus
    {
        $this->fake->record('purgeArtifact', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'decision' => $purge->toArray(),
        ]);

        return $this->fake->artifactLedger()->purge($streamId, $generation, $purge);
    }

    public function createMaster(string $streamId, MasterRequest $master): ArtifactStatus
    {
        $this->fake->record('createMaster', $this->node, [
            'streamId' => $streamId,
            'master' => FakeArtifactLedger::redactMaster($master),
        ]);

        return $this->fake->artifactLedger()->createMaster($streamId, $master, $this->fake->knowsStream($streamId));
    }

    public function overrideArtifact(string $streamId, string $generation, OverrideRequest $override): DecisionStatus
    {
        $this->fake->record('overrideArtifact', $this->node, [
            'streamId' => $streamId,
            'generation' => $generation,
            'decision' => $override->toArray(),
        ]);

        return $this->fake->artifactLedger()->override($streamId, $generation, $override);
    }
}
