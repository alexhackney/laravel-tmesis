<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * One transfer object reached its terminal `failed` state. A superseded revision's
 * abort records `failed` but is never announced.
 *
 * `code()` is the contract and `message()` a safe sentence. The schema does not
 * enumerate `code`; the constants below are the values WEBHOOKS.md 8.28 and API.md 12a
 * name, and anything else is a definitive provider error passed through safely.
 */
class TransferFailed extends ArtifactEvent
{
    public const EVENT = 'transfer.failed';

    /**
     * The retry budget is spent, including five unanswered completion probes. After a
     * probe budget the remote copy may exist: `getArtifact` keeps `reconcile_required`
     * true and the release stays held until the plan is replaced or the target waived.
     */
    public const CODE_ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    /**
     * A multipart initiation lost its answer, so whether an upload exists is unknown.
     */
    public const CODE_INITIATION_OUTCOME_UNKNOWN = 'initiation_outcome_unknown';

    /**
     * The destination already holds a different object under the told key.
     */
    public const CODE_DESTINATION_CONFLICT = 'destination_conflict';

    /**
     * The provider no longer knows the multipart upload.
     */
    public const CODE_UPLOAD_LOST = 'upload_lost';

    /**
     * The local file no longer matches its inventory entry.
     */
    public const CODE_LOCAL_INTEGRITY_FAILED = 'local_integrity_failed';

    public function planRevision(): int
    {
        return $this->int('plan_revision');
    }

    /**
     * The target the object was headed to.
     */
    public function targetId(): string
    {
        return $this->string('target_id');
    }

    /**
     * The failed transfer object's id (the object's own resource calls it `id`).
     */
    public function objectId(): string
    {
        return $this->string('object_id');
    }

    /**
     * The safe error code: the thing to branch on.
     */
    public function code(): string
    {
        return $this->string('code');
    }

    /**
     * One human sentence, safe to log. Do not branch on it.
     */
    public function message(): string
    {
        return $this->string('message');
    }
}
