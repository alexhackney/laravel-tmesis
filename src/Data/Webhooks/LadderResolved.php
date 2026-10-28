<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * How the node resolved the configured ladder against the probed source, on
 * `stream.live` as `ladder_resolved`.
 *
 * The `$defs.LadderResolved` shape of `stream.live.schema.json`. The ladder a stream
 * is provisioned with names ceilings; this block is what the encoder was actually
 * told to run for the session, pinned on the session so a respawn or a rejoin reuses
 * it. `notes` is the human-readable list of what moved and why, one line per rung
 * that was capped, and empty when every rung ran as written.
 */
final readonly class LadderResolved
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

    public function source(): SourceSpec
    {
        $value = $this->raw['source'] ?? null;

        /** @var array<string, mixed> $source */
        $source = is_array($value) ? $value : [];

        return SourceSpec::fromArray($source);
    }

    /**
     * @return list<ResolvedRung>
     */
    public function rungs(): array
    {
        $value = $this->raw['rungs'] ?? null;

        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = ResolvedRung::fromArray($item);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        $value = $this->raw['notes'] ?? null;

        return array_values(array_filter(
            is_array($value) ? $value : [],
            static fn (mixed $note): bool => is_string($note),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
