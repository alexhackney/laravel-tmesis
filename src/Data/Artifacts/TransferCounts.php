<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * Transfer objects by state, over a plan or one of its targets. The openapi component
 * TransferCounts (tmesis `v0.5.0`, `docs/API.md` 12a.3).
 */
final readonly class TransferCounts
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

    public function queued(): int
    {
        return $this->int('queued');
    }

    public function uploading(): int
    {
        return $this->int('uploading');
    }

    public function waitingAuthorization(): int
    {
        return $this->int('waiting_authorization');
    }

    public function retryFailed(): int
    {
        return $this->int('retry_failed');
    }

    public function failed(): int
    {
        return $this->int('failed');
    }

    public function complete(): int
    {
        return $this->int('complete');
    }

    public function total(): int
    {
        return $this->queued() + $this->uploading() + $this->waitingAuthorization()
            + $this->retryFailed() + $this->failed() + $this->complete();
    }

    /**
     * Every object complete. True for an empty plan too, which is never a release on
     * its own: a release is always an explicit call.
     */
    public function allComplete(): bool
    {
        return $this->complete() === $this->total();
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
}
