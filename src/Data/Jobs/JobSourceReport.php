<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * The job's source as the node reports it back. The openapi component JobSourceReport
 * (tmesis `v0.6.0`, `docs/API.md` 9.11), also the `$defs` of the same name in
 * `job.queued` and `job.completed`.
 *
 * A URL source is reported with its query string removed, so a presigned signature
 * never comes back.
 */
final readonly class JobSourceReport
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
     * The `storage.destinations` entry the source was read from; null for a URL source.
     */
    public function destinationRef(): ?string
    {
        return $this->nullableString('destination_ref');
    }

    /**
     * The source object's key in that destination; null for a URL source.
     */
    public function key(): ?string
    {
        return $this->nullableString('key');
    }

    /**
     * The HTTPS source with its query string removed; null for a destination source.
     */
    public function url(): ?string
    {
        return $this->nullableString('url');
    }

    /**
     * The source's size once the fetch learned it; null while unknown.
     */
    public function bytes(): ?int
    {
        $value = $this->raw['bytes'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
