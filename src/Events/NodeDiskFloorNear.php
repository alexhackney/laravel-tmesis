<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * Free space on the node's data volume came within a margin of the disk floor
 * (tmesis `v0.9.0`, handover 3.9; WEBHOOKS.md 8.32). Node-scoped: no stream, no
 * labels.
 *
 * Once per ENTRY, from the node's own 15 s sampler, and once at boot when the node
 * starts inside the margin; it re-arms only after free space recovers above 1.25
 * times the margin. There is NO exit event: recovery is read on health
 * (`disk_floor_near` leaves `degraded_reasons`). It evicts nothing; recording still
 * sheds at the floor itself (`recording.degraded` reason `disk_floor`).
 */
class NodeDiskFloorNear extends TmesisWebhookReceived
{
    public const EVENT = 'node.disk_floor_near';

    /**
     * The data dir whose volume was sampled.
     */
    public function dataDir(): string
    {
        return $this->string('data_dir');
    }

    public function freeBytes(): int
    {
        return $this->int('free_bytes');
    }

    /**
     * The volume's whole size; `0` means unknown (the volume probe failed), never an
     * empty disk.
     */
    public function totalBytes(): int
    {
        return $this->int('total_bytes');
    }

    /**
     * `limits.disk_floor_gb` in bytes (the default is 5 since `v0.9.0`, 10 before).
     */
    public function floorBytes(): int
    {
        return $this->int('floor_bytes');
    }

    /**
     * Free minus floor, SIGNED: negative once the floor is breached.
     */
    public function headroomBytes(): int
    {
        return $this->int('headroom_bytes');
    }

    /**
     * The margin above the floor: 2 percent of the volume, clamped 512 MiB to 20 GiB.
     * `0` means none (the volume size is unknown), so it fired at the floor itself.
     */
    public function marginBytes(): int
    {
        return $this->int('margin_bytes');
    }

    /**
     * Whether free space was already below the floor when this was sampled.
     */
    public function isBreached(): bool
    {
        return $this->headroomBytes() < 0;
    }
}
