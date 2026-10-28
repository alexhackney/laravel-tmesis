<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The `recording` block on a StreamRecord: the EFFECTIVE recording settings of the
 * stream, resolved through the node's config. Only the ingest artifact is here: the
 * master is not a stream setting (since tmesis `v0.8.0` it is a request,
 * `Artifacts::createMaster()`, and `recording.master` on a stream body is `422`).
 *
 * @see RecordingRecord in docs/contract/openapi.json at tmesis v0.2.0.
 */
final readonly class RecordingRecord
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

    public function ingest(): IngestRecordingRecord
    {
        return IngestRecordingRecord::fromArray($this->object('ingest'));
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
     * @return array<string, mixed>
     */
    private function object(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }
}
