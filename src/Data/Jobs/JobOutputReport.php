<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * One output of a job, as its generation stands. The openapi component JobOutputReport
 * (tmesis `v0.6.0`, `docs/API.md` 9.11), also the `$defs` of the same name in
 * `job.progress` and `job.completed`.
 *
 * One entry per requested output from submission (`pending`, then replaced as its
 * generation appears), and one per rung for `mp4`. `url()` is a REPORT of the first told
 * target (`s3://<bucket>/<key>`): the filed plan is where the bytes go.
 */
final readonly class JobOutputReport
{
    public const STATE_PENDING = 'pending';

    public const STATE_HELD = 'held';

    public const STATE_FINALIZED = 'finalized';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    /** @var list<string> */
    public const STATES = [self::STATE_PENDING, self::STATE_HELD, self::STATE_FINALIZED, self::STATE_COMPLETED, self::STATE_FAILED];

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
     * The output, one of `Spec::OUTPUTS`.
     */
    public function kind(): string
    {
        return $this->string('kind');
    }

    /**
     * The output's artifact generation (a ULID), null until it is created. Read,
     * re-plan and release it through `jobArtifacts()`.
     */
    public function generation(): ?string
    {
        return $this->nullableString('generation');
    }

    /**
     * One of STATES. `completed` means every object of its plan landed.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    public function isCompleted(): bool
    {
        return $this->state() === self::STATE_COMPLETED;
    }

    /**
     * Where the output lands at its FIRST told target; null until known.
     */
    public function url(): ?string
    {
        return $this->nullableString('url');
    }

    /**
     * `mp4`: the ladder rung this entry is.
     */
    public function rung(): ?string
    {
        return $this->nullableString('rung');
    }

    /**
     * The output's size once finalized.
     */
    public function bytes(): ?int
    {
        return $this->nullableInt('bytes');
    }

    /**
     * `thumbnails`: images written.
     */
    public function count(): ?int
    {
        return $this->nullableInt('count');
    }

    /**
     * `thumbnails`: images per selection mode (`interval`, `at_seconds`,
     * `at_percent`, `random`), before deduplication.
     *
     * @return array<string, int>
     */
    public function modeCounts(): array
    {
        $value = $this->raw['mode_counts'] ?? null;
        $out = [];

        foreach (is_array($value) ? $value : [] as $mode => $count) {
            if (is_string($mode) && is_numeric($count)) {
                $out[$mode] = (int) $count;
            }
        }

        return $out;
    }

    /**
     * `thumbnails`: the `random_count` seed, so the pick is reproducible.
     */
    public function seed(): ?int
    {
        return $this->nullableInt('seed');
    }

    /**
     * `audio`: `aac`, `mp3` or `opus`.
     */
    public function format(): ?string
    {
        return $this->nullableString('format');
    }

    /**
     * `transcript`: the language transcribed.
     */
    public function language(): ?string
    {
        return $this->nullableString('language');
    }

    /**
     * `transcript`: the batch provider that transcribed it (since tmesis `v0.9.3`).
     * Absent on every other kind and when no provider answered. The node never sends it
     * null, though the schema allows null, so read it as optional.
     */
    public function provider(): ?string
    {
        return $this->nullableString('provider');
    }

    /**
     * `hls_ladder` or `mp4` from a source with no audio. Present only when true.
     */
    public function videoOnly(): bool
    {
        return (bool) ($this->raw['video_only'] ?? false);
    }

    /**
     * Why this output failed; null unless it did. An optional output (`audio`,
     * `thumbnails`, `transcript`) may fail while the job completes.
     */
    public function error(): ?JobError
    {
        $value = $this->raw['error'] ?? null;

        /** @var array<string, mixed>|null $value */
        return is_array($value) ? JobError::fromArray($value) : null;
    }

    /**
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

    private function nullableString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
