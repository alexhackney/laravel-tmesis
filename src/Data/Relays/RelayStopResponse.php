<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Relays;

/**
 * The body of POST /api/v1/streams/:id/relays/stop.
 *
 * @see RelayStopResponse in docs/contract/openapi.json at v0.1.0-rc2.
 *
 * `stopped()` is how many destinations were running and are not any more. Zero is a
 * success: it means nothing was forwarding.
 */
final readonly class RelayStopResponse
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

    public function stopped(): int
    {
        $value = $this->raw['stopped'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
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
