<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Preview\PreviewGrant;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantInput;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantListResponse;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch;
use AlexHackney\Tmesis\Resources\PreviewGrants;

/**
 * Preview grants, answered from FakeTmesis's grant ledger: every create is a new,
 * independent grant, an update moves `expires_at` to now plus the ttl, a revoke
 * takes the grant out of every read, and an unknown, expired or revoked id is the
 * node's `404`.
 */
class FakePreviewGrantsResource extends PreviewGrants
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function createPreviewGrant(PreviewGrantInput $input): PreviewGrant
    {
        $this->fake->record('createPreviewGrant', $this->node, [
            'ips' => $input->ips(),
            'ttlSeconds' => $input->ttlSeconds(),
            'streams' => $input->streams(),
            'labels' => $input->labels(),
        ]);

        return $this->fake->createdPreviewGrant($input);
    }

    public function listPreviewGrants(): PreviewGrantListResponse
    {
        $this->fake->record('listPreviewGrants', $this->node);

        return $this->fake->previewGrantList();
    }

    public function updatePreviewGrant(string $grantId, PreviewGrantPatch $patch): PreviewGrant
    {
        $this->fake->record('updatePreviewGrant', $this->node, ['grantId' => $grantId, 'ttlSeconds' => $patch->ttlSeconds()]);

        return $this->fake->updatedPreviewGrant($grantId, $patch);
    }

    public function revokePreviewGrant(string $grantId): PreviewGrant
    {
        $this->fake->record('revokePreviewGrant', $this->node, ['grantId' => $grantId]);

        return $this->fake->revokedPreviewGrant($grantId);
    }
}
