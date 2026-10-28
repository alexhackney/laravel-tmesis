<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Str;

/**
 * An override or a discard of one plan revision: the body of `overrideArtifact`. The
 * openapi component OverrideRequest (tmesis `v0.5.0`, `docs/API.md` 12a.5 and 12a.5a).
 *
 * Three shapes, one constructor each:
 *
 *  - override(): waives EXACTLY every incomplete target of the revision.
 *  - discard(): waives EVERY target of the revision, completed ones included (`[]` for
 *    an empty plan).
 *  - discardAbandoned(): the one decision an ABANDONED `hls_sealed` generation takes
 *    (held, never finalized, its session ended, nothing writing, recovering or
 *    transferring it): `plan_revision` 0, `inventory_sha256` `""` and no waivers.
 *    Release and override answer `409 artifact_not_finalized` for such a generation.
 *
 * Which ids to waive is the node's to check (an unknown, duplicate or omitted one is
 * `422`), and a waiver never turns a failed transfer into a success. `request_id` is
 * minted as a ULID unless given and shares one idempotency family with ReleaseRequest.
 */
final readonly class OverrideRequest
{
    public const ACTION_OVERRIDE = 'override';

    public const ACTION_DISCARD = 'discard';

    /**
     * @param  list<string>  $waivedTargets
     */
    private function __construct(
        private string $requestId,
        private int $planRevision,
        private string $inventorySha256,
        private string $action,
        private string $reason,
        private array $waivedTargets,
    ) {}

    /**
     * @param  array<array-key, string>  $waivedTargets  every incomplete target id of the revision
     * @param  string  $reason  1 to 1024 bytes
     */
    public static function override(int $planRevision, string $inventorySha256, string $reason, array $waivedTargets, ?string $requestId = null): self
    {
        ReleaseRequest::guardDecision($planRevision, $inventorySha256, $reason);

        return new self($requestId ?? (string) Str::ulid(), $planRevision, $inventorySha256, self::ACTION_OVERRIDE, $reason, array_values($waivedTargets));
    }

    /**
     * @param  array<array-key, string>  $waivedTargets  every target id of the
     *                                                   revision; `[]` for an empty plan
     * @param  string  $reason  1 to 1024 bytes
     */
    public static function discard(int $planRevision, string $inventorySha256, string $reason, array $waivedTargets, ?string $requestId = null): self
    {
        ReleaseRequest::guardDecision($planRevision, $inventorySha256, $reason);

        return new self($requestId ?? (string) Str::ulid(), $planRevision, $inventorySha256, self::ACTION_DISCARD, $reason, array_values($waivedTargets));
    }

    /**
     * Discard a generation that can never finalize. The answer's `release_state` is
     * `overridden` and its `retention_expires_at` the session's end plus the node's
     * spool retention; the files go once that passes.
     *
     * @param  string  $reason  1 to 1024 bytes
     */
    public static function discardAbandoned(string $reason, ?string $requestId = null): self
    {
        if ($reason === '' || strlen($reason) > ReleaseRequest::MAX_REASON_BYTES) {
            throw InvalidConfigurationException::invalid('reason', 'it must be 1 to '.ReleaseRequest::MAX_REASON_BYTES.' bytes');
        }

        return new self($requestId ?? (string) Str::ulid(), 0, '', self::ACTION_DISCARD, $reason, []);
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function planRevision(): int
    {
        return $this->planRevision;
    }

    public function inventorySha256(): string
    {
        return $this->inventorySha256;
    }

    /**
     * `override` or `discard`.
     */
    public function action(): string
    {
        return $this->action;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return list<string>
     */
    public function waivedTargets(): array
    {
        return $this->waivedTargets;
    }

    /**
     * Whether this is the abandoned-generation discard shape.
     */
    public function isAbandonedDiscard(): bool
    {
        return $this->action === self::ACTION_DISCARD && $this->inventorySha256 === '' && $this->planRevision === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'plan_revision' => $this->planRevision,
            'inventory_sha256' => $this->inventorySha256,
            'action' => $this->action,
            'reason' => $this->reason,
            'waived_targets' => $this->waivedTargets,
        ];
    }
}
