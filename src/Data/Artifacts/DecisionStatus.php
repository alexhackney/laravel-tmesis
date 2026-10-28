<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * A release, override or discard decision on one plan revision. The openapi component
 * DecisionStatus (tmesis `v0.5.0`, `docs/API.md` 12a.5), the body of `releaseArtifact`,
 * `overrideArtifact` and (since tmesis `v0.8.0`) `purgeArtifact`, which records its
 * purge ON the current decision (purgeRequestedAt(), purgeReason()).
 *
 * A decision authorises EVENTUAL local cleanup under retention and every hold, never
 * immediate unlinking. Its identity on the wire is `requestId()`; there is no separate
 * decision id.
 */
final readonly class DecisionStatus
{
    public const ACTION_RELEASE = 'release';

    public const ACTION_OVERRIDE = 'override';

    public const ACTION_DISCARD = 'discard';

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        private array $raw,
        /**
         * Whether THIS call recorded the decision: `202` for the first, `200` for an
         * exact duplicate of one already recorded (the stored result, unchanged).
         *
         * From the status code, the only place the fact exists.
         */
        public bool $recorded = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $recorded = true): self
    {
        return new self($data, $recorded);
    }

    public function requestId(): string
    {
        return $this->string('request_id');
    }

    public function generation(): string
    {
        return $this->string('generation');
    }

    public function planRevision(): int
    {
        $value = $this->raw['plan_revision'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * One of the ACTION_* values.
     */
    public function action(): string
    {
        return $this->string('action');
    }

    /**
     * Derived from the credential the request presented (`admin_token` or `open`); a
     * supplied actor is refused.
     */
    public function actor(): string
    {
        return $this->string('actor');
    }

    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * @return list<string>
     */
    public function waivedTargets(): array
    {
        $value = $this->raw['waived_targets'] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * Whether this decision is on the current plan revision. A later plan makes the
     * generation unreleased again.
     */
    public function current(): bool
    {
        return (bool) ($this->raw['current'] ?? false);
    }

    /**
     * One of the `ArtifactStatus::RELEASE_*` values.
     */
    public function releaseState(): string
    {
        return $this->string('release_state');
    }

    /**
     * Retention still applies. Null is an unknown deadline, which holds the
     * generation.
     */
    public function retentionExpiresAt(): ?DateTimeImmutable
    {
        $value = $this->raw['retention_expires_at'] ?? null;

        return (! is_string($value) || $value === '') ? null : Rfc3339::parse($value);
    }

    public function createdAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('created_at'));
    }

    /**
     * When an operator purge was recorded on this decision (tmesis `v0.8.0`):
     * retirement may begin from then, before retentionExpiresAt(). Null when none was.
     */
    public function purgeRequestedAt(): ?DateTimeImmutable
    {
        $value = $this->raw['purge_requested_at'] ?? null;

        return (! is_string($value) || $value === '') ? null : Rfc3339::parse($value);
    }

    /**
     * The purge's reason; null when none was recorded.
     */
    public function purgeReason(): ?string
    {
        $value = $this->raw['purge_reason'] ?? null;

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
