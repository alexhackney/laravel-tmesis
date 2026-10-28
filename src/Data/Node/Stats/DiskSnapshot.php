<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node\Stats;

/**
 * Disk headroom under the node data directory.
 *
 * statfsOk() false means every byte count below is stale: the node could not
 * stat the filesystem and error() says why.
 */
final readonly class DiskSnapshot
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

    public function dataDir(): string
    {
        return $this->string('data_dir');
    }

    /**
     * Why the last statfs failed. The only property of this component that is not
     * required, so it is absent rather than empty while statfsOk() is true.
     */
    public function error(): string
    {
        return $this->string('error');
    }

    public function floorBreached(): bool
    {
        return $this->bool('floor_breached');
    }

    public function floorBytes(): int
    {
        return $this->int('floor_bytes');
    }

    public function freeBytes(): int
    {
        return $this->int('free_bytes');
    }

    public function headroomBytes(): int
    {
        return $this->int('headroom_bytes');
    }

    /**
     * The size of the volume under dataDir(), or 0 when unknown (`v0.9.0`, handover
     * 3.8).
     */
    public function totalBytes(): int
    {
        return $this->int('total_bytes');
    }

    /**
     * The early-warning margin above floorBytes(), or 0 for no margin (`v0.9.0`,
     * handover 3.8 and 3.9): free space inside floor plus margin is what
     * `node.disk_floor_near` reports.
     */
    public function nearMarginBytes(): int
    {
        return $this->int('near_margin_bytes');
    }

    public function statfsOk(): bool
    {
        return $this->bool('statfs_ok');
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

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
