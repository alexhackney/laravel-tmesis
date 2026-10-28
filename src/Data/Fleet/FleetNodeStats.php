<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use stdClass;

/**
 * The box-level state one heartbeat reports (`fleet.heartbeat.request` `$defs.NodeStats`).
 *
 * Not called `NodeStats`: that name is the admin stats DTO (`NodeSnapshot`, decision
 * D5), and this is a different six-field shape the fleet defines on purpose (tmesis
 * wire.go keeps the two free to diverge).
 */
final readonly class FleetNodeStats
{
    public function __construct(
        public int $uptimeSeconds,
        public int $sessionsActive,
        public int $diskFreeBytes,
        public bool $diskFloorBreached,
        public int $outboxDepth,
        public int $recordingsUnregistered,
    ) {}

    public static function fromWire(stdClass $o, string $path = '$.stats'): self
    {
        return new self(
            FleetWire::integer($o, 'uptime_seconds', $path),
            FleetWire::integer($o, 'sessions_active', $path),
            FleetWire::integer($o, 'disk_free_bytes', $path),
            FleetWire::boolean($o, 'disk_floor_breached', $path),
            FleetWire::integer($o, 'outbox_depth', $path),
            FleetWire::integer($o, 'recordings_unregistered', $path),
        );
    }

    /**
     * @param  array<string, mixed>  $a  a stored copy
     */
    public static function fromStored(array $a): self
    {
        return new self(
            (int) ($a['uptime_seconds'] ?? 0),
            (int) ($a['sessions_active'] ?? 0),
            (int) ($a['disk_free_bytes'] ?? 0),
            (bool) ($a['disk_floor_breached'] ?? false),
            (int) ($a['outbox_depth'] ?? 0),
            (int) ($a['recordings_unregistered'] ?? 0),
        );
    }

    /**
     * @return array{uptime_seconds: int, sessions_active: int, disk_free_bytes: int, disk_floor_breached: bool, outbox_depth: int, recordings_unregistered: int}
     */
    public function toArray(): array
    {
        return [
            'uptime_seconds' => $this->uptimeSeconds,
            'sessions_active' => $this->sessionsActive,
            'disk_free_bytes' => $this->diskFreeBytes,
            'disk_floor_breached' => $this->diskFloorBreached,
            'outbox_depth' => $this->outboxDepth,
            'recordings_unregistered' => $this->recordingsUnregistered,
        ];
    }
}
