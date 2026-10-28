<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * One word of a final cue on the transcript feed, with its timing and the provider's
 * confidence.
 *
 * `start` and `end` are seconds from the session's media origin, like the cue's. An
 * integral value on the wire (`1`) is still a float here.
 *
 * @see TranscriptWord in docs/contract/openapi.json at tmesis v0.3.0.
 */
final readonly class TranscriptWord
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
     * The bare token, for matching.
     */
    public function word(): string
    {
        return $this->string('word');
    }

    /**
     * The token as it appeared in the rendered text, for display.
     */
    public function punctuatedWord(): string
    {
        return $this->string('punctuated_word');
    }

    public function start(): float
    {
        return $this->float('start');
    }

    public function end(): float
    {
        return $this->float('end');
    }

    public function confidence(): float
    {
        return $this->float('confidence');
    }

    /**
     * The provider's word-level diarisation label. Always present on the wire, null
     * when the provider gave none.
     */
    public function speaker(): ?int
    {
        $value = $this->raw['speaker'] ?? null;

        return is_int($value) ? $value : null;
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
