<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * One authorization refresh for one signed transfer object: the body of
 * `supplyTransferAuthorization`. The openapi component AuthorizationRequest (tmesis
 * `v0.5.0`, `docs/API.md` 12a.4).
 *
 * Every binding field is the object's own, read off `ObjectStatus` (or the
 * `transfer.authorization_required` event): the node answers `409
 * transfer_identity_mismatch` when one is not. `authorization_revision` is the
 * object's plus 1 for a new refresh; the same revision again is a retry (`200` when
 * the bundle is identical, `409 request_conflict` when it differs, and after a
 * restart `200` with `authorization_available` false, which asks for the next one).
 *
 * The bundle carries credentials; see SignedRequest. Nothing here but the requests is
 * secret, so `supplied()` and the binding accessors are safe to log.
 */
final readonly class AuthorizationRequest
{
    private function __construct(
        private int $planRevision,
        private string $inventorySha256,
        private string $targetId,
        private string $objectKey,
        private int $authorizationRevision,
        private ?string $multipartUploadId,
        private AuthorizationBundle $requests,
    ) {}

    /**
     * @param  string|null  $multipartUploadId  the object's upload id; null before a
     *                                          multipart upload has one
     */
    public static function make(
        int $planRevision,
        string $inventorySha256,
        string $targetId,
        string $objectKey,
        int $authorizationRevision,
        ?string $multipartUploadId,
        AuthorizationBundle $requests,
    ): self {
        if ($authorizationRevision < 1) {
            throw InvalidConfigurationException::invalid('authorization_revision', 'revisions start at 1');
        }

        return new self($planRevision, $inventorySha256, $targetId, $objectKey, $authorizationRevision, $multipartUploadId, $requests);
    }

    /**
     * The next refresh for an object as the node last reported it: its bindings, and
     * its authorization revision plus 1.
     */
    public static function nextFor(ObjectStatus $object, string $inventorySha256, AuthorizationBundle $requests): self
    {
        return self::make(
            $object->planRevision(),
            $inventorySha256,
            $object->targetId(),
            $object->objectKey(),
            $object->authorizationRevision() + 1,
            $object->multipartUploadId(),
            $requests,
        );
    }

    public function planRevision(): int
    {
        return $this->planRevision;
    }

    public function inventorySha256(): string
    {
        return $this->inventorySha256;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }

    public function objectKey(): string
    {
        return $this->objectKey;
    }

    public function authorizationRevision(): int
    {
        return $this->authorizationRevision;
    }

    public function multipartUploadId(): ?string
    {
        return $this->multipartUploadId;
    }

    public function requests(): AuthorizationBundle
    {
        return $this->requests;
    }

    /**
     * The request body, credentials included, in the node's pinned field order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_revision' => $this->planRevision,
            'inventory_sha256' => $this->inventorySha256,
            'target_id' => $this->targetId,
            'object_key' => $this->objectKey,
            'authorization_revision' => $this->authorizationRevision,
            'multipart_upload_id' => $this->multipartUploadId,
            'requests' => $this->requests->toArray(),
        ];
    }
}
