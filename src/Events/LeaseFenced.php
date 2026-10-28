<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Keys\FencingEpoch;
use DateTimeImmutable;

/**
 * This node stopped writing an output prefix because the single-writer lease on it
 * moved (tmesis `v0.4.0`, 4.3 / EDGE-6; WEBHOOKS.md 8.26, final plan principle 8).
 *
 * A fence is a fact about the BUCKET OUTPUT of one publish session, never about the
 * show: the session keeps running, the packager keeps its manifests, the publisher is
 * not touched. What stopped is this node's uploads under `prefix()`, and
 * `abandonedUploads()` is what it still owed when it stopped. The next holder, or the
 * next start, reconciles those: segments are uploaded, playlists and init segments are
 * retired, never uploaded over the current holder's.
 *
 * `cause()` is a CLOSED set and is the thing to branch on. `superseded` is the normal
 * takeover: a higher fencing epoch appeared, and `observedEpoch()` is above
 * `heldEpoch()`. The other three (`released`, `holder_changed`, `missing`) mean the
 * grant disappeared with no higher epoch and are always an incident; the two epochs
 * are equal then.
 *
 * At launch the lease is node-local: the node grants, renews and fences itself, and
 * on one box `newHolder()` is usually this node's own name (a takeover fences its own
 * predecessor). The package implements no grant, renew or handoff; the control-plane
 * side rides with FLEET. The fencing epoch is the THIRD thing called "epoch" here
 * (the package's contract notes): not the session epoch and not the key epoch, so the typed accessors
 * hand back `FencingEpoch` and nothing else.
 */
class LeaseFenced extends TmesisWebhookReceived
{
    public const EVENT = 'lease.fenced';

    /**
     * A higher fencing epoch took the prefix: the normal takeover.
     */
    public const CAUSE_SUPERSEDED = 'superseded';

    /**
     * The grant was released with no higher epoch. An incident.
     */
    public const CAUSE_RELEASED = 'released';

    /**
     * The grant of record names another holder at the same epoch. An incident.
     */
    public const CAUSE_HOLDER_CHANGED = 'holder_changed';

    /**
     * The grant disappeared. An incident.
     */
    public const CAUSE_MISSING = 'missing';

    /**
     * The publish session whose bucket output was fenced. EMPTY for a crash-recovery
     * seal, which belongs to no running session, so never `required()`.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    /**
     * The stream's object key root under the live destination
     * (`live/show-2041-main`): the unit a single writer owns.
     */
    public function prefix(): string
    {
        return $this->string('prefix');
    }

    /**
     * CLOSED set: `superseded`, `released`, `holder_changed`, `missing`. Only the
     * first is routine.
     */
    public function cause(): string
    {
        return $this->string('cause');
    }

    /**
     * Whether the fence was the normal takeover rather than an incident.
     */
    public function isSuperseded(): bool
    {
        return $this->cause() === self::CAUSE_SUPERSEDED;
    }

    /**
     * The FENCING epoch this node wrote under. Not the session epoch and not the key
     * epoch.
     */
    public function heldEpoch(): int
    {
        return $this->int('held_epoch');
    }

    public function heldEpochValue(): FencingEpoch
    {
        return FencingEpoch::fromInt($this->heldEpoch());
    }

    /**
     * The highest fencing epoch on record for the prefix when the fence was detected.
     * Above `heldEpoch()` when the cause is `superseded`, equal to it otherwise.
     */
    public function observedEpoch(): int
    {
        return $this->int('observed_epoch');
    }

    public function observedEpochValue(): FencingEpoch
    {
        return FencingEpoch::fromInt($this->observedEpoch());
    }

    /**
     * The holder of the grant of record, or null when the prefix is released or
     * unheld.
     */
    public function newHolder(): ?string
    {
        return $this->nullableString('new_holder');
    }

    /**
     * RFC3339 UTC, when this node stopped writing the prefix. BEFORE `occurredAt()`:
     * the node stops first and tells afterwards. Up to the live destination's
     * `upload_concurrency` objects already in flight may still land after it.
     */
    public function writesStoppedAtRaw(): string
    {
        return $this->string('writes_stopped_at');
    }

    public function writesStoppedAt(): DateTimeImmutable
    {
        return $this->timestamp('writes_stopped_at');
    }

    /**
     * Objects this grant still owed when it stopped. They stay in the node's ledger
     * at `heldEpoch()` for the next holder or the next start to reconcile.
     */
    public function abandonedUploads(): int
    {
        return $this->int('abandoned_uploads');
    }
}
