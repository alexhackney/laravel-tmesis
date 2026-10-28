<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * One object a failed or cancelled job had already transferred, on `job.failed`'s
 * `landed`. The `$defs` JobLandedObject of `job.failed.schema.json` (tmesis `v0.6.0`);
 * not an openapi component.
 *
 * The node never deletes remotely, so this is the list a caller removes itself.
 */
final readonly class JobLandedObject
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

    public function generation(): string
    {
        return $this->string('generation');
    }

    public function targetId(): string
    {
        return $this->string('target_id');
    }

    /**
     * The object's key at its target, bucket-relative.
     */
    public function objectKey(): string
    {
        return $this->string('object_key');
    }

    /**
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
}
