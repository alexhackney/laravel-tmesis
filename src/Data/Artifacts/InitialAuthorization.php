<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * Signed requests for one inventory path, supplied WITH a plan rather than by a later
 * refresh. The openapi component InitialAuthorization (tmesis `v0.5.0`, `docs/API.md`
 * 12a.3), an entry of `SignedDestination::authorizations`.
 *
 * The caller names only what it knows: the inventory path, the object key it signed
 * for, and the bundle. The three binding fields the node checks against the plan
 * itself (`internal/api/artifacts.go`, `422` on each) are filled in when the plan is
 * rendered, never taken from the caller: `plan_revision` is the plan's
 * `expected_revision` plus 1, `inventory_sha256` the plan's, and `target_id` the id of
 * the target this rides on. `authorization_revision` is always 1 and
 * `multipart_upload_id` always null.
 *
 * They are validated against the prospective plan before the commit (a bad one
 * refuses the whole plan: `422`, or `409 transfer_identity_mismatch` for a key that is
 * not the object's) and installed after it. They are never stored and never part of
 * the request hash, so an exact retry of an accepted plan installs nothing: answer a
 * lost install with a refresh.
 *
 * The bundle carries credentials; see SignedRequest.
 */
final readonly class InitialAuthorization
{
    private function __construct(
        private string $artifactPath,
        private string $objectKey,
        private AuthorizationBundle $requests,
    ) {}

    /**
     * @param  string  $artifactPath  the inventory path these requests are for
     * @param  string  $objectKey  the complete object key the requests were signed for
     */
    public static function make(string $artifactPath, string $objectKey, AuthorizationBundle $requests): self
    {
        return new self($artifactPath, $objectKey, $requests);
    }

    public function artifactPath(): string
    {
        return $this->artifactPath;
    }

    public function objectKey(): string
    {
        return $this->objectKey;
    }

    public function requests(): AuthorizationBundle
    {
        return $this->requests;
    }

    /**
     * The request body, credentials included, bound to the plan and target it rides
     * in. PlanRequest::toArray() supplies the three bindings; nothing else should.
     *
     * @param  int  $planRevision  the revision the plan creates: its expected_revision plus 1
     * @param  string  $inventorySha256  the plan's inventory_sha256
     * @param  string  $targetId  the enclosing target's id
     * @return array<string, mixed>
     */
    public function toArray(int $planRevision, string $inventorySha256, string $targetId): array
    {
        return [
            'artifact_path' => $this->artifactPath,
            'plan_revision' => $planRevision,
            'inventory_sha256' => $inventorySha256,
            'target_id' => $targetId,
            'object_key' => $this->objectKey,
            'authorization_revision' => 1,
            'multipart_upload_id' => null,
            'requests' => $this->requests->toArray(),
        ];
    }
}
