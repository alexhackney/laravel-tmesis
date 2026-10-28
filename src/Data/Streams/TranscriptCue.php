<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One final cue on the transcript feed: the text, where it sits on the session's
 * media timeline, its wall-clock start, and the word timings.
 *
 * `seq` is the journal's per (stream, session, lang) sequence number, the same one
 * the VTT cues carry. Pass the last one seen as `since` to tail the route while the
 * show runs.
 *
 * @see TranscriptCue in docs/contract/openapi.json at tmesis v0.3.0.
 */
final readonly class TranscriptCue
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

    public function seq(): int
    {
        $value = $this->raw['seq'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Seconds from the session's media origin (the audio rendition's first
     * `EXT-X-PROGRAM-DATE-TIME`), millisecond precision.
     */
    public function start(): float
    {
        return $this->float('start');
    }

    /**
     * Seconds from the session's media origin, millisecond precision.
     */
    public function end(): float
    {
        return $this->float('end');
    }

    /**
     * The cue's wall-clock start, RFC3339 UTC with milliseconds, so a hit in an
     * index maps back to a second of the DVR.
     */
    public function programDateRaw(): string
    {
        return $this->string('program_date');
    }

    /**
     * Parsed from the raw value above.
     */
    public function programDate(): DateTimeImmutable
    {
        return Rfc3339::parse($this->programDateRaw());
    }

    public function text(): string
    {
        return $this->string('text');
    }

    /** The STT provider that produced this cue (v0.9.2); '' from a node before it. */
    public function provider(): string
    {
        return $this->string('provider');
    }

    /**
     * The provider's cue-level diarisation label. The key is ABSENT when the
     * provider gave none (unlike a word's `speaker`, which is present and null).
     */
    public function speaker(): ?int
    {
        $value = $this->raw['speaker'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Word timings. Never null on the wire.
     *
     * @return list<TranscriptWord>
     */
    public function words(): array
    {
        $words = $this->raw['words'] ?? null;

        if (! is_array($words)) {
            return [];
        }

        $out = [];

        foreach ($words as $word) {
            if (is_array($word)) {
                /** @var array<string, mixed> $word */
                $out[] = TranscriptWord::fromArray($word);
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

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
