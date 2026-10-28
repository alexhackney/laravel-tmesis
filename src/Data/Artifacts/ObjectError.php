<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * The safe error on a transfer object or a part. The openapi component ObjectError
 * (tmesis `v0.5.0`, `docs/API.md` 12a.4).
 *
 * Not the admin error envelope: this is state the node reports on a row, such as
 * `authorization_missing`, `attempts_exhausted`, `initiation_outcome_unknown` or
 * `destination_conflict`, and it is never thrown.
 */
final readonly class ObjectError
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

    public function code(): string
    {
        $value = $this->raw['code'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function message(): string
    {
        $value = $this->raw['message'] ?? null;

        return is_string($value) ? $value : '';
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
}
