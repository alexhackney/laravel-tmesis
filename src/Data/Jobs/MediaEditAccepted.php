<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The answer to an edit submission. The openapi component MediaEditAccepted (tmesis
 * `v0.10.0`, `docs/API.md` 9.13), the body of `createMediaEdit` on both `202` and
 * `200`. It is ONE transcode job: status is `getTranscodeJob($id)`, cancel is
 * `cancelTranscodeJob($id)`, and the lifecycle is the `job.*` events.
 *
 * `state()` is `queued` for a new job; an idempotent repeat answers the EXISTING job's
 * state, a finished one included.
 */
final readonly class MediaEditAccepted
{
    public const KIND = 'media_edit';

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private array $raw,
        /**
         * Whether THIS call queued the job: `createMediaEdit` answered `202`.
         *
         * False on a `200`, the idempotent repeat of a known `idempotency_key`: nothing
         * new was queued and this is the existing job.
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
     * Always `media_edit`.
     */
    public function kind(): string
    {
        return $this->string('kind');
    }

    public function cut(): EditCut
    {
        $value = $this->raw['cut'] ?? null;

        /** @var array<string, mixed>|null $value */
        return EditCut::fromArray(is_array($value) ? $value : []);
    }

    /**
     * The operations as submitted.
     *
     * @return list<EditOperation>
     */
    public function operations(): array
    {
        return EditSpec::fromArray(['operations' => $this->raw['operations'] ?? []])->operations();
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
