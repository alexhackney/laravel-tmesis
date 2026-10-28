<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use stdClass;

/**
 * The newest desired-state revision the node refused, and why (tmesis D6).
 *
 * `reason` starts with the JSON path of the offending value (`streams[0].recording:
 * ...`). The node keeps running what it last applied; the host app fixes the document
 * and the next content change is a new revision.
 */
final readonly class RejectedRevision
{
    public function __construct(public int $revision, public string $reason) {}

    public static function fromWire(stdClass $o, string $path): self
    {
        return new self(
            FleetWire::integer($o, 'revision', $path, 1),
            FleetWire::string($o, 'reason', $path),
        );
    }
}
