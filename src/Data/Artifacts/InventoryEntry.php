<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * One file of a finalized generation. The openapi component InventoryEntry (tmesis
 * `v0.5.0`, `docs/API.md` 12a.2).
 *
 * `path()` is relative to the generation and never a local filesystem path.
 */
final readonly class InventoryEntry
{
    /** Media, init, caption segments and sidecars. */
    public const ORDER_MEDIA = 0;

    /** Playlists. */
    public const ORDER_PLAYLISTS = 1;

    /** The replay master. */
    public const ORDER_MASTER = 2;

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

    public function path(): string
    {
        return $this->string('path');
    }

    public function sizeBytes(): int
    {
        return $this->int('size_bytes');
    }

    public function checksumSha256(): string
    {
        return $this->string('checksum_sha256');
    }

    public function contentType(): string
    {
        return $this->string('content_type');
    }

    public function cacheControl(): string
    {
        return $this->string('cache_control');
    }

    /**
     * One of the ORDER_* groups. Earlier groups complete before later ones at every
     * target, so a player never finds a playlist naming a segment that is not there.
     */
    public function publishOrder(): int
    {
        return $this->int('publish_order');
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
}
