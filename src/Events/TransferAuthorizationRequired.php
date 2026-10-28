<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A signed-target transfer object needs fresh signed requests before the node can
 * continue it. Fires only for a NEW need, never on every poll of one already reported;
 * a superseded revision never emits it, and a signed plan's initial waiting state emits
 * nothing (the plan status shows it).
 *
 * The signer answers through the `supplyTransferAuthorization` route with one signed
 * request per operation in requiredOperations(). Supplied requests live in the node's memory
 * only, so after a restart it asks again with this event.
 *
 * `completion_probe_required` asks for a HEAD (`required` is `["head"]`): a write's
 * outcome is ambiguous and the node sends nothing for the object until a HEAD settles
 * it. A signer that can only mint writes cannot answer it.
 *
 * The event carries no URL and no signature, only the key the object is bound to.
 */
class TransferAuthorizationRequired extends ArtifactEvent
{
    public const EVENT = 'transfer.authorization_required';

    public const REASON_AUTHORIZATION_MISSING = 'authorization_missing';

    public const REASON_AUTHORIZATION_EXPIRED = 'authorization_expired';

    public const REASON_AUTHORIZATION_REJECTED = 'authorization_rejected';

    public const REASON_CREDENTIALS_MISSING = 'credentials_missing';

    /**
     * A write's outcome is unknown and no signed HEAD is held to reconcile it.
     */
    public const REASON_COMPLETION_PROBE_REQUIRED = 'completion_probe_required';

    public const REASON_ABORT_AUTHORIZATION_REQUIRED = 'abort_authorization_required';

    /**
     * CLOSED set, in schema order.
     */
    public const REASONS = [
        self::REASON_AUTHORIZATION_MISSING,
        self::REASON_AUTHORIZATION_EXPIRED,
        self::REASON_AUTHORIZATION_REJECTED,
        self::REASON_CREDENTIALS_MISSING,
        self::REASON_COMPLETION_PROBE_REQUIRED,
        self::REASON_ABORT_AUTHORIZATION_REQUIRED,
    ];

    public const OPERATION_PUT = 'put';

    public const OPERATION_INITIATE = 'initiate';

    public const OPERATION_PARTS = 'parts';

    public const OPERATION_COMPLETE = 'complete';

    public const OPERATION_ABORT = 'abort';

    public const OPERATION_HEAD = 'head';

    /**
     * The operations requiredOperations() can name. The schema types them as strings; this set is
     * WEBHOOKS.md 8.28's and `ObjectStatus.authorization_required`'s.
     */
    public const OPERATIONS = [
        self::OPERATION_PUT,
        self::OPERATION_INITIATE,
        self::OPERATION_PARTS,
        self::OPERATION_COMPLETE,
        self::OPERATION_ABORT,
        self::OPERATION_HEAD,
    ];

    public function planRevision(): int
    {
        return $this->int('plan_revision');
    }

    /**
     * The signed target waiting on authorization.
     */
    public function targetId(): string
    {
        return $this->string('target_id');
    }

    /**
     * The waiting transfer object.
     */
    public function objectId(): string
    {
        return $this->string('object_id');
    }

    /**
     * The told remote key the object is bound to; the signed requests must name it.
     */
    public function objectKey(): string
    {
        return $this->string('object_key');
    }

    /**
     * The provider's multipart upload id, once initiation has produced one.
     */
    public function multipartUploadId(): ?string
    {
        return $this->nullableString('multipart_upload_id');
    }

    /**
     * Which operations need a fresh signed request: the payload's `required` (the name
     * required() is the base class's required-key reader).
     *
     * @return list<string>
     */
    public function requiredOperations(): array
    {
        return $this->stringList('required');
    }

    public function requires(string $operation): bool
    {
        return in_array($operation, $this->requiredOperations(), true);
    }

    /**
     * Whether the signer must mint a HEAD, which is what a completion probe asks for.
     */
    public function requiresHead(): bool
    {
        return $this->requires(self::OPERATION_HEAD);
    }

    /**
     * One of REASONS.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    public function isCompletionProbe(): bool
    {
        return $this->reason() === self::REASON_COMPLETION_PROBE_REQUIRED;
    }
}
