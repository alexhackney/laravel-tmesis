<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * Which encode backend is active, and why it is not the better one when
 * fallback_reason is non-null. The same component serves doctor `backend` (the
 * live ladder) and `jobs_backend` (VOD jobs) since tmesis `v0.9.0`.
 */
final readonly class DoctorBackend
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

    public function accelerator(): string
    {
        return $this->string('accelerator');
    }

    public function active(): string
    {
        return $this->string('active');
    }

    public function fallbackReason(): ?string
    {
        return $this->nullableString('fallback_reason');
    }

    public function preset(): string
    {
        return $this->string('preset');
    }

    /**
     * The config value: `auto`, or the pinned encoder (tmesis `v0.9.0`, handover
     * 3.4). An open string here, where health's `requested` is a closed enum.
     */
    public function requested(): string
    {
        return $this->string('requested');
    }

    /**
     * False ONLY for a pin that failed at boot (tmesis `v0.9.0`, handover 3.4):
     * `active()` then names the pin and that workload is refused. True when absent,
     * which is what every pre-`v0.9.0` node meant.
     */
    public function usable(): bool
    {
        return $this->nullableBool('usable') ?? true;
    }

    public function zeroCopy(): ?bool
    {
        return $this->nullableBool('zero_copy');
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

    private function nullableBool(string $key): ?bool
    {
        $value = $this->raw[$key] ?? null;

        return is_bool($value) ? $value : null;
    }
}
