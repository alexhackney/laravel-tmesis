<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * GET /api/v1/streams/:id/transcript with `format=json` (the default): the caption
 * transcript as a FEED, one row per final cue in sequence order (API.md 9.5b).
 *
 * It is a tail read: the call's `since` (a cue `seq`, exclusive) returns only the
 * cues after it while the show runs, and the same route answers the whole session
 * once sealed. The `vtt` and `srt` renderings are opaque strings and are not modelled.
 *
 * @see TranscriptResponse in docs/contract/openapi.json at tmesis v0.3.0.
 */
final readonly class TranscriptResponse
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

    public function stream(): string
    {
        return $this->string('stream');
    }

    /**
     * The publish session the cues belong to.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    /**
     * The caption language rendered, RFC 5646.
     */
    public function lang(): string
    {
        return $this->string('lang');
    }

    /**
     * The first cue's STT provider, or the node's primary when there are no cues.
     */
    public function provider(): string
    {
        return $this->string('provider');
    }

    /**
     * The cues in sequence order. Never null on the wire: an empty transcript is `[]`.
     *
     * @return list<TranscriptCue>
     */
    public function cues(): array
    {
        $cues = $this->raw['cues'] ?? null;

        if (! is_array($cues)) {
            return [];
        }

        $out = [];

        foreach ($cues as $cue) {
            if (is_array($cue)) {
                /** @var array<string, mixed> $cue */
                $out[] = TranscriptCue::fromArray($cue);
            }
        }

        return $out;
    }

    /**
     * The last cue's `seq`, the value to pass as `since` on the next tail read. Null
     * when the page is empty (keep the `since` you sent).
     */
    public function lastSeq(): ?int
    {
        $cues = $this->cues();

        return $cues === [] ? null : $cues[array_key_last($cues)]->seq();
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
