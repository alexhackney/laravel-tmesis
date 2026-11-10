<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Relays;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The totals of a relay destination's last finished session. The openapi component
 * RelayLastSession (tmesis `v0.10.0`, `docs/API.md` 9.9c), `RelayRecord::lastSession()`:
 * equal to the totals on that destination's `relay.stopped`.
 */
final readonly class RelayLastSession
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

    public function endedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('ended_at'));
    }

    public function bytesOut(): int
    {
        return $this->int('bytes_out');
    }

    public function droppedBytes(): int
    {
        return $this->int('dropped_bytes');
    }

    public function reconnects(): int
    {
        return $this->int('reconnects');
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
