<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One entry on a stream's timeline.
 *
 * @see EventResponse in docs/contract/openapi.json at v0.1.0-rc2. AlexHackney\Tmesis\Events
 * already means "a webhook event", so the class keeps the StreamEvent name and the
 * component is the pin.
 */
final readonly class StreamEvent
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
        return $this->string('id');
    }

    public function stream(): string
    {
        return $this->string('stream');
    }

    public function session(): string
    {
        return $this->string('session');
    }

    public function atRaw(): string
    {
        return $this->string('at');
    }

    public function at(): DateTimeImmutable
    {
        return Rfc3339::parse($this->atRaw());
    }

    public function level(): string
    {
        return $this->string('level');
    }

    /**
     * An OPEN string: the journal's row kinds (`publish.accepted`, `input.lost`,
     * `packager.sealed`, the `api.request` audit rows, and since tmesis `v0.2.0`
     * step 1 the four `window.*` kinds a node crash leaves: `window.recovered`,
     * `window.stitched`, `window.sealed` with a `cause`, `window.discarded` with a
     * `cause` and `segment_dir`; details() carries each kind's object). Since
     * tmesis `v0.8.0` a SEALED orphan window also emits stream.sealed with
     * `sealed_by: crash_recovered` (TMESIS-44; before, the timeline row was the only
     * record); a discarded one still emits nothing. Since tmesis `v0.9.6` the timeline also
     * carries `encoder.video_silent` (an `info` row when every video rung stops delivering
     * while audio flows, another when video returns; timeline only, no webhook). API.md
     * 9.5a, 9.7; final plan 11.37.
     */
    public function kind(): string
    {
        return $this->string('kind');
    }

    public function message(): string
    {
        return $this->string('message');
    }

    /**
     * The kind's own JSON object, or null. Passed through verbatim by the node.
     *
     * @return array<string, mixed>|null
     */
    public function details(): ?array
    {
        $value = $this->raw['details'] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }

    /**
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
