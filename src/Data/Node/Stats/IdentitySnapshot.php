<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * Who this node is and how long the process has been up.
 */
final readonly class IdentitySnapshot
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

    public function commit(): string
    {
        return $this->string('commit');
    }

    public function goVersion(): string
    {
        return $this->string('go_version');
    }

    public function node(): string
    {
        return $this->string('node');
    }

    public function pid(): int
    {
        return $this->int('pid');
    }

    public function role(): string
    {
        return $this->string('role');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function startedAtRaw(): string
    {
        return $this->string('started_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function startedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('started_at'));
    }

    public function uptimeSeconds(): float
    {
        return $this->float('uptime_seconds');
    }

    public function version(): string
    {
        return $this->string('version');
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

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
