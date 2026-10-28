<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One transcode job as its row stands. The openapi component JobStatus (tmesis
 * `v0.6.0`, `docs/API.md` 9.11), the body of `getTranscodeJob`.
 *
 * The read for a control plane that missed a webhook, not a polling loop: the `job.*`
 * events are the intended mechanism. `attempts()` counts crashes, boot requeues and
 * transient stage failures and is what `jobs.max_attempts` judges; `evictions()`
 * counts live preemptions and never fails a job.
 */
final readonly class JobStatus
{
    public const STATE_QUEUED = 'queued';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const STATE_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATES = [
        self::STATE_QUEUED,
        self::STATE_CLAIMED,
        self::STATE_RUNNING,
        self::STATE_COMPLETED,
        self::STATE_FAILED,
        self::STATE_CANCELLED,
    ];

    /** @var list<string> */
    public const TERMINAL_STATES = [self::STATE_COMPLETED, self::STATE_FAILED, self::STATE_CANCELLED];

    /** @var list<string> */
    public const STAGES = ['fetch', 'probe', 'audio', 'encode', 'package', 'thumbnails', 'transcribe', 'upload', 'verify'];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    /** @var list<string> */
    public const PRIORITIES = [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH];

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
     * The job id, `job_<ULID>`.
     */
    public function id(): string
    {
        return $this->string('id');
    }

    /**
     * One of STATES.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    public function isTerminal(): bool
    {
        return in_array($this->state(), self::TERMINAL_STATES, true);
    }

    /**
     * One of PRIORITIES.
     */
    public function priority(): string
    {
        return $this->string('priority');
    }

    /**
     * The node running it.
     */
    public function node(): string
    {
        return $this->string('node');
    }

    public function idempotencyKey(): ?string
    {
        return $this->nullableString('idempotency_key');
    }

    public function submittedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('submitted_at'));
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('started_at');
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('completed_at');
    }

    public function attempts(): int
    {
        return $this->int('attempts');
    }

    public function evictions(): int
    {
        return $this->int('evictions');
    }

    public function progress(): JobProgressStatus
    {
        return JobProgressStatus::fromArray($this->object('progress'));
    }

    public function source(): JobSourceReport
    {
        return JobSourceReport::fromArray($this->object('source'));
    }

    /**
     * One entry per requested output in the order submitted, one per rung for `mp4`.
     *
     * @return list<JobOutputReport>
     */
    public function outputs(): array
    {
        return self::outputReports($this->raw['outputs'] ?? null);
    }

    /**
     * The whole error once the job failed or was cancelled; null otherwise.
     */
    public function error(): ?JobError
    {
        $value = $this->raw['error'] ?? null;

        /** @var array<string, mixed>|null $value */
        return is_array($value) ? JobError::fromArray($value) : null;
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return self::stringMap($this->raw['labels'] ?? null);
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

    /**
     * @return list<JobOutputReport>
     *
     * @internal shared with the job events
     */
    public static function outputReports(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = JobOutputReport::fromArray($entry);
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     *
     * @internal shared with JobListItem
     */
    public static function stringMap(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $out[$key] = $item;
            }
        }

        return $out;
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    private function nullableTime(string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($key);

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }
}
