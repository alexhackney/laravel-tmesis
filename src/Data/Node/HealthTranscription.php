<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One configured transcription role on the full health body (tmesis `v0.9.1`, K6):
 * the provider instance serving it and whether it works. `transcription` lists the
 * live role first, then batch, then fallback when configured, and is empty when none.
 * Captions fail soft: a role that is down never makes the node unready.
 *
 * @see HealthTranscription the openapi component of the same name
 */
final readonly class HealthTranscription
{
    public const ROLE_LIVE = 'live';

    public const ROLE_BATCH = 'batch';

    public const ROLE_FALLBACK = 'fallback';

    /** The closed `role` enum, held equal to the schema by its test. */
    public const ROLES = [self::ROLE_LIVE, self::ROLE_BATCH, self::ROLE_FALLBACK];

    public const STATE_UP = 'up';

    public const STATE_DOWN = 'down';

    public const STATE_UNKNOWN = 'unknown';

    /** The closed `state` enum, held equal to the schema by its test. */
    public const STATES = [self::STATE_UP, self::STATE_DOWN, self::STATE_UNKNOWN];

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
     * `live` is `transcription.provider` (live captions); `batch` is
     * `transcription.batch_provider` (VOD transcript outputs); `fallback` is the
     * configured `transcription.fallback_provider`.
     */
    public function role(): string
    {
        return $this->string('role');
    }

    /**
     * An open string: `deepgram` or `self_hosted` at this pin.
     */
    public function provider(): string
    {
        return $this->string('provider');
    }

    /**
     * Where this role's requests go, as `scheme://host:port/path`: never a key, never
     * a query. Two roles with the same endpoint share one instance and one state.
     */
    public function endpoint(): string
    {
        return $this->string('endpoint');
    }

    /**
     * `up`, `down`, or `unknown`. `unknown` is no fresh evidence, which is NOT a fault.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * RFC3339 UTC: when `state` last changed. Null while unknown.
     */
    public function sinceRaw(): ?string
    {
        $value = $this->raw['since'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function since(): ?DateTimeImmutable
    {
        $raw = $this->sinceRaw();

        return $raw === null ? null : Rfc3339::parse($raw);
    }

    /**
     * Down 30 seconds in a row: this entry is what puts `transcription_unavailable`
     * on `degraded_reasons`. A `down` entry is not unavailable until then.
     */
    public function unavailable(): bool
    {
        return (bool) ($this->raw['unavailable'] ?? false);
    }

    /**
     * The newest failure in the node's words (end reason and status or network
     * error), never a response body and never a key. Null while up or unknown. A
     * refusal with a configured fallback stays down until tmesis restarts.
     */
    public function detail(): ?string
    {
        $value = $this->raw['detail'] ?? null;

        return is_string($value) ? $value : null;
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
