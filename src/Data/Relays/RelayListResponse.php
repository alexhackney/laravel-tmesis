<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Relays;

/**
 * GET /api/v1/streams/:id/relays. The openapi component RelayListResponse.
 */
final readonly class RelayListResponse
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
     * @return list<RelayRecord>
     */
    public function relays(): array
    {
        $out = [];

        foreach ($this->array('relays') as $item) {
            if (is_array($item)) {
                $out[] = RelayRecord::fromArray($item);
            }
        }

        return $out;
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

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
