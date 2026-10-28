<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * The last required object of ONE plan target on the current revision was delivered.
 * A target with five objects fires this once, not five times.
 *
 * A completed transfer does not authorise deletion: the generation stays on the node
 * until a release, override or discard (`artifact.released`) and its retention.
 */
class TransferCompleted extends ArtifactEvent
{
    public const EVENT = 'transfer.completed';

    public function planRevision(): int
    {
        return $this->int('plan_revision');
    }

    /**
     * The target that finished.
     */
    public function targetId(): string
    {
        return $this->string('target_id');
    }

    /**
     * Objects delivered to this target at this revision.
     */
    public function objectCount(): int
    {
        return $this->int('object_count');
    }

    /**
     * Bytes delivered to this target at this revision.
     */
    public function sizeBytes(): int
    {
        return $this->int('size_bytes');
    }

    public function completedAtRaw(): string
    {
        return $this->required('completed_at');
    }

    public function completedAt(): DateTimeImmutable
    {
        return $this->timestamp('completed_at');
    }
}
