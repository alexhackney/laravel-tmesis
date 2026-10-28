<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A release, override or discard decision committed for a generation's plan revision.
 *
 * It authorises eventual LOCAL cleanup once retention has passed. It is not deletion
 * (that is `artifact.deleted`) and not registration. A later plan revision makes the
 * generation unreleased again.
 *
 * The discard of an ABANDONED generation (one that never finalized, API.md 12a.5a)
 * carries `inventory_sha256` "" and `plan_revision` 0, because it has neither;
 * isAbandonedDiscard() names that shape.
 */
class ArtifactReleased extends ArtifactEvent
{
    public const EVENT = 'artifact.released';

    public const ACTION_RELEASE = 'release';

    public const ACTION_OVERRIDE = 'override';

    public const ACTION_DISCARD = 'discard';

    /**
     * The decision was accepted under the admin token.
     */
    public const ACTOR_ADMIN_TOKEN = 'admin_token';

    /**
     * The decision was accepted on a node with no admin token configured.
     */
    public const ACTOR_OPEN = 'open';

    /**
     * The plan revision the decision is bound to. 0 for an abandoned generation's
     * discard.
     */
    public function planRevision(): int
    {
        return $this->int('plan_revision');
    }

    /**
     * The decision's identity: the caller-minted request id. There is no separate
     * decision id.
     */
    public function requestId(): string
    {
        return $this->string('request_id');
    }

    /**
     * `release`, `override` or `discard`.
     */
    public function action(): string
    {
        return $this->string('action');
    }

    /**
     * The credential class the decision was accepted under: `admin_token` or `open`.
     */
    public function actor(): string
    {
        return $this->string('actor');
    }

    /**
     * The reason the caller supplied. Prose; do not branch on it.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * Target ids the decision waived: empty for a normal release, every target for a
     * discard. A waiver never turns a failed transfer into a success.
     *
     * @return list<string>
     */
    public function waivedTargets(): array
    {
        return $this->stringList('waived_targets');
    }

    /**
     * Whether this is the discard of a generation that never finalized: `discard` on
     * plan revision 0 with an empty inventory hash.
     */
    public function isAbandonedDiscard(): bool
    {
        return $this->action() === self::ACTION_DISCARD
            && $this->planRevision() === 0
            && $this->identity()->inventorySha256() === '';
    }
}
