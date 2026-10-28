<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Preview;

/**
 * GET /api/v1/preview/grants: every LIVE grant on the node, oldest first. Expired and
 * revoked grants are not listed. The openapi component PreviewGrantListResponse
 * (tmesis v0.6.0).
 */
final readonly class PreviewGrantListResponse
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

    /**
     * @return list<PreviewGrant>
     */
    public function grants(): array
    {
        $out = [];
        $value = $this->raw['grants'] ?? null;

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = PreviewGrant::fromArray($item);
            }
        }

        return $out;
    }

    /**
     * The live grants carrying every one of these labels: how a control plane finds
     * its own (a panel's, a user's) among the node's.
     *
     * @param  array<string, string>  $labels
     * @return list<PreviewGrant>
     */
    public function withLabels(array $labels): array
    {
        return array_values(array_filter(
            $this->grants(),
            static fn (PreviewGrant $grant): bool => array_intersect_assoc($labels, $grant->labels()) === $labels,
        ));
    }

    /**
     * The response as it arrived, unknown fields included; each grant through
     * PreviewGrant::toArray(), so an empty label map stays an object.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $raw = $this->raw;

        if (is_array($raw['grants'] ?? null)) {
            $raw['grants'] = array_map(
                static fn (mixed $grant): mixed => is_array($grant) ? PreviewGrant::fromArray($grant)->toArray() : $grant,
                $raw['grants'],
            );
        }

        return $raw;
    }
}
