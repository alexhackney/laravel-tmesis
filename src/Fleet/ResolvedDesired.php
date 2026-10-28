<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

/**
 * A desired document at the revision the package assigned it.
 */
final readonly class ResolvedDesired
{
    public function __construct(
        public DesiredDocument $document,
        public int $revision,
    ) {}

    /**
     * @return array{revision: int, streams: list<array<string, mixed>>, revocations: list<string>, open_posture: bool, jobs_pause: array{until: string|null, reason: string}|null}
     */
    public function toWire(): array
    {
        return $this->document->toWire($this->revision);
    }

    public function revocationsDigest(): string
    {
        return $this->document->revocationsDigest();
    }
}
