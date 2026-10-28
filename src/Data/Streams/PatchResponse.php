<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * The body of PATCH /api/v1/streams/:id.
 *
 * @see PatchResponse in docs/contract/openapi.json at v0.1.0-rc2.
 *
 * A PATCH answers with what it DID, field by field, rather than with the record: a
 * field can be applied now, pending until the next session, or unchanged because the
 * value was already the one requested. Reading a 200 as "it is set" is how a control
 * plane comes to believe a ladder switched mid-show.
 */
final readonly class PatchResponse
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
     * The record version after the change. It advances on every applied write.
     */
    public function version(): int
    {
        $value = $this->raw['version'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Field path to when it took effect, for the fields that took effect.
     *
     * @return array<string, string>
     */
    public function applied(): array
    {
        $out = [];

        foreach ($this->array('applied') as $path => $when) {
            if (is_string($path) && is_string($when)) {
                $out[$path] = $when;
            }
        }

        return $out;
    }

    /**
     * Field path to the change that is stored but not in force yet.
     *
     * @return array<string, PendingChange>
     */
    public function pending(): array
    {
        $out = [];

        foreach ($this->array('pending') as $path => $change) {
            if (is_string($path) && is_array($change)) {
                /** @var array<string, mixed> $change */
                $out[$path] = PendingChange::fromArray($change);
            }
        }

        return $out;
    }

    /**
     * Fields that were mentioned and already held the value requested.
     *
     * @return list<string>
     */
    public function unchanged(): array
    {
        $out = [];

        foreach ($this->array('unchanged') as $path) {
            if (is_string($path)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * The audit record this change was written under, at the node.
     */
    public function auditId(): string
    {
        $value = $this->raw['audit_id'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Whether anything at all changed. False when every mentioned field was already
     * at the value requested, which is a successful no-op and not a failure.
     */
    public function changedAnything(): bool
    {
        return $this->applied() !== [] || $this->pending() !== [];
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

    /**
     * @return array<array-key, mixed>
     */
    private function array(string $key): array
    {
        $value = $this->raw[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
