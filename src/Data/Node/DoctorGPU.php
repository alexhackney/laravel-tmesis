<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * One accelerator the node found.
 */
final readonly class DoctorGPU
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

    public function index(): int
    {
        return $this->int('index');
    }

    public function model(): string
    {
        return $this->string('model');
    }

    public function renderNode(): string
    {
        return $this->string('render_node');
    }

    public function vendor(): string
    {
        return $this->string('vendor');
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
}
