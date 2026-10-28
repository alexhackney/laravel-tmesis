<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events\Fleet;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for the command lifecycle events. A command is identified by its ULID, the id
 * `Tmesis::fleet()->drain()` / `kick()` / `seal()` returned.
 */
abstract class FleetCommandEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $commandId,
        public readonly string $nodeId,
        public readonly string $kind,
        public readonly ?string $stream,
    ) {}
}
