<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The live capacity on GET /api/v1/health (tmesis `v0.9.0`, handover 3.6,
 * `docs/API.md` 9.1). The openapi component HealthLiveCapacity, REQUIRED and never
 * null. Health `capacity()` stays the JOB slots; this is the live ledger.
 *
 * `admission: off` is NOT a fault: an unmeasured box (every box updated in place
 * to 0.9.0 until it is benched) admits every publish and still counts it. Health
 * `status` is never degraded by anything here.
 */
final readonly class HealthLiveCapacity
{
    public const ADMISSION_ON = 'on';

    public const ADMISSION_OFF = 'off';

    public const ADMISSION_NOT_APPLICABLE = 'not_applicable';

    /**
     * The closed `admission` enum, held equal to the schema by its test.
     */
    public const ADMISSIONS = [self::ADMISSION_ON, self::ADMISSION_OFF, self::ADMISSION_NOT_APPLICABLE];

    public const SOURCE_MEASURED = 'measured';

    public const SOURCE_CONFIGURED = 'configured';

    public const SOURCE_UNMEASURED = 'unmeasured';

    public const SOURCE_STALE = 'stale';

    public const SOURCE_INSUFFICIENT = 'insufficient';

    public const SOURCE_NOT_APPLICABLE = 'not_applicable';

    /**
     * The closed `source` enum, held equal to the schema by its test.
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
     * CLOSED set. `on`: a publish past `encodeSlotsTotal()` is refused
     * `over_capacity`. `off`: unmeasured, stale or insufficient; every publish is
     * admitted and counted, while a known device ceiling still refuses.
     * `not_applicable`: the node does not encode live (passthrough or transcoder).
     */
    public function admission(): string
    {
        return $this->string('admission');
    }

    /**
     * Whether the unit limit is enforced, so `encodeSlotsTotal()` is a number.
     */
    public function admissionOn(): bool
    {
        return $this->admission() === self::ADMISSION_ON;
    }

    /**
     * Whether the node encodes live at all.
     */
    public function applicable(): bool
    {
        return $this->admission() !== self::ADMISSION_NOT_APPLICABLE;
    }

    /**
     * CLOSED set. Where the limits came from: `measured` (the stored `tmesis bench
     * --profile live` record valid for this box), `configured` (a told
     * `limits.max_streams`), `unmeasured` (no record), `stale` (a record of another
     * box, or an unreadable one), `insufficient` (the box cannot hold one session),
     * `not_applicable` beside admission `not_applicable`.
     */
    public function source(): string
    {
        return $this->string('source');
    }

    /**
     * The ladder one unit is one session of. `""` when not applicable.
     */
    public function unitLadder(): string
    {
        return $this->string('unit_ladder');
    }

    /**
     * The unit limit. NULL whenever admission is not `on`, never 0 as a stand-in
     * for unknown (the stats `capacity` says 0 for the same state).
     */
    public function encodeSlotsTotal(): ?int
    {
        return $this->nullableInt('encode_slots_total');
    }

    /**
     * Units held by live sessions, always counted, admission on or off. A costlier
     * ladder holds more than one unit.
     */
    public function encodeSlotsUsed(): int
    {
        return $this->int('encode_slots_used');
    }

    /**
     * Units left before a publish is refused `over_capacity`. Null when admission
     * is not on: there is no unit limit to run out of.
     */
    public function encodeSlotsFree(): ?int
    {
        $total = $this->encodeSlotsTotal();

        return $total === null ? null : max(0, $total - $this->encodeSlotsUsed());
    }

    /**
     * The NVENC/QSV encoder session ceiling of a matching stored record. Null when
     * none is known.
     */
    public function deviceSessionsTotal(): ?int
    {
        return $this->nullableInt('device_sessions_total');
    }

    /**
     * Encoder sessions held on the device: live sessions plus running job encoders.
     */
    public function deviceSessionsUsed(): int
    {
        return $this->int('device_sessions_used');
    }

    /**
     * Since v0.9.3. The self-hosted captioned streams this box admits: the caption
     * step's measured count less one stream of headroom (never below 1 for a measured
     * 1 or more), 0 when the step measured 0 (the sidecar cannot carry one captioned
     * stream). Past it a stream's captions start on the fallback provider, or the
     * stream runs uncaptioned. Null when no `self_hosted` provider serves live
     * captions, or the caption step was never measured or does not apply to this
     * sidecar (captions are then admitted without a limit).
     */
    public function captionSessionsTotal(): ?int
    {
        return $this->nullableInt('caption_sessions_total');
    }

    /**
     * Since v0.9.3. Captioned slots held: streams whose live captions run on the
     * self-hosted sidecar now. Null whenever `captionSessionsTotal()` is null.
     */
    public function captionSessionsUsed(): ?int
    {
        return $this->nullableInt('caption_sessions_used');
    }

    /**
     * RFC3339 UTC of the stored record, a stale one included. Null when no record
     * was read.
     */
    public function measuredAtRaw(): ?string
    {
        $value = $this->raw['measured_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Parsed from the raw value above.
     */
    public function measuredAt(): ?DateTimeImmutable
    {
        $raw = $this->measuredAtRaw();

        return $raw === null ? null : Rfc3339::parse($raw);
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
