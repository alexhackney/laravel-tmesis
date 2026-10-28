<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Str;

/**
 * The complete told target set for one generation: the body of `replaceTransferPlan`.
 * The openapi component PlanRequest (tmesis `v0.5.0`, `docs/API.md` 12a.3).
 *
 * A plan REPLACES the previous revision, never merges into it; superseded work is
 * cancelled. An empty target set is permitted and never implies a release.
 *
 * **`expected_revision` is required and is never filled in from a GET.** It names the
 * revision this plan replaces, and the node answers `409 transfer_plan_stale` when that
 * is no longer current. Reading the plan and sending its revision back on every PUT
 * would defeat the guard (the node's own CLI was corrected for exactly this,
 * TMESIS-REVIEW-5): send the revision the caller's own decision was made against.
 *
 * **`request_id` is the idempotency key.** It is minted here as a ULID unless given;
 * keep it (`requestId()`) and send the same request with it on every retry: an exact
 * retry answers the original revision, the same id with another body is `409
 * request_conflict`.
 *
 * **Target order is significant and never changed here.** For a recording awaiting
 * remote registration, the FIRST target of the first accepted non-empty plan is the
 * registration target (`recording.registered` fires as it completes, naming its
 * location).
 *
 * **`reason` is optional and OMITTED when not set.** An omitted reason and `""` are
 * different requests to the node's request hash, so the builder never renders one it
 * was not given.
 */
final readonly class PlanRequest
{
    public const MAX_REASON_BYTES = 1024;

    /**
     * @param  list<PlanTarget>  $targets
     */
    private function __construct(
        private string $requestId,
        private int $expectedRevision,
        private string $inventorySha256,
        private array $targets,
        private ?string $reason,
    ) {}

    /**
     * @param  string  $inventorySha256  the generation's `inventory_sha256`
     * @param  int  $expectedRevision  the revision this plan replaces; 0 replaces the
     *                                 baseline
     * @param  string|null  $requestId  a caller-minted ULID, minted here when null
     */
    public static function make(string $inventorySha256, int $expectedRevision, ?string $requestId = null): self
    {
        if ($expectedRevision < 0) {
            throw InvalidConfigurationException::invalid('expected_revision', 'it must be 0 or greater; 0 replaces the baseline');
        }

        return new self($requestId ?? (string) Str::ulid(), $expectedRevision, $inventorySha256, [], null);
    }

    /**
     * Append a target. Targets go out in the order they were added.
     */
    public function withTarget(PlanTarget $target): self
    {
        foreach ($this->targets as $existing) {
            if ($existing->id() === $target->id()) {
                throw InvalidConfigurationException::invalid('targets.id', "the target id {$target->id()} is already in this plan");
            }
        }

        return new self($this->requestId, $this->expectedRevision, $this->inventorySha256, [...$this->targets, $target], $this->reason);
    }

    /**
     * Append several targets, in the order given.
     */
    public function withTargets(PlanTarget ...$targets): self
    {
        $plan = $this;

        foreach ($targets as $target) {
            $plan = $plan->withTarget($target);
        }

        return $plan;
    }

    /**
     * Why this revision, at most 1024 bytes. `""` is a reason, and a different
     * request from one with none.
     */
    public function withReason(string $reason): self
    {
        if (strlen($reason) > self::MAX_REASON_BYTES) {
            throw InvalidConfigurationException::invalid('reason', 'at most '.self::MAX_REASON_BYTES.' bytes');
        }

        return new self($this->requestId, $this->expectedRevision, $this->inventorySha256, $this->targets, $reason);
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function expectedRevision(): int
    {
        return $this->expectedRevision;
    }

    public function inventorySha256(): string
    {
        return $this->inventorySha256;
    }

    /**
     * @return list<PlanTarget>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * The request body. A signed target's initial authorizations carry credentials;
     * see SignedRequest. Their `plan_revision` (the revision this plan creates),
     * `inventory_sha256` and `target_id` are filled in here from the plan and the
     * enclosing target, the three bindings the node refuses with `422` otherwise.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'request_id' => $this->requestId,
            'expected_revision' => $this->expectedRevision,
            'inventory_sha256' => $this->inventorySha256,
        ];

        if ($this->reason !== null) {
            $body['reason'] = $this->reason;
        }

        $body['targets'] = array_map(
            fn (PlanTarget $target): array => $target->toArray($this->expectedRevision + 1, $this->inventorySha256),
            $this->targets,
        );

        return $body;
    }
}
