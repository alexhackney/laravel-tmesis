<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * One input an edit read. The openapi component JobEditInput (tmesis `v0.10.0`,
 * `docs/API.md` 9.10), an entry of `JobEdit::inputs()`, in first-use order.
 */
final readonly class JobEditInput
{
    public const KIND_FILE = 'file';

    public const KIND_HLS = 'hls';

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
     * `file` or `hls`.
     */
    public function kind(): string
    {
        $value = $this->raw['kind'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * `s3://<bucket>/<key or prefix>`: never a credential or a query.
     */
    public function location(): string
    {
        $value = $this->raw['location'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The file's size at the identity check; null for an HLS input.
     */
    public function bytes(): ?int
    {
        $value = $this->raw['bytes'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The file's ETag at the identity check; null for an HLS input.
     */
    public function etag(): ?string
    {
        $value = $this->raw['etag'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
