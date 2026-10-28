<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * A told master's request and progress: the `master` of a `recording_master`
 * ArtifactStatus (null on every other kind). The openapi component MasterInfo (tmesis
 * `v0.8.0`, `docs/API.md` 12a.8).
 *
 * The node runs one remux at a time: `queued` waits its turn, `running` is under way
 * (a restart runs it again), `completed` means the MP4 is finalized and its target is
 * the generation's plan revision 1 (the upload's progress is that plan's transfers and
 * the ordinary `transfer.*` events), and `failed` is terminal with error() saying why;
 * the sources are untouched. While queued or running, every source carries the hold
 * reason `master_pending`.
 */
final readonly class MasterInfo
{
    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    /** @var list<string> */
    public const STATES = [self::STATE_QUEUED, self::STATE_RUNNING, self::STATE_COMPLETED, self::STATE_FAILED];

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
     * The caller-minted ULID the master was requested under (`MasterRequest`).
     */
    public function requestId(): string
    {
        return $this->string('request_id');
    }

    /**
     * One of STATES.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    public function isFinished(): bool
    {
        return in_array($this->state(), [self::STATE_COMPLETED, self::STATE_FAILED], true);
    }

    /**
     * The source generations in JOIN order: the order their recordings started,
     * whatever order the request listed them in.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $value = $this->raw['sources'] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * The sources' capture container, `mpegts` or `flv`. The master itself is an MP4.
     */
    public function captureContainer(): string
    {
        return $this->string('capture_container');
    }

    /**
     * The resolved, secret-free target.
     */
    public function target(): MasterTarget
    {
        $value = $this->raw['target'] ?? null;

        /** @var array<string, mixed> $target */
        $target = is_array($value) ? $value : [];

        return MasterTarget::fromArray($target);
    }

    /**
     * The request's reason; `""` when it gave none.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * Why the master failed; null unless state() is `failed`.
     */
    public function error(): ?string
    {
        $value = $this->raw['error'] ?? null;

        return is_string($value) ? $value : null;
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
