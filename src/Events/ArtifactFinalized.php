<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * A generation's local seal evidence, inventory and empty revision-zero plan committed
 * together. Fires on the first finalisation only: a retry with the same inventory
 * enqueues nothing.
 *
 * This is the point a control plane can tell a transfer plan against it; the inventory
 * hash is `identity()->inventorySha256()`.
 */
class ArtifactFinalized extends ArtifactEvent
{
    public const EVENT = 'artifact.finalized';

    /**
     * The recording the generation belongs to, or null for `hls_sealed`.
     */
    public function recordingId(): ?string
    {
        return $this->nullableString('recording_id');
    }

    /**
     * Entries in the finalized inventory.
     */
    public function objectCount(): int
    {
        return $this->int('object_count');
    }

    /**
     * The sum of the inventory's sizes.
     */
    public function sizeBytes(): int
    {
        return $this->int('size_bytes');
    }

    public function finalizedAtRaw(): string
    {
        return $this->required('finalized_at');
    }

    public function finalizedAt(): DateTimeImmutable
    {
        return $this->timestamp('finalized_at');
    }

    /**
     * Set only when finalisation and registration were the same instant (a filesystem
     * or no-destination recording); null otherwise.
     */
    public function registeredAtRaw(): ?string
    {
        return $this->nullableString('registered_at');
    }

    public function registeredAt(): ?DateTimeImmutable
    {
        return $this->nullableTimestamp('registered_at');
    }
}
