<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * Playback URLs on `stream.live` (`playback`) and `stream.sealed` (`vod`).
 *
 * NOTE the name is reused. This is the webhook `$defs.StreamPlayback`, which requires
 * `master`, `renditions`, `preview` (since rc7) and `captions` (since tmesis v0.3.0).
 * The openapi component of the same name, which `Data\Streams\StreamPlayback` models,
 * requires only `master`, and the caption URLs are on this shape alone. Two shapes,
 * two classes, on purpose.
 */
final readonly class StreamPlayback
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
     * The multivariant playlist.
     */
    public function master(): string
    {
        return (string) ($this->raw['master'] ?? '');
    }

    /**
     * One URL per rendition, keyed by rung name.
     *
     * @return array<string, string>
     */
    public function renditions(): array
    {
        $renditions = $this->raw['renditions'] ?? null;

        if (! is_array($renditions)) {
            return [];
        }

        $out = [];

        foreach ($renditions as $name => $url) {
            if (is_string($name) && is_string($url)) {
                $out[$name] = $url;
            }
        }

        return $out;
    }

    /**
     * Where a producer's monitor connects for the low-delay preview. Since Tmesis rc7.
     *
     * Null on `stream.live` when the session runs no tap: the stream's
     * `preview.enabled` is off, the box cannot encode one, or the source has no video
     * (`preview.skipped` on the timeline says which). ALWAYS null on `stream.sealed`
     * (`vod`), which carries the key only because the two events share this shape.
     * The `whep_url` inside it is null until `preview.public_url` is set on the node.
     */
    public function preview(): ?PreviewPlayback
    {
        $value = $this->raw['preview'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return PreviewPlayback::fromArray($value);
    }

    /**
     * One entry per caption language, keyed by RFC 5646 tag. Since tmesis v0.3.0.
     *
     * Null when the session has no caption rendition at all. On `stream.live` each
     * entry's playlist is the DVR one and its sidecars are null; on `stream.sealed`
     * (`vod`) the playlist is the VOD one and the sidecars are what the drain wrote,
     * and a language whose rendition sealed nothing is left out of the map.
     *
     * @return array<string, CaptionPlayback>|null
     */
    public function captions(): ?array
    {
        $captions = $this->raw['captions'] ?? null;

        if (! is_array($captions)) {
            return null;
        }

        $out = [];

        foreach ($captions as $lang => $entry) {
            if (is_string($lang) && is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[$lang] = CaptionPlayback::fromArray($entry);
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
