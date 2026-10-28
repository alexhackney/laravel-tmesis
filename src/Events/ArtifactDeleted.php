<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * The last LOCAL entry of a retiring generation was removed from the node.
 *
 * Local removal only: the node never deletes a remote copy, so a copy a transfer
 * delivered is exactly where it was. A retry after the deletion enqueues nothing.
 */
class ArtifactDeleted extends ArtifactEvent
{
    public const EVENT = 'artifact.deleted';

    /**
     * The revision whose decision authorised the retirement.
     */
    public function planRevision(): int
    {
        return $this->int('plan_revision');
    }

    /**
     * That decision's request id, the one `artifact.released` carried.
     */
    public function requestId(): string
    {
        return $this->string('request_id');
    }

    /**
     * When the last local entry was removed: the retirement's completion commit.
     */
    public function deletedAtRaw(): string
    {
        return $this->required('deleted_at');
    }

    public function deletedAt(): DateTimeImmutable
    {
        return $this->timestamp('deleted_at');
    }

    /**
     * True when an operator purge (`Artifacts::purgeArtifact()`) retired the
     * generation, before its retention deadline when that had not passed; false for
     * a retirement at the deadline. REQUIRED since tmesis `v0.8.0`.
     */
    public function purge(): bool
    {
        return $this->bool('purge');
    }

    /**
     * The purge's reason, 1 to 1024 bytes; null when purge() is false.
     */
    public function purgeReason(): ?string
    {
        return $this->nullableString('purge_reason');
    }
}
