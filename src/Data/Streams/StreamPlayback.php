<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * Playback URLs on a StreamRecord. The openapi component StreamPlayback,
 * which requires only master. NOTE the name is reused: the webhook
 * $defs.StreamPlayback (Data\Webhooks\StreamPlayback) requires master AND
 * renditions. Two shapes, two classes.
 */
final readonly class StreamPlayback
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

    public function master(): string
    {
        return $this->string('master');
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
}
