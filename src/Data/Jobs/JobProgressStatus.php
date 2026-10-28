<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * Where a running job is. The openapi component JobProgressStatus (tmesis `v0.6.0`,
 * `docs/API.md` 9.11), JobStatus's `progress`.
 *
 * `percent()` is within the current stage and is not monotonic across a retry.
 */
final readonly class JobProgressStatus
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
     * 0 to 100 within the stage.
     */
    public function percent(): float
    {
        return $this->float('percent');
    }

    /**
     * The running claim's stage (JobStatus::STAGES); null while not running.
     */
    public function stage(): ?string
    {
        $value = $this->raw['stage'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Multiples of realtime for the current stage.
     */
    public function speed(): float
    {
        return $this->float('speed');
    }

    public function etaSeconds(): ?float
    {
        $value = $this->raw['eta_seconds'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
