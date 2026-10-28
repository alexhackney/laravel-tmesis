<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * How the node reaches its control plane: the outbox depth behind webhook
 * delivery, whether the control plane answered last time, and every fencing
 * lease held. Since tmesis `v0.7.0` also the node's admission and, in fleet mode, the
 * desired-state revision it last applied and when its last heartbeat was answered.
 */
final readonly class IntegrationSnapshot
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * `open`, `draining` or `shutting_down` (tmesis `v0.7.0` D3): the same value as
     * HealthFull::admission(), the HealthFull::ADMISSION_* constants.
     */
    public function admission(): string
    {
        return $this->string('admission');
    }

    /**
     * The desired-state revision this node last applied. Null outside fleet mode, and
     * before the first revision a fleet node has applied.
     */
    public function fleetRevision(): ?int
    {
        $value = $this->raw['fleet_revision'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * RFC3339 UTC, docs/API.md section 4. When the control plane last answered a
     * heartbeat; null outside fleet mode and before the first answer.
     */
    public function lastHeartbeatAtRaw(): ?string
    {
        $value = $this->raw['last_heartbeat_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Parsed from the raw value above.
     */
    public function lastHeartbeatAt(): ?DateTimeImmutable
    {
        $value = $this->lastHeartbeatAtRaw();

        return $value === null ? null : Rfc3339::parse($value);
    }

    /**
     * Null when the node has not tried yet, which is not the same fact as false.
     */
    public function controlPlaneReachable(): ?bool
    {
        return $this->nullableBool('control_plane_reachable');
    }

    /**
     * @return list<LeaseSnapshot>
     */
    public function leases(): array
    {
        $out = [];

        foreach ($this->array('leases') as $item) {
            if (is_array($item)) {
                $out[] = LeaseSnapshot::fromArray($item);
            }
        }

        return $out;
    }

    public function mode(): string
    {
        return $this->string('mode');
    }

    public function webhookOutboxDepth(): int
    {
        return $this->int('webhook_outbox_depth');
    }

    /**
     * The response as it arrived, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableBool(string $key): ?bool
    {
        $value = $this->raw[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
