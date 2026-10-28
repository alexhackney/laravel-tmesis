<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The answer to a job submission. The openapi component JobAccepted (tmesis `v0.6.0`,
 * `docs/API.md` 9.10), the body of `createTranscodeJob` on both `202` and `200`.
 *
 * `state()` is `queued` for a new job; an idempotent repeat answers the EXISTING job's
 * state, a completed one included.
 */
final readonly class JobAccepted
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private array $raw,
        /**
         * Whether THIS call queued the job: `createTranscodeJob` answered `202`.
         *
         * False on a `200`, the idempotent repeat of a known `idempotency_key`: nothing
         * new was queued and this is the existing job. The same outcome for the caller,
         * which is what makes the retry safe.
         */
        public bool $created = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $created = false): self
    {
        return new self($data, $created);
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

    public function submittedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('submitted_at'));
    }

    public function idempotencyKey(): ?string
    {
        $value = $this->raw['idempotency_key'] ?? null;

        return is_string($value) ? $value : null;
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
}
