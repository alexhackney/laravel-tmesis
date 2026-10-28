<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

use AlexHackney\Tmesis\Data\Streams\Stats\CaptionSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\DeliverySnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\EncodeSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\IngestSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\PackagerRenditionSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\PreviewSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\RecordingSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\RingSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\SessionSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\SourceSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\TransportRungSnapshot;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * GET /api/v1/streams/:id/stats: one stream's live counters.
 *
 * @see StreamStatsResponse in docs/contract/openapi.json at tmesis v0.3.0. The class
 * keeps the name a Laravel codebase reads better; the component is the pin.
 */
final readonly class StreamStats
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

    public function stream(): string
    {
        $value = $this->raw['stream'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Whether a publisher is currently attached.
     */
    public function open(): bool
    {
        return (bool) ($this->raw['open'] ?? false);
    }

    public function session(): SessionSnapshot
    {
        return SessionSnapshot::fromArray($this->object('session'));
    }

    public function ingest(): IngestSnapshot
    {
        return IngestSnapshot::fromArray($this->object('ingest'));
    }

    /**
     * What the node probed the source to be for the current session. Null before a
     * session's first publish probed anything and for a passthrough stream, which
     * never probes. Since Tmesis rc5.
     */
    public function source(): ?SourceSnapshot
    {
        $value = $this->raw['source'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return SourceSnapshot::fromArray($value);
    }

    /**
     * One entry per ladder rung the encoder is running.
     *
     * @return list<EncodeSnapshot>
     */
    public function encode(): array
    {
        return array_map(
            static fn (array $item): EncodeSnapshot => EncodeSnapshot::fromArray($item),
            $this->listOfObjects('encode'),
        );
    }

    /**
     * @return list<RingSnapshot>
     */
    public function ring(): array
    {
        return array_map(
            static fn (array $item): RingSnapshot => RingSnapshot::fromArray($item),
            $this->listOfObjects('ring'),
        );
    }

    public function delivery(): DeliverySnapshot
    {
        return DeliverySnapshot::fromArray($this->object('delivery'));
    }

    /**
     * One entry per recording artifact for this stream, still running or finished.
     *
     * @return list<RecordingSnapshot>
     */
    public function recording(): array
    {
        return array_map(
            static fn (array $item): RecordingSnapshot => RecordingSnapshot::fromArray($item),
            $this->listOfObjects('recording'),
        );
    }

    /**
     * The low-delay preview tap's counters. Null for a stream that has never had a
     * tap armed, on the source() pattern. Since Tmesis rc7.
     */
    public function preview(): ?PreviewSnapshot
    {
        $value = $this->raw['preview'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return PreviewSnapshot::fromArray($value);
    }

    /**
     * The caption session's counters. Null until a captioner has reported (the key is
     * required and `oneOf [CaptionSnapshot, null]`); once it has, it outlives the
     * session's close for a late reader. Since tmesis v0.3.0.
     */
    public function captions(): ?CaptionSnapshot
    {
        $value = $this->raw['captions'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return CaptionSnapshot::fromArray($value);
    }

    /**
     * One entry per ladder rung while an encoder is running: what the encoder is
     * producing. Empty between sessions, which is the honest answer rather than a
     * zeroed row.
     *
     * The preview tap's two halves (`_preview_video`, `_preview_audio`) are rows here
     * too since rc7; `TransportRungSnapshot::isPreviewTap()` tells them from the
     * renditions, and a consumer building a rendition list skips them.
     *
     * @return list<TransportRungSnapshot>
     */
    public function transport(): array
    {
        return array_map(
            static fn (array $item): TransportRungSnapshot => TransportRungSnapshot::fromArray($item),
            $this->listOfObjects('transport'),
        );
    }

    /**
     * One entry per rendition while a packager is running: what is actually being
     * published.
     *
     * It sits beside transport() rather than inside it because the two can disagree,
     * and the disagreement is itself the diagnosis: fragments arriving with no
     * segments announced is a packager problem, neither arriving is an encoder one.
     *
     * @return list<PackagerRenditionSnapshot>
     */
    public function packager(): array
    {
        return array_map(
            static fn (array $item): PackagerRenditionSnapshot => PackagerRenditionSnapshot::fromArray($item),
            $this->listOfObjects('packager'),
        );
    }

    /**
     * This ONE stream's contribution to the node's degraded state, from the same
     * closed set as GET /api/v1/health.
     *
     * @return list<string>
     */
    public function degradedReasons(): array
    {
        $value = $this->raw['degraded_reasons'] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $reason) {
            if (is_string($reason)) {
                $out[] = $reason;
            }
        }

        return $out;
    }

    public function suppressedUpdates(): int
    {
        $value = $this->raw['suppressed_updates'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    public function observedAtRaw(): string
    {
        $value = $this->raw['observed_at'] ?? null;

        return is_string($value) ? $value : '';
    }

    public function observedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->observedAtRaw());
    }

    /**
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

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfObjects(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }
}
