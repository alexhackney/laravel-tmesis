<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * The box: CPU, memory, and every accelerator found.
 */
final readonly class DoctorHardware
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

    public function cpu(): DoctorCPU
    {
        return DoctorCPU::fromArray($this->array('cpu'));
    }

    /**
     * @return list<DoctorGPU>
     */
    public function gpus(): array
    {
        $out = [];

        foreach ($this->array('gpus') as $item) {
            if (is_array($item)) {
                $out[] = DoctorGPU::fromArray($item);
            }
        }

        return $out;
    }

    public function memoryBytes(): int
    {
        return $this->int('memory_bytes');
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

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
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
