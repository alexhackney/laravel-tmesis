<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Preview\PreviewGrant;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantInput;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantListResponse;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch;

/**
 * WHEP preview grants: which producer addresses may open a monitor on this node, for
 * how long (tmesis v0.6.0, `docs/API.md` 9.20a, final plan 11.54). Method names are
 * the openapi operationIds.
 *
 * NODE-scoped: `Tmesis::node($name)->previewGrants()`. The panel flow is grant on
 * open (every address family the panel was seen on, in ONE grant), extend on reload
 * (update the grant's id), revoke on close. Every create is an INDEPENDENT grant:
 * grants never replace each other, so a panel that creates on every reload piles
 * them up instead of extending one.
 *
 * Under `preview.access: granted` the WHEP offer from an address no live grant
 * covers answers `403 not_granted` on the preview listener, to the PLAYER. The admin
 * client never sees that code, so nothing here maps it.
 */
class PreviewGrants
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * POST /api/v1/preview/grants: a new grant, `201`. Independent of every other.
     */
    public function createPreviewGrant(PreviewGrantInput $input): PreviewGrant
    {
        return PreviewGrant::fromArray(
            $this->transport->post('/api/v1/preview/grants', $input->toArray())->body
        );
    }

    /**
     * GET /api/v1/preview/grants: every LIVE grant, oldest first. Expired and revoked
     * grants are not listed.
     */
    public function listPreviewGrants(): PreviewGrantListResponse
    {
        return PreviewGrantListResponse::fromArray($this->transport->get('/api/v1/preview/grants'));
    }

    /**
     * PATCH /api/v1/preview/grants/:id: the grant's new life from now (the panel's
     * reload). Only this grant changes. An expired or revoked grant is `404` and is
     * never revived: create a new one.
     */
    public function updatePreviewGrant(string $grantId, PreviewGrantPatch $patch): PreviewGrant
    {
        return PreviewGrant::fromArray($this->transport->patch(
            $this->transport->path('/api/v1/preview/grants/%s', $grantId),
            $patch->toArray(),
        )->body);
    }

    /**
     * DELETE /api/v1/preview/grants/:id: revoke. The answer is the grant as it was. A
     * connected monitor it covered that no other live grant covers is closed now
     * (`grant_revoked`). An unknown, expired or already revoked grant is `404`.
     */
    public function revokePreviewGrant(string $grantId): PreviewGrant
    {
        return PreviewGrant::fromArray(
            $this->transport->delete($this->transport->path('/api/v1/preview/grants/%s', $grantId))->body
        );
    }
}
