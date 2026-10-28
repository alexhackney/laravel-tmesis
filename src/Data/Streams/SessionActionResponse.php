<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The body of POST /api/v1/streams/:id/kick and .../takeover.
 *
 * @see SessionActionResponse in docs/contract/openapi.json at v0.1.0-rc6.
 *
 * `session()` is the session that was ENDED, and null when nobody was publishing.
 * Null is a SUCCESS: the caller asked for nobody to be publishing and nobody is,
 * which is what makes a control-plane retry safe.
 */
final readonly class SessionActionResponse
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

    public function id(): string
    {
        $value = $this->raw['id'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The ended session's ULID, or null when there was nobody to end.
     */
    public function session(): ?string
    {
        $value = $this->raw['session'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Whether a publisher was actually displaced.
     */
    public function endedASession(): bool
    {
        return $this->session() !== null;
    }

    /**
     * The stream's state after the action.
     */
    public function state(): string
    {
        $value = $this->raw['state'] ?? null;

        return is_string($value) ? $value : '';
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
