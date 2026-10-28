<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * The transcode queue on GET /api/v1/health (tmesis `v0.6.0`, `docs/API.md` 9.1).
 *
 * A control plane choosing a box before the fleet door exists posts to one with
 * `running() < slots()`.
 */
final readonly class HealthJobs
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

    public function queued(): int
    {
        return $this->int('queued');
    }

    /**
     * Claimed and running jobs TOGETHER: a claimed job already holds a slot.
     */
    public function running(): int
    {
        return $this->int('running');
    }

    /**
     * How many jobs may be claimed or running at once: `jobs.max_concurrent`, else the
     * stored `tmesis bench --profile job` measurement, else 1.
     */
    public function slots(): int
    {
        return $this->int('slots');
    }

    /**
     * Whether a job submitted now could be claimed without waiting for a slot. A
     * paused queue claims nothing whatever this says; read `isPaused()` too.
     */
    public function hasFreeSlot(): bool
    {
        return $this->running() < $this->slots();
    }

    /**
     * The jobs pause in force (tmesis `v0.9.0`, handover 3.5): REQUIRED, null when
     * no pause is active.
     */
    public function paused(): ?HealthJobsPause
    {
        $value = $this->raw['paused'] ?? null;

        return is_array($value) ? HealthJobsPause::fromArray($value) : null;
    }

    /**
     * Whether a pause is active, so no queued job is claimed. A control plane stops
     * offering jobs to this box while it is true.
     */
    public function isPaused(): bool
    {
        return $this->paused() !== null;
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
