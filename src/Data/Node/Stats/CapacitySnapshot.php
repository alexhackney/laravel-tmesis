<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node\Stats;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * Encode capacity on GET /api/v1/stats: what the node will admit, and whether
 * a benchmark measured it or the numbers are the configured defaults.
 *
 * FILLED since `v0.9.0` (handover 3.6) from the same snapshot as the health body's
 * `live_capacity`, so the two cannot disagree, with one difference: while
 * admission() is not `on`, encodeSlotsTotal() is 0 here where health says null.
 * Neither is a fault: `admission: off` admits every publish.
 *
 * Not HealthCapacity, which is the two-field block on GET /api/v1/health, and
 * not DoctorCapacity, which is the doctor report's own. Three components, three
 * shapes, one word.
 */
final readonly class CapacitySnapshot
{
    /** A publish past encodeSlotsTotal() is refused `over_capacity`. */
    public const ADMISSION_ON = 'on';

    /**
     * Unmeasured, stale or insufficient: every publish is admitted and counted, while
     * a known device ceiling still refuses. Not a fault.
     */
    public const ADMISSION_OFF = 'off';

    /** The node does not encode live (passthrough, transcoder). */
    public const ADMISSION_NOT_APPLICABLE = 'not_applicable';

    /**
     * The closed `admission` set (`v0.9.0`, handover 3.6).
     *
     * @var list<string>
     */
    public const ADMISSIONS = [
        self::ADMISSION_ON,
        self::ADMISSION_OFF,
        self::ADMISSION_NOT_APPLICABLE,
    ];

    /** The stored `tmesis bench --profile live --store` record, valid for this box. */
    public const SOURCE_MEASURED = 'measured';

    /** A told `limits.max_streams`. */
    public const SOURCE_CONFIGURED = 'configured';

    /** No record: a box updated in place reads this until it is benched once. */
    public const SOURCE_UNMEASURED = 'unmeasured';

    /** A record of another box, or an unreadable one. */
    public const SOURCE_STALE = 'stale';

    /** A record that found the box cannot hold one session. */
    public const SOURCE_INSUFFICIENT = 'insufficient';

    /** With `admission: not_applicable`. */
    public const SOURCE_NOT_APPLICABLE = 'not_applicable';

    /**
     * The closed `source` set (`v0.9.0`, handover 3.6).
     *
     * @var list<string>
     */
    public const SOURCES = [
        self::SOURCE_MEASURED,
        self::SOURCE_CONFIGURED,
        self::SOURCE_UNMEASURED,
        self::SOURCE_STALE,
        self::SOURCE_INSUFFICIENT,
        self::SOURCE_NOT_APPLICABLE,
    ];

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
     * Live unit admission, one of ADMISSIONS (`v0.9.0`, handover 3.6): the health
     * `live_capacity.admission` value. Only `on` refuses on units; `off` is never a
     * fault.
     */
    public function admission(): string
    {
        return $this->string('admission');
    }

    public function admissionOn(): bool
    {
        return $this->admission() === self::ADMISSION_ON;
    }

    /**
     * Where the limits came from, one of SOURCES (`v0.9.0`, handover 3.6): the health
     * `live_capacity.source` value.
     */
    public function source(): string
    {
        return $this->string('source');
    }

    /**
     * The ladder one unit is one session of; an empty string when not applicable
     * (`v0.9.0`).
     */
    public function unitLadder(): string
    {
        return $this->string('unit_ladder');
    }

    /**
     * The NVENC/QSV encoder session ceiling of a stored record matching this box, or
     * null when none is known (`v0.9.0`). REQUIRED and nullable.
     */
    public function deviceSessionsTotal(): ?int
    {
        $value = $this->raw['device_sessions_total'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Encoder sessions held on the device: live sessions plus running job encoders
     * (`v0.9.0`).
     */
    public function deviceSessionsUsed(): int
    {
        return $this->int('device_sessions_used');
    }

    /**
     * When the stored live measurement was taken (health `live_capacity.measured_at`,
     * stale records included). Null when no record was read.
     */
    public function benchmarkRanAtRaw(): ?string
    {
        return $this->nullableString('benchmark_ran_at');
    }

    /**
     * Parsed. Null when the raw value is null.
     */
    public function benchmarkRanAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('benchmark_ran_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * The live unit limit while admission() is `on`; 0 while it is `off` or
     * `not_applicable`, which means nobody measured, never no capacity. The health
     * body's `live_capacity.encode_slots_total` is null for the same state: read
     * admissionOn() before this number, never `0` as a ceiling.
     */
    public function encodeSlotsTotal(): int
    {
        return $this->int('encode_slots_total');
    }

    /**
     * Units held by live sessions, counted whether or not admission is on; a costlier
     * ladder holds more than one unit.
     */
    public function encodeSlotsUsed(): int
    {
        return $this->int('encode_slots_used');
    }

    /**
     * The told `limits.max_streams`; 0 when the stored measurement decides.
     */
    public function maxStreams(): int
    {
        return $this->int('max_streams');
    }

    /**
     * True only for source() `measured` since `v0.9.0`: the stored live bench record,
     * valid for this box. A configured limit is not measured.
     */
    public function measured(): bool
    {
        return $this->bool('measured');
    }

    /**
     * Always an empty string since `v0.9.0`, which fills this block; the field is
     * kept on the wire for compatibility. Before it, the milestone that would replace
     * the configured numbers (`M3`).
     */
    public function pendingMilestone(): string
    {
        return $this->string('pending_milestone');
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

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
