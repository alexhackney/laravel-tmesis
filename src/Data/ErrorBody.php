<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data;

/**
 * The `error` object every non-2xx from the node carries.
 *
 * The openapi component `ErrorBody`. `details` and `milestone` are always PRESENT and
 * may be null; `required` in the contract means present, not non-null.
 */
final readonly class ErrorBody
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The machine-readable code. Branch on this, never on the message. The set is
     * open: an unknown code is mapped by status class instead.
     */
    public function code(): string
    {
        return (string) ($this->raw['code'] ?? '');
    }

    /**
     * A human sentence. Not stable, not for branching.
     */
    public function message(): string
    {
        return (string) ($this->raw['message'] ?? '');
    }

    /**
     * Field-level problems on a 422, empty otherwise.
     *
     * @return list<ErrorDetail>
     */
    public function details(): array
    {
        $details = $this->raw['details'] ?? null;

        if (! is_array($details)) {
            return [];
        }

        $out = [];

        foreach ($details as $detail) {
            if (is_array($detail)) {
                /** @var array<string, mixed> $detail */
                $out[] = ErrorDetail::fromArray($detail);
            }
        }

        return $out;
    }

    /**
     * Correlates with the node's own logs. Also sent back on `X-Request-Id`.
     */
    public function requestId(): string
    {
        return (string) ($this->raw['request_id'] ?? '');
    }

    /**
     * The milestone that delivers this route, on a 501. Null on every other status.
     */
    public function milestone(): ?string
    {
        $milestone = $this->raw['milestone'] ?? null;

        return is_string($milestone) ? $milestone : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
