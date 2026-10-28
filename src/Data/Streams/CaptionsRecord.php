<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The stream's EFFECTIVE caption settings on a StreamRecord, resolved through the
 * node's `transcription.*` config for every value the stream did not set itself.
 * Always present on the record since tmesis v0.3.0, every field a value.
 *
 * @see CaptionsRecord in docs/contract/openapi.json at tmesis v0.3.0.
 */
final readonly class CaptionsRecord
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
     * Whether the node opens a provider session for this stream and writes the
     * caption rendition. A captioning stream streams audio to the provider for the
     * whole session, silence included.
     */
    public function enabled(): bool
    {
        return $this->bool('enabled');
    }

    /**
     * Whether the masters carry the `EXT-X-MEDIA TYPE=SUBTITLES` group. The writer
     * runs whenever enabled() is on, advertised or not.
     */
    public function advertise(): bool
    {
        return $this->bool('advertise');
    }

    /**
     * The effective STT provider: the node's `transcription.provider` when the stream
     * named none, and `none` on a node with no provider. This is the node's primary;
     * a session may use the configured fallback provider instead. Never null.
     */
    public function provider(): string
    {
        $value = $this->raw['provider'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The effective RFC 5646 caption languages, `[transcription.language]` when the
     * stream named none. Exactly one in v1.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $value = $this->raw['languages'] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * The stream's provisioned keyterms, as the node STORED them: trimmed, with
     * duplicates removed case-insensitively (the first spelling and the order kept);
     * `[]` when it named none. REQUIRED since tmesis `v0.8.0`.
     *
     * A live session uses the keyterms hook's answer INSTEAD once a configured hook
     * has answered in that session (decision D14), so this is not necessarily the
     * list in force on a captioning stream.
     *
     * @return list<string>
     */
    public function keyterms(): array
    {
        $value = $this->raw['keyterms'] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * The provider session for the current publish. Null unless the read sent
     * `include=captions` and captions are on for the stream (the key is absent,
     * never null, on the wire).
     */
    public function session(): ?CaptionSessionRecord
    {
        $value = $this->raw['session'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return CaptionSessionRecord::fromArray($value);
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

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
