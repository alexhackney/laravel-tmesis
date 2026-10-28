<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

use AlexHackney\Tmesis\Data\Node\Stats\CapacitySnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\DiskSnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\IdentitySnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\IntegrationSnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\LoadSnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\VideoEngineSnapshot;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * GET /api/v1/stats: the node snapshot.
 *
 * @see NodeSnapshot in docs/contract/openapi.json at v0.1.0-rc2. The class keeps
 * the name a Laravel codebase reads better; the component is the pin.
 */
final readonly class NodeStats
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

    public function identity(): IdentitySnapshot
    {
        return IdentitySnapshot::fromArray($this->array('identity'));
    }

    public function load(): LoadSnapshot
    {
        return LoadSnapshot::fromArray($this->array('load'));
    }

    public function disk(): DiskSnapshot
    {
        return DiskSnapshot::fromArray($this->array('disk'));
    }

    public function capacity(): CapacitySnapshot
    {
        return CapacitySnapshot::fromArray($this->array('capacity'));
    }

    public function integration(): IntegrationSnapshot
    {
        return IntegrationSnapshot::fromArray($this->array('integration'));
    }

    /**
     * What this node encodes the live ladder with, or null when it does not encode
     * live at all (no ladder configured, or the transcoder role, which encodes
     * jobs only; `v0.9.0`).
     *
     * Null rather than a zeroed block on purpose upstream: "this box runs libx264"
     * and "this box encodes nothing" are different facts.
     */
    public function videoEngine(): ?VideoEngineSnapshot
    {
        $value = $this->raw['video_engine'] ?? null;

        return is_array($value) ? VideoEngineSnapshot::fromArray($value) : null;
    }

    /**
     * What this node encodes VOD jobs with (`v0.9.0`, handover 3.4), pinned
     * independently of the live encoder by `jobs.video_encoder`. Null when jobs are
     * off. REQUIRED and nullable on NodeSnapshot.
     */
    public function jobsVideoEngine(): ?VideoEngineSnapshot
    {
        $value = $this->raw['jobs_video_engine'] ?? null;

        return is_array($value) ? VideoEngineSnapshot::fromArray($value) : null;
    }

    /**
     * When the snapshot was built. Every freshness value in it was computed against
     * this instant.
     */
    public function observedAtRaw(): string
    {
        $value = $this->raw['observed_at'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function observedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->observedAtRaw());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }
}
