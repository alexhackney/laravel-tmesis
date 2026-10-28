<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * Why a job, or one of its outputs, failed. The openapi component JobError (tmesis
 * `v0.6.0`, `docs/API.md` 9.11), also the `$defs` of the same name in `job.failed`,
 * `job.completed` and `job.progress`.
 *
 * `class()` is the whole point of the taxonomy: `permanent` is never retried, by the
 * node or by the control plane, so a backfill must exclude it; `transient` was already
 * retried by the node up to `jobs.max_attempts`. A cancel is `code` `cancelled` with
 * class `permanent`: treat it as a cancellation, never as a failure to alert on.
 */
final readonly class JobError
{
    public const CLASS_PERMANENT = 'permanent';

    public const CLASS_TRANSIENT = 'transient';

    public const CODE_CANCELLED = 'cancelled';

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
     * From API.md 9.11's closed vocabulary (`source_unreadable`, `source_changed`,
     * `source_unsupported`, `disk_insufficient`, `package_incomplete`, `timeout`,
     * `cancelled`, `attempts_exhausted`, `internal`, ...). Branch on the constants you
     * need; an unknown code is a newer node.
     */
    public function code(): string
    {
        return $this->string('code');
    }

    /**
     * `permanent` or `transient`.
     */
    public function class(): string
    {
        return $this->string('class');
    }

    public function isPermanent(): bool
    {
        return $this->class() === self::CLASS_PERMANENT;
    }

    public function isCancellation(): bool
    {
        return $this->code() === self::CODE_CANCELLED;
    }

    public function message(): string
    {
        return $this->string('message');
    }

    /**
     * Whether resubmitting the same job could succeed.
     */
    public function retryable(): bool
    {
        return (bool) ($this->raw['retryable'] ?? false);
    }

    /**
     * The stage it happened in (JobStatus::STAGES); null for a failure outside any
     * stage, such as the cancel of a queued job.
     */
    public function stage(): ?string
    {
        $value = $this->raw['stage'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The error as it arrived, unknown fields included.
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
