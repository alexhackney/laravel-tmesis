<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * What left the node for the viewer: segments, the manifest that announced
 * them, and the upload queue behind both.
 *
 * On a `both` node the `upload` block counts the bucket side and the segment and
 * manifest counters count what the packager wrote, which the origin serves: one
 * packager writes one tree (docs/API.md 9.13, tmesis v0.6.0).
 */
final readonly class DeliverySnapshot
{
    public const MODE_BUCKET = 'bucket';

    public const MODE_ORIGIN = 'origin';

    public const MODE_BOTH = 'both';

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

    public function lastSegmentAtRaw(): ?string
    {
        return $this->nullableString('last_segment_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function lastSegmentAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('last_segment_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * The node's configured `delivery.mode`: `bucket`, `origin` or `both` (the
     * `MODE_*` constants), the same value the stream record's and the webhooks'
     * `delivery_mode` carry. A node fact, identical on every stream. Since tmesis
     * v0.6.0.
     */
    public function mode(): string
    {
        return $this->string('mode');
    }

    /**
     * True when this box's own listener serves the HLS output: `origin` or `both`
     * mode with a listener configured. False on a bucket node. Since tmesis v0.6.0.
     */
    public function originServing(): bool
    {
        return ($this->raw['origin_serving'] ?? false) === true;
    }

    /**
     * The `storage.destinations` name the live output uploads to, or null when this
     * mode uploads nothing (an `origin` node carrying a staged selector reads null
     * too). Since tmesis v0.6.0.
     */
    public function liveDestination(): ?string
    {
        return $this->nullableString('live_destination');
    }

    public function manifest(): ManifestSnapshot
    {
        return ManifestSnapshot::fromArray($this->array('manifest'));
    }

    public function mediaSequence(): int
    {
        return $this->int('media_sequence');
    }

    public function packagerDrainOutcome(): string
    {
        return $this->string('packager_drain_outcome');
    }

    /**
     * @return list<RenditionSnapshot>
     */
    public function renditions(): array
    {
        $out = [];

        foreach ($this->array('renditions') as $item) {
            if (is_array($item)) {
                $out[] = RenditionSnapshot::fromArray($item);
            }
        }

        return $out;
    }

    public function secondsSinceLastSegment(): ?float
    {
        return $this->nullableFloat('seconds_since_last_segment');
    }

    public function segmentsTotal(): int
    {
        return $this->int('segments_total');
    }

    public function upload(): UploadSnapshot
    {
        return UploadSnapshot::fromArray($this->array('upload'));
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

    private function nullableFloat(string $key): ?float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
