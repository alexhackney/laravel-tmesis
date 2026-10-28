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
use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PartStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Closure;
use Illuminate\Support\LazyCollection;

/**
 * Artifacts: a stream's finalized output generations, where they are copied, and the
 * decision that lets the node delete them (tmesis `v0.5.0`, `docs/API.md` 12a). Method
 * names are the openapi operationIds; every route is under
 * `/api/v1/streams/{id}/artifacts`, except createMaster() (`/api/v1/streams/{id}/masters`,
 * tmesis `v0.8.0`), which is here because what it makes and answers is a generation
 * and everything after it (progress, transfers, release, purge) is these routes.
 *
 * **Nothing leaves a box without a release.** From `v0.5.0` the node deletes a
 * generation's local files only after releaseArtifact() or overrideArtifact() on its
 * current plan revision AND once its retention has passed. A control plane that copies
 * a generation and never releases it fills the node's disk. A registration or a
 * completed transfer authorises nothing.
 *
 * A generation named under a stream it does not belong to is `404`, never a `409`.
 * The conflicts are all `409` and arrive as ConflictException with the node's code:
 * `artifact_not_finalized`, `artifact_identity_mismatch`, `transfer_plan_stale`,
 * `transfer_identity_mismatch`, `transfer_incomplete`, `request_conflict`,
 * `release_conflict`, `artifact_retiring`, `artifact_reconciliation_required`, and
 * since tmesis `v0.8.0` `artifact_held` (purge) and `master_disabled` (masters).
 *
 * Pages are `{items, limit, next_cursor}`. `limit` is 1 to 1000 at the node (above is
 * clamped, not refused), checked positive here; the cursor is opaque and bound to the
 * route, stream, generation and (transfers) revision, so a foreign one is `400` and a
 * transfers cursor from a replaced revision is `409 transfer_plan_stale`. The `all*()`
 * walkers follow `next_cursor` lazily, one request per page as the collection is read.
 *
 * Signed URLs and headers go OUT in supplyTransferAuthorization() and in a plan's
 * initial authorizations, and never come back: the node answers safe status only.
 * Nothing here logs a request body.
 */
class Artifacts
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /api/v1/streams/:id/artifacts: one page of the stream's generations,
     * `(created_at, id)` ascending, held ones included.
     */
    public function listArtifacts(string $streamId, ?int $limit = null, ?string $cursor = null): ArtifactPage
    {
        self::guardLimit($limit);

        return $this->routes($streamId)->list($limit, $cursor);
    }

    /**
     * Every generation of the stream, following the cursor.
     *
     * @return LazyCollection<int, ArtifactStatus>
     */
    public function allArtifacts(string $streamId, ?int $limit = null): LazyCollection
    {
        self::guardLimit($limit);

        return self::walk(function (?string $cursor) use ($streamId, $limit): array {
            $page = $this->listArtifacts($streamId, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * GET /api/v1/streams/:id/artifacts/:generation.
     *
     * The hold reasons are only readable here: there is no `artifact.held` webhook.
     */
    public function getArtifact(string $streamId, string $generation): ArtifactStatus
    {
        return $this->routes($streamId)->get($generation);
    }

    /**
     * GET /api/v1/streams/:id/artifacts/:generation/inventory: one page, path
     * ascending. A generation that is not finalized has none yet: `409
     * artifact_not_finalized`.
     */
    public function getArtifactInventory(string $streamId, string $generation, ?int $limit = null, ?string $cursor = null): InventoryPage
    {
        self::guardLimit($limit);

        return $this->routes($streamId)->inventory($generation, $limit, $cursor);
    }

    /**
     * The generation's whole inventory, following the cursor.
     *
     * @return LazyCollection<int, InventoryEntry>
     */
    public function allArtifactInventory(string $streamId, string $generation, ?int $limit = null): LazyCollection
    {
        self::guardLimit($limit);

        return self::walk(function (?string $cursor) use ($streamId, $generation, $limit): array {
            $page = $this->getArtifactInventory($streamId, $generation, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * GET /api/v1/streams/:id/artifacts/:generation/transfer-plan: the CURRENT
     * revision. Revision 0 is the empty baseline written at finalisation.
     *
     * Do not read this to fill a PlanRequest's `expected_revision` before every PUT:
     * that defeats the stale guard. See PlanRequest.
     */
    public function getTransferPlan(string $streamId, string $generation): PlanStatus
    {
        return $this->routes($streamId)->plan($generation);
    }

    /**
     * PUT /api/v1/streams/:id/artifacts/:generation/transfer-plan: create the next
     * revision, replacing the current one entirely.
     *
     * `201` with a `Location` for revision 1 (`PlanStatus::$created`), `200` for a
     * later revision or an exact duplicate of an accepted request (its original
     * revision, with a truthful `current()`). The `Location` is not read: it names the
     * path getTransferPlan() reads.
     *
     * `409`: `request_conflict` (the request id with another body),
     * `transfer_plan_stale` (`expected_revision` is not current),
     * `artifact_not_finalized`, `artifact_identity_mismatch` (not this generation's
     * inventory), `artifact_retiring`, and `transfer_identity_mismatch` for a bad
     * initial authorization. `422` for everything the node decides about the targets.
     */
    public function replaceTransferPlan(string $streamId, string $generation, PlanRequest $plan): PlanStatus
    {
        return $this->routes($streamId)->replacePlan($generation, $plan);
    }

    /**
     * GET /api/v1/streams/:id/artifacts/:generation/transfers: one page of transfer
     * objects, `(target_id, object_key)` ascending.
     */
    public function listTransfers(string $streamId, string $generation, ?int $limit = null, ?string $cursor = null): TransferPage
    {
        self::guardLimit($limit);

        return $this->routes($streamId)->transfers($generation, $limit, $cursor);
    }

    /**
     * Every transfer object of the current revision, following the cursor. A plan
     * replaced mid-walk makes the next page `409 transfer_plan_stale`: start again.
     *
     * @return LazyCollection<int, ObjectStatus>
     */
    public function allTransfers(string $streamId, string $generation, ?int $limit = null): LazyCollection
    {
        self::guardLimit($limit);

        return self::walk(function (?string $cursor) use ($streamId, $generation, $limit): array {
            $page = $this->listTransfers($streamId, $generation, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * GET /api/v1/streams/:id/artifacts/:generation/transfers/:object/parts: one page
     * of one object's parts, part number ascending.
     */
    public function listTransferParts(string $streamId, string $generation, string $objectId, ?int $limit = null, ?string $cursor = null): PartPage
    {
        self::guardLimit($limit);

        return $this->routes($streamId)->parts($generation, $objectId, $limit, $cursor);
    }

    /**
     * Every part of one object, following the cursor.
     *
     * @return LazyCollection<int, PartStatus>
     */
    public function allTransferParts(string $streamId, string $generation, string $objectId, ?int $limit = null): LazyCollection
    {
        self::guardLimit($limit);

        return self::walk(function (?string $cursor) use ($streamId, $generation, $objectId, $limit): array {
            $page = $this->listTransferParts($streamId, $generation, $objectId, $limit, $cursor);

            return [$page->items(), $page->nextCursor()];
        });
    }

    /**
     * PUT /api/v1/streams/:id/artifacts/:generation/transfers/:object/authorization:
     * supply signed requests for one object.
     *
     * The answer is the object's safe status and NEVER echoes a URL or header. The
     * node keeps the requests in memory only: `authorizationAvailable()` false after
     * a retry of the accepted revision means it restarted, so send the next one.
     *
     * `409`: `transfer_identity_mismatch` (a binding is not the object's, the target
     * is not signed, or the revision is neither the accepted one nor the next),
     * `request_conflict` (the accepted revision again with another bundle),
     * `transfer_plan_stale`, `artifact_not_finalized`. `422` for a request that does
     * not bind to its pinned operation or has under 30 seconds left.
     */
    public function supplyTransferAuthorization(string $streamId, string $generation, string $objectId, AuthorizationRequest $authorization): ObjectStatus
    {
        return $this->routes($streamId)->authorize($generation, $objectId, $authorization);
    }

    /**
     * POST /api/v1/streams/:id/artifacts/:generation/release: release the named
     * revision, authorising EVENTUAL local cleanup under retention and every hold.
     *
     * `202` for the first decision, `200` for an exact duplicate
     * (`DecisionStatus::$recorded` false). `409`: `transfer_incomplete` (a requested
     * copy is not complete), `release_conflict` (another decision exists for this
     * revision), `transfer_plan_stale`, `artifact_identity_mismatch`,
     * `artifact_not_finalized`, `request_conflict`, `artifact_retiring`.
     */
    public function releaseArtifact(string $streamId, string $generation, ReleaseRequest $release): DecisionStatus
    {
        return $this->routes($streamId)->release($generation, $release);
    }

    /**
     * POST /api/v1/streams/:id/artifacts/:generation/override: override (waive the
     * incomplete targets) or discard (waive them all), including the discard of an
     * abandoned generation (`OverrideRequest::discardAbandoned()`).
     *
     * `202` first, `200` for an exact duplicate, as releaseArtifact(). The same `409`s
     * but `transfer_incomplete`; `422` for an unknown, duplicate or omitted waiver.
     */
    public function overrideArtifact(string $streamId, string $generation, OverrideRequest $override): DecisionStatus
    {
        return $this->routes($streamId)->override($generation, $override);
    }

    /**
     * POST /api/v1/streams/:id/artifacts/:generation/purge (tmesis `v0.8.0`,
     * `docs/API.md` 12a.5b): release the generation if it needs it and retire its
     * local files NOW, before its retention deadline. The body is a release's:
     * make() or forPlan() for a finalized generation, `ReleaseRequest::purgeAbandoned()`
     * for an abandoned or failed held one.
     *
     * The node records the purge on the current decision (releasing an unreleased
     * generation, or discarding an abandoned or failed held one, in the same
     * transaction) and asks for a retirement pass: the local files go in seconds, and
     * `artifact.deleted` says `purge: true`. `retention_expires_at` never changes. A
     * remote copy is never touched.
     *
     * `202` recorded, `200` for an exact duplicate (`DecisionStatus::$recorded`
     * false). `409`: `artifact_held` (a hold no purge removes: `writer_active`,
     * `recovery_active`, `transfer_active`, `master_pending`, `local_integrity_failed`;
     * the details name it), `transfer_incomplete` (the release it includes needs every
     * requested copy complete), `release_conflict` (already purged under another
     * request), and the release route's others. `422` for a malformed body.
     */
    public function purgeArtifact(string $streamId, string $generation, ReleaseRequest $purge): DecisionStatus
    {
        return $this->routes($streamId)->purge($generation, $purge);
    }

    /**
     * POST /api/v1/streams/:id/masters (tmesis `v0.8.0`, `docs/API.md` 12a.8): join
     * the stream's ingest parts into one master MP4 and upload it to the one told key.
     * The answer is the new `recording_master` generation, held, with master() in
     * state `queued`; read its progress back with getArtifact().
     *
     * `202` journaled by this call (`ArtifactStatus::$created`), `200` for an exact
     * retry of the same MasterRequest (the generation as it is now). Mint the
     * MasterRequest once and resend THAT object: a new one is a new request id.
     *
     * `404` no such stream. `409`: `master_disabled` (`recording.master.enabled` is
     * off on the node; it defaults on wherever the ingest copy is on),
     * `artifact_not_finalized` (a source is still recording), `request_conflict` (the
     * id with another body). `422`: `invalid_source` and `sources_incompatible` (the
     * details name each source or the first differing field), `validation_failed`.
     * `503`: `master_unavailable` (no ffmpeg or ffprobe on the node),
     * `journal_unavailable`. The required `Location` of the `202` names the path
     * getArtifact() reads and is not read.
     *
     * The events are the ordinary `recording.*` with `artifact: master` and
     * `source_generations`: recording.started when the remux begins,
     * recording.completed `master_finished` with `container: mp4`, then
     * recording.registered; the upload is the generation's plan revision 1 and its
     * `transfer.*` events. Nothing auto-releases: release or purge the master, and
     * its sources, when the copies are where they need to be.
     */
    public function createMaster(string $streamId, MasterRequest $master): ArtifactStatus
    {
        $result = $this->transport->post($this->transport->path('/api/v1/streams/%s/masters', $streamId), $master->toArray());

        return ArtifactStatus::fromArray($result->body, created: $result->status === 202);
    }

    /**
     * A page size that is not positive, refused before the request. Above 1000 is
     * the node's to clamp, and it does rather than refusing.
     */
    public static function guardLimit(?int $limit): void
    {
        if ($limit === null || $limit > 0) {
            return;
        }

        throw InvalidConfigurationException::invalid('limit', 'it must be a positive integer');
    }

    /**
     * Follow `next_cursor` until the node says there is no next page (ArtifactRoutes).
     *
     * @template TItem
     *
     * @param  Closure(?string): array{0: list<TItem>, 1: ?string}  $page
     * @return LazyCollection<int, TItem>
     */
    private static function walk(Closure $page): LazyCollection
    {
        return ArtifactRoutes::walk($page);
    }

    private function routes(string $streamId): ArtifactRoutes
    {
        return new ArtifactRoutes($this->transport, $this->transport->path('/api/v1/streams/%s/artifacts', $streamId));
    }
}
