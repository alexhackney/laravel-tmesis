<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * One doctor check. status() is pass, warn, or fail.
 */
final readonly class DoctorCheck
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

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

    public function detail(): string
    {
        return $this->string('detail');
    }

    public function name(): string
    {
        return $this->string('name');
    }

    public function status(): string
    {
        return $this->string('status');
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
}
