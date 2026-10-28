<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * GET /api/v1/streams: the registry listing.
 *
 * @see StreamList in docs/contract/openapi.json at v0.1.0-rc2.
 */
final readonly class StreamList
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

    public function count(): int
    {
        $value = $this->raw['count'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<StreamRecord>
     */
    public function streams(): array
    {
        $streams = $this->raw['streams'] ?? null;

        if (! is_array($streams)) {
            return [];
        }

        $out = [];

        foreach ($streams as $stream) {
            if (is_array($stream)) {
                /** @var array<string, mixed> $stream */
                $out[] = StreamRecord::fromArray($stream);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
