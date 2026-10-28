<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * GET /api/v1/system/doctor: the effective-config endpoint. The openapi
 * component DoctorResponse. config() is an untyped map by contract, so it
 * stays an array here; secrets in it are already masked by the node.
 */
final readonly class DoctorResponse
{
    public const STATUS_OK = 'ok';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_FAIL = 'fail';

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

    public function ageSeconds(): float
    {
        return $this->float('age_seconds');
    }

    public function backend(): DoctorBackend
    {
        return DoctorBackend::fromArray($this->array('backend'));
    }

    /**
     * The VOD jobs encoder (`jobs.video_encoder`, tmesis `v0.9.0`, handover 3.4):
     * REQUIRED, null when jobs are off.
     */
    public function jobsBackend(): ?DoctorBackend
    {
        $value = $this->raw['jobs_backend'] ?? null;

        return is_array($value) ? DoctorBackend::fromArray($value) : null;
    }

    public function benchmark(): ?DoctorBenchmark
    {
        $value = $this->raw['benchmark'] ?? null;

        return is_array($value) ? DoctorBenchmark::fromArray($value) : null;
    }

    public function cached(): bool
    {
        return $this->bool('cached');
    }

    public function capacity(): DoctorCapacity
    {
        return DoctorCapacity::fromArray($this->array('capacity'));
    }

    /**
     * @return list<DoctorCheck>
     */
    public function checks(): array
    {
        $out = [];

        foreach ($this->array('checks') as $item) {
            if (is_array($item)) {
                $out[] = DoctorCheck::fromArray($item);
            }
        }

        return $out;
    }

    public function checksRan(): bool
    {
        return $this->bool('checks_ran');
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->array('config');
    }

    public function contractVersion(): int
    {
        return $this->int('contract_version');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function generatedAtRaw(): string
    {
        return $this->string('generated_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function generatedAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('generated_at'));
    }

    public function hardware(): DoctorHardware
    {
        return DoctorHardware::fromArray($this->array('hardware'));
    }

    public function node(): string
    {
        return $this->string('node');
    }

    public function nodeRole(): string
    {
        return $this->string('node_role');
    }

    public function status(): ?string
    {
        return $this->nullableString('status');
    }

    public function version(): string
    {
        return $this->string('version');
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

    private function float(string $key): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
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
