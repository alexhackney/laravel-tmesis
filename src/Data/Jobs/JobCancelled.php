<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * The answer to a cancel. The openapi component JobCancelled (tmesis `v0.6.0`,
 * `docs/API.md` 9.12), the `202` body of `cancelTranscodeJob`.
 *
 * The node superseded every plan the job filed with an empty revision and discarded
 * its HELD generations; a FINALIZED one stays local until released. Objects already
 * transferred stay where they landed: `job.failed` (`cancelled`) lists them.
 */
final readonly class JobCancelled
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

    public function id(): string
    {
        $value = $this->raw['id'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Always `cancelled`.
     */
    public function state(): string
    {
        $value = $this->raw['state'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
