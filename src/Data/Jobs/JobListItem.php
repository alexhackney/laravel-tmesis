<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One job SUMMARY on a list page. The openapi component JobListItem (tmesis `v0.6.0`,
 * `docs/API.md` 9.10a).
 *
 * Not the 9.11 status: no outputs, no source and only the error CODE. Those are one
 * getTranscodeJob() away.
 */
final readonly class JobListItem
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
     * The job id, `job_<ULID>`.
     */
    public function id(): string
    {
        return $this->string('id');
    }

    /**
     * One of `JobStatus::STATES`.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * One of `JobStatus::PRIORITIES`.
     */
    public function priority(): string
    {
        return $this->string('priority');
    }

    /**
     * The stage of a running job (`JobStatus::STAGES`); null otherwise.
     */
    public function stage(): ?string
    {
        return $this->nullableString('stage');
    }

    /**
     * 0 to 100 within the current stage; 0 when not running.
     */
    public function percent(): float
    {
        $value = $this->raw['percent'] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public function attempts(): int
    {
        return $this->int('attempts');
    }

    public function evictions(): int
    {
        return $this->int('evictions');
    }

    public function submittedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('submitted_at'));
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('started_at');
    }

    /**
     * When it reached a terminal state; null until then.
     */
    public function completedAt(): ?DateTimeImmutable
    {
        return $this->nullableTime('completed_at');
    }

    /**
     * A failed or cancelled job's `error.code`; null otherwise.
     */
    public function errorCode(): ?string
    {
        return $this->nullableString('error_code');
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return JobStatus::stringMap($this->raw['labels'] ?? null);
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableTime(string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($key);

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }
}
