<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

/**
 * What an edit did. The openapi component JobEdit (tmesis `v0.10.0`, `docs/API.md`
 * 9.10): on `JobStatus::edit()` and `JobCompleted::edit()`, absent for a job without an
 * edit and present once the edit stage has run, whatever the outputs. Read from the
 * node's journal, so it outlives the work directory.
 *
 * **`ranges()` is the field a clip UI reads to say where the cut actually landed.**
 */
final readonly class JobEdit
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
     * The submitted edit echoed (operations and cut), every per-range `source.url`
     * REDACTED to no query, fragment or userinfo: it carries no presigned signature.
     */
    public function request(): EditSpec
    {
        $value = $this->raw['request'] ?? null;

        /** @var array<string, mixed>|null $value */
        return EditSpec::fromArray(is_array($value) ? $value : []);
    }

    /**
     * `keyframe` or `frame_accurate`.
     */
    public function mode(): string
    {
        $value = $this->raw['mode'] ?? null;

        return is_string($value) ? $value : EditCut::MODE_KEYFRAME;
    }

    /**
     * Every input read, in first-use order.
     *
     * @return list<JobEditInput>
     */
    public function inputs(): array
    {
        $out = [];

        foreach (is_array($this->raw['inputs'] ?? null) ? $this->raw['inputs'] : [] as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = JobEditInput::fromArray($entry);
            }
        }

        return $out;
    }

    /**
     * One per kept range, in output order.
     *
     * @return list<JobEditRange>
     */
    public function ranges(): array
    {
        $out = [];

        foreach (is_array($this->raw['ranges'] ?? null) ? $this->raw['ranges'] : [] as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = JobEditRange::fromArray($entry);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
