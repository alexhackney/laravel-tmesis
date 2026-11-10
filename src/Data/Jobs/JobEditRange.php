<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * One kept range of a finished edit, where the cut actually landed. The openapi
 * component JobEditRange (tmesis `v0.10.0`, `docs/API.md` 9.10), an entry of
 * `JobEdit::ranges()`, one per kept range in output order.
 *
 * A keyframe cut's `actualInSeconds()` is the keyframe at or before the request, and an
 * end-of-file snap can land up to one frame short of the requested end.
 */
final readonly class JobEditRange
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
     * The index into `JobEdit::inputs()`.
     */
    public function input(): int
    {
        return $this->int('input');
    }

    public function requestedInSeconds(): float
    {
        return $this->float('requested_in_seconds');
    }

    public function actualInSeconds(): float
    {
        return $this->float('actual_in_seconds');
    }

    public function requestedOutSeconds(): float
    {
        return $this->float('requested_out_seconds');
    }

    public function actualOutSeconds(): float
    {
        return $this->float('actual_out_seconds');
    }

    /**
     * Where this range starts in the edited file.
     */
    public function outputOffsetSeconds(): float
    {
        return $this->float('output_offset_seconds');
    }

    /**
     * How many GOPs were re-encoded; 0 is a pure stream copy.
     */
    public function reencodedGops(): int
    {
        return $this->int('reencoded_gops');
    }

    /**
     * True when every kept frame was re-encoded (a frame operation, or inputs that
     * cannot be copy-joined).
     */
    public function reencodedWhole(): bool
    {
        return (bool) ($this->raw['reencoded_whole'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
