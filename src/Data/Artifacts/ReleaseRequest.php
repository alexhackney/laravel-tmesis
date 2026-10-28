<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Str;

/**
 * A normal release of one plan revision: the body of `releaseArtifact`, and since
 * tmesis `v0.8.0` of `purgeArtifact` (`docs/API.md` 12a.5b), which takes the same
 * body. The openapi component ReleaseRequest (tmesis `v0.5.0`, `docs/API.md` 12a.5).
 *
 * **Nothing leaves a box without a release.** From tmesis `v0.5.0` the node deletes a
 * generation's local files only after an explicit release, override or discard on its
 * CURRENT plan revision and once its retention has passed; a registration or a
 * completed transfer authorises nothing. A normal release needs every requested copy
 * complete (`409 transfer_incomplete` otherwise); an explicit release of an empty plan
 * is allowed.
 *
 * **The revision is the one the caller's own evidence covers**, never one read just
 * before the release; see forPlan().
 *
 * `request_id` is minted as a ULID unless given and shares one idempotency family with
 * OverrideRequest: keep it for the retry.
 */
final readonly class ReleaseRequest
{
    public const MAX_REASON_BYTES = 1024;

    private function __construct(
        private string $requestId,
        private int $planRevision,
        private string $inventorySha256,
        private string $reason,
    ) {}

    /**
     * @param  string  $reason  1 to 1024 bytes
     */
    public static function make(int $planRevision, string $inventorySha256, string $reason, ?string $requestId = null): self
    {
        self::guardDecision($planRevision, $inventorySha256, $reason);

        return new self($requestId ?? (string) Str::ulid(), $planRevision, $inventorySha256, $reason);
    }

    /**
     * Release the revision the caller's OWN plan write created: the PlanStatus
     * `replaceTransferPlan()` answered (a duplicate's answer is the same revision).
     * The caller is asserting the copies it needs are complete; the node refuses the
     * release if they are not.
     *
     * There is deliberately no "release whatever is current". A revision read just
     * before the release is not evidence of anything: if another writer replaced the
     * plan in between (with an empty set, say), releasing the revision the read
     * returned would let the node delete a generation this caller never saw copied,
     * and the stale guard, which exists to catch exactly that, would pass. Release the
     * revision your evidence covers (this PlanStatus, or the `plan_revision` of the
     * `transfer.completed` you acted on, through make()), so a replaced plan answers
     * `409 transfer_plan_stale` instead.
     */
    public static function forPlan(PlanStatus $plan, string $reason, ?string $requestId = null): self
    {
        return self::make($plan->revision(), $plan->inventorySha256(), $reason, $requestId);
    }

    /**
     * The purge of an ABANDONED or FAILED held generation (tmesis `v0.8.0`,
     * `purgeArtifact()` only): `plan_revision` 0 and an empty `inventory_sha256`, the
     * names the node gives a generation that never finalized. The node discards it and
     * records the purge in one transaction; its next retirement pass removes the local
     * files (in seconds, unless a hold stands). Anywhere but the purge route
     * that empty sha is a `422`; for an ordinary finalized generation, purge with
     * make() or forPlan() as a release.
     *
     * @param  string  $reason  1 to 1024 bytes
     */
    public static function purgeAbandoned(string $reason, ?string $requestId = null): self
    {
        if ($reason === '' || strlen($reason) > self::MAX_REASON_BYTES) {
            throw InvalidConfigurationException::invalid('reason', 'it must be 1 to '.self::MAX_REASON_BYTES.' bytes');
        }

        return new self($requestId ?? (string) Str::ulid(), 0, '', $reason);
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

    public function reason(): string
    {
        return $this->reason;
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
            'reason' => $this->reason,
        ];
    }

    /**
     * The request-alone rules a release or a (non-abandoned) override shares: a
     * reason of 1 to 1024 bytes, a non-negative revision, and a named inventory. An
     * empty inventory is the abandoned discard's alone
     * (`OverrideRequest::discardAbandoned()`), and a `422` anywhere else.
     */
    public static function guardDecision(int $planRevision, string $inventorySha256, string $reason): void
    {
        if ($reason === '' || strlen($reason) > self::MAX_REASON_BYTES) {
            throw InvalidConfigurationException::invalid('reason', 'it must be 1 to '.self::MAX_REASON_BYTES.' bytes');
        }

        if ($planRevision < 0) {
            throw InvalidConfigurationException::invalid('plan_revision', 'it must be 0 or greater');
        }

        if ($inventorySha256 === '') {
            throw InvalidConfigurationException::invalid(
                'inventory_sha256',
                'it names the generation\'s inventory; an empty one is only for OverrideRequest::discardAbandoned()'
            );
        }
    }
}
