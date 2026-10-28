<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The body of DELETE /api/v1/streams/:id.
 *
 * @see SealResponse in docs/contract/openapi.json at v0.1.0-rc6.
 *
 * Every value here is EFFECTIVE and never an echo of the request: `graceSeconds()` is
 * what the node will actually wait, and `seal()` is what it will actually do. A
 * response that confirmed the request rather than the decision is how an operator
 * gets told a discard worked while a replay was being written.
 */
final readonly class SealResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private array $raw,
        /**
         * Whether THIS call started the seal.
         *
         * From the status code, which is the only place the fact exists: 202 for the
         * call that started it, 200 for a retry of a seal already under way.
         */
        public bool $started = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $started = true): self
    {
        return new self($data, $started);
    }

    public function id(): string
    {
        $value = $this->raw['id'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The stream's state now: `draining` while the grace period runs, `sealed` once
     * the replay is written.
     */
    public function state(): string
    {
        $value = $this->raw['state'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The grace the node will actually wait before ending an attached session.
     */
    public function graceSeconds(): int
    {
        $value = $this->raw['grace_seconds'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Always true at this tag. `seal=false` (discard rather than seal) is reserved
     * and refused with a 422 before anything happens.
     */
    public function seal(): bool
    {
        return (bool) ($this->raw['seal'] ?? false);
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
}
