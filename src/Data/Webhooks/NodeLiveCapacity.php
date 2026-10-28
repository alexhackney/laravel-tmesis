<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * A node's live capacity, on `node.capacity_changed` (`v0.9.0`, handover 3.3 and 3.6).
 *
 * The `$defs.NodeLiveCapacity` shape: the health `live_capacity` object. On the
 * `admission` and `device` reasons it carries the TRANSITION's own used counts and
 * limits, never a later reading.
 */
final readonly class NodeLiveCapacity
{
    /**
     * Unit admission is enforced: a publish past encode_slots_total() is refused
     * `over_capacity`.
     */
    public const ADMISSION_ON = 'on';

    /**
     * Every publish is admitted and still counted (unmeasured, stale or insufficient).
     */
    public const ADMISSION_OFF = 'off';

    /**
     * A node that encodes no live stream (passthrough or transcoder).
     */
    public const ADMISSION_NOT_APPLICABLE = 'not_applicable';

    /**
     * The stored measurement valid for this box.
     */
    public const SOURCE_MEASURED = 'measured';

    /**
     * A told `limits.max_streams`.
     */
    public const SOURCE_CONFIGURED = 'configured';

    /**
     * No measurement.
     */
    public const SOURCE_UNMEASURED = 'unmeasured';

    /**
     * A record of another box: re-measure. Worth alerting on.
     */
    public const SOURCE_STALE = 'stale';

    /**
     * A record that found the box cannot hold one session. Worth alerting on.
     */
    public const SOURCE_INSUFFICIENT = 'insufficient';

    /**
     * With admission `not_applicable`.
     */
    public const SOURCE_NOT_APPLICABLE = 'not_applicable';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * CLOSED set: the ADMISSION_* constants.
     */
    public function admission(): string
    {
        return $this->string('admission');
    }

    /**
     * Whether unit admission is enforced, which is the only time encodeSlotsTotal()
     * has a value.
     */
    public function admissionOn(): bool
    {
        return $this->admission() === self::ADMISSION_ON;
    }

    /**
     * CLOSED set: the SOURCE_* constants.
     */
    public function source(): string
    {
        return $this->string('source');
    }

    /**
     * The ladder one unit is one session of; empty when not applicable.
     */
    public function unitLadder(): string
    {
        return $this->string('unit_ladder');
    }

    /**
     * The unit limit. Null whenever admission is not on: never 0 as a stand-in for
     * unknown.
     */
    public function encodeSlotsTotal(): ?int
    {
        return $this->nullableInt('encode_slots_total');
    }

    /**
     * Units held by live sessions, counted whether or not admission is on.
     */
    public function encodeSlotsUsed(): int
    {
        return $this->nullableInt('encode_slots_used') ?? 0;
    }

    /**
     * The NVENC/QSV encoder session ceiling; null when no ceiling is known.
     */
    public function deviceSessionsTotal(): ?int
    {
        return $this->nullableInt('device_sessions_total');
    }

    /**
     * Encoder sessions held on the device: live sessions plus running job encoders,
     * so a job can fill the device with no publish.
     */
    public function deviceSessionsUsed(): int
    {
        return $this->nullableInt('device_sessions_used') ?? 0;
    }

    public function measuredAtRaw(): ?string
    {
        $value = $this->raw['measured_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * When the stored record was measured, stale ones included; null when no record
     * was read.
     */
    public function measuredAt(): ?DateTimeImmutable
    {
        $value = $this->measuredAtRaw();

        return $value === null ? null : Rfc3339::parse($value);
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

    private function nullableInt(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
