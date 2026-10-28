<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * What the probe learned of a job's source, on `job.completed`. The `$defs`
 * JobProbeSummary of `job.completed.schema.json` (tmesis `v0.6.0`); not an openapi
 * component.
 */
final readonly class JobProbeSummary
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
     * `codec WxH pix_fmt fps`; null for a source with no video.
     */
    public function video(): ?string
    {
        $value = $this->raw['video'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * `codec rate layout`; null for a source with no audio.
     */
    public function audio(): ?string
    {
        $value = $this->raw['audio'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function durationSeconds(): float
    {
        $value = $this->raw['duration_seconds'] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
