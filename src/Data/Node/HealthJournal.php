<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * The durable journal and its webhook outbox. A climbing outbox_depth with a
 * growing oldest age means the receiver is not answering 2xx.
 */
final readonly class HealthJournal
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

    public function ok(): bool
    {
        return $this->bool('ok');
    }

    /**
     * Undelivered webhooks. A rising number means a receiver is down, never that
     * media is affected.
     */
    public function outboxDepth(): int
    {
        return $this->int('outbox_depth');
    }

    public function outboxOldestAgeSeconds(): int
    {
        return $this->int('outbox_oldest_age_seconds');
    }

    public function schemaVersion(): int
    {
        return $this->int('schema_version');
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
