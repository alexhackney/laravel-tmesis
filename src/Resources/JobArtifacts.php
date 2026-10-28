<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactPage;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryEntry;
use AlexHackney\Tmesis\Data\Artifacts\InventoryPage;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PartStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use Illuminate\Support\LazyCollection;

/**
 * A transcode job's output generations: the job-scoped mirror of Artifacts (tmesis
 * `v0.6.0`, `docs/API.md` 12a.1 and 9.10). Method names are the openapi operationIds;
 * every route is under `/api/v1/transcode/jobs/{id}/artifacts`, and the bodies and
 * answers are the stream routes' own.
 *
 * Each requested output is its own generation (kinds `job_hls`, `job_mp4`,
 * `job_audio`, `job_thumbnails`, `job_transcript`, `job_probe`), finalized with a
 * frozen inventory, and the targets the Spec named for it are filed BY THE NODE as its
 * revision-1 plan, under the submitting credential. A caller re-plans with
 * replaceJobTransferPlan() only to change where it goes.
 *
 * **Nothing auto-releases.** A job completes when every required output LANDED, and
 * its generations stay held on the node until each is released: after its
 * `transfer.completed` (or `job.completed`), call releaseJobArtifact() for it, or the
 * transcoder's disk fills. Everything Artifacts says about the release, the stale
 * guard, idempotency, cursors and the `409` codes holds here unchanged. A generation
 * named under a job it does not belong to is `404`.
 *
 * Signed URLs and headers go OUT in supplyJobTransferAuthorization() and never come
 * back; the package's TransferSigner seam routes a job generation's
 * `transfer.authorization_required` here by itself. Nothing here logs a request body.
 */
class JobArtifacts
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /api/v1/transcode/jobs/:id/artifacts: one page of the job's generations,
     * `(created_at, id)` ascending, held ones included.
     */
    public function listJobArtifacts(string $jobId, ?int $limit = null, ?string $cursor = null): ArtifactPage
    {
        Artifacts::guardLimit($limit);

        return $this->routes($jobId)->list($limit, $cursor);
    }

    /**
     * Every generation of the job, following the cursor.
     *
     * @return LazyCollection<int, ArtifactStatus>
     */
    public function allJobArtifacts(string $jobId, ?int $limit = null): LazyCollection
    {
        Artifacts::guardLimit($limit);

        return ArtifactRoutes::walk(function (?string $cursor) use ($jobId, $limit): array {
            $page = $this->listJobArtifacts($jobId, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * GET /api/v1/transcode/jobs/:id/artifacts/:generation.
     */
    public function getJobArtifact(string $jobId, string $generation): ArtifactStatus
    {
        return $this->routes($jobId)->get($generation);
    }

    /**
     * GET .../artifacts/:generation/inventory: one page, path ascending. A held
     * generation has none yet: `409 artifact_not_finalized`.
     */
    public function getJobArtifactInventory(string $jobId, string $generation, ?int $limit = null, ?string $cursor = null): InventoryPage
    {
        Artifacts::guardLimit($limit);

        return $this->routes($jobId)->inventory($generation, $limit, $cursor);
    }

    /**
     * The generation's whole inventory, following the cursor.
     *
     * @return LazyCollection<int, InventoryEntry>
     */
    public function allJobArtifactInventory(string $jobId, string $generation, ?int $limit = null): LazyCollection
    {
        Artifacts::guardLimit($limit);

        return ArtifactRoutes::walk(function (?string $cursor) use ($jobId, $generation, $limit): array {
            $page = $this->getJobArtifactInventory($jobId, $generation, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * GET .../artifacts/:generation/transfer-plan: the CURRENT revision. Revision 1 is
     * the plan the node filed from the Spec's targets at finalization.
     */
    public function getJobTransferPlan(string $jobId, string $generation): PlanStatus
    {
        return $this->routes($jobId)->plan($generation);
    }

    /**
     * PUT .../artifacts/:generation/transfer-plan: the next revision, replacing the
     * current one entirely. `PlanStatus::$created` is true on the `201` of revision 1;
     * a job generation's revision 1 is normally the node's own, so a caller's PUT is
     * a `200`. The same `409`s and `422`s as Artifacts::replaceTransferPlan().
     */
    public function replaceJobTransferPlan(string $jobId, string $generation, PlanRequest $plan): PlanStatus
    {
        return $this->routes($jobId)->replacePlan($generation, $plan);
    }

    /**
     * GET .../artifacts/:generation/transfers: one page of transfer objects,
     * `(target_id, object_key)` ascending.
     */
    public function listJobTransfers(string $jobId, string $generation, ?int $limit = null, ?string $cursor = null): TransferPage
    {
        Artifacts::guardLimit($limit);

        return $this->routes($jobId)->transfers($generation, $limit, $cursor);
    }

    /**
     * Every transfer object of the current revision, following the cursor.
     *
     * @return LazyCollection<int, ObjectStatus>
     */
    public function allJobTransfers(string $jobId, string $generation, ?int $limit = null): LazyCollection
    {
        Artifacts::guardLimit($limit);

        return ArtifactRoutes::walk(function (?string $cursor) use ($jobId, $generation, $limit): array {
            $page = $this->listJobTransfers($jobId, $generation, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * GET .../transfers/:object/parts: one page of one object's parts.
     */
    public function listJobTransferParts(string $jobId, string $generation, string $objectId, ?int $limit = null, ?string $cursor = null): PartPage
    {
        Artifacts::guardLimit($limit);

        return $this->routes($jobId)->parts($generation, $objectId, $limit, $cursor);
    }

    /**
     * Every part of one object, following the cursor.
     *
     * @return LazyCollection<int, PartStatus>
     */
    public function allJobTransferParts(string $jobId, string $generation, string $objectId, ?int $limit = null): LazyCollection
    {
        Artifacts::guardLimit($limit);

        return ArtifactRoutes::walk(function (?string $cursor) use ($jobId, $generation, $objectId, $limit): array {
            $page = $this->listJobTransferParts($jobId, $generation, $objectId, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * PUT .../transfers/:object/authorization: supply signed requests for one object
     * of a signed target. The answer is safe status and never echoes a URL or header.
     */
    public function supplyJobTransferAuthorization(string $jobId, string $generation, string $objectId, AuthorizationRequest $authorization): ObjectStatus
    {
        return $this->routes($jobId)->authorize($generation, $objectId, $authorization);
    }

    /**
     * POST .../artifacts/:generation/release: release the named revision, authorising
     * EVENTUAL local cleanup. `202` first (`DecisionStatus::$recorded`), `200` for an
     * exact duplicate. `409 transfer_incomplete` until every requested copy landed.
     */
    public function releaseJobArtifact(string $jobId, string $generation, ReleaseRequest $release): DecisionStatus
    {
        return $this->routes($jobId)->release($generation, $release);
    }

    /**
     * POST .../artifacts/:generation/override: override (waive the incomplete targets)
     * or discard (waive them all). `202` first, `200` for an exact duplicate.
     */
    public function overrideJobArtifact(string $jobId, string $generation, OverrideRequest $override): DecisionStatus
    {
        return $this->routes($jobId)->override($generation, $override);
    }

    /**
     * POST .../artifacts/:generation/purge (tmesis `v0.8.0`): release if needed and
     * retire the local files now, before retention; the body is a release's
     * (`ReleaseRequest::purgeAbandoned()` for an abandoned or failed held one). `202`
     * first, `200` for an exact duplicate; `409 artifact_held`, `transfer_incomplete`,
     * `release_conflict` as Artifacts::purgeArtifact().
     */
    public function purgeJobArtifact(string $jobId, string $generation, ReleaseRequest $purge): DecisionStatus
    {
        return $this->routes($jobId)->purge($generation, $purge);
    }

    private function routes(string $jobId): ArtifactRoutes
    {
        return new ArtifactRoutes($this->transport, $this->transport->path('/api/v1/transcode/jobs/%s/artifacts', $jobId));
    }
}
