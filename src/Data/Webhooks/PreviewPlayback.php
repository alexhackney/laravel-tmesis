<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * Where a producer's monitor connects for the low-delay preview, on `stream.live`
 * under `playback.preview` (and, always null, on `stream.sealed` under `vod.preview`).
 *
 * The `$defs.PreviewPlayback` shape shared by `stream.live.schema.json` and
 * `stream.sealed.schema.json`. The same two fields the openapi component
 * `PreviewMonitorRecord` carries on a stream read with `include=preview`.
 */
final readonly class PreviewPlayback
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The WHEP endpoint's path on the node's PREVIEW listener,
     * `/whep/v1/streams/<id>`. Always present.
     */
    public function whepPath(): string
    {
        $value = $this->raw['whep_path'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * That path on the node's `preview.public_url`. Null until that key is set on the
     * node: the box never derives a public base of its own.
     */
    public function whepUrl(): ?string
    {
        $value = $this->raw['whep_url'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
