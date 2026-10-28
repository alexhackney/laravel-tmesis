<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The ingest copy's EFFECTIVE recording settings on a StreamRecord, resolved through
 * the node's `recording.ingest.*` config (CONFIG.md 6.6) for every value the stream
 * did not set itself.
 *
 * @see IngestRecordingRecord in docs/contract/openapi.json (since tmesis v0.2.0;
 *      `destination_ref` since v0.6.0).
 */
final readonly class IngestRecordingRecord
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
     * Whether the node byte-copies what the publisher sends. The config default is
     * role-dependent: on by default on `edge` and `standalone`, off on `origin` and
     * `transcoder`.
     */
    public function enabled(): bool
    {
        return $this->bool('enabled');
    }

    /**
     * How long the node keeps its local copy AFTER the artifact is registered
     * (`registered_at + retention_hours`, swept every five minutes). 72 by default.
     */
    public function retentionHours(): int
    {
        return $this->int('retention_hours');
    }

    /**
     * Where the finalized artifact is delivered: an absolute path on the node. Null
     * means LOCAL ONLY, and then the local copy is the only copy for
     * `retentionHours()`.
     *
     * A value that still reads `s3://` was stored before tmesis `v0.4.0` (the API
     * refuses the form on POST and PATCH since Destination WP1); on the node it
     * resolves to a `destination_ref`, and the stream's finalized recordings are
     * HELD on the box (no upload, no `recording.registered`, never swept) until it
     * is PATCHed to a directory. `StreamSpec::ingestDestination()` has the rule.
     */
    public function destination(): ?string
    {
        return $this->nullableString('destination');
    }

    /**
     * The effective `storage.destinations` NAME the finalized recording is held for,
     * or null. REQUIRED and nullable since tmesis v0.6.0, and never set together
     * with destination(). `StreamSpec::ingestDestinationRef()` sets it.
     */
    public function destinationRef(): ?string
    {
        return $this->nullableString('destination_ref');
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

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
