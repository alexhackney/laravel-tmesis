<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use stdClass;

/**
 * One durable event riding a heartbeat (`$defs.HeartbeatEvent`).
 *
 * `payload` is the event body as a JSON STRING, and it is byte for byte the body the
 * node would POST to its webhook URL (tmesis seam S1): one id and one serialisation,
 * written to both queues. So `id` equals the body's `delivery`, and the package's
 * webhook dedupe key covers both channels (tmesis D7).
 */
final readonly class FleetHeartbeatEvent
{
    public function __construct(
        public string $id,
        public string $event,
        public string $occurredAt,
        public string $payload,
    ) {}

    public static function fromWire(stdClass $o, string $path): self
    {
        return new self(
            FleetWire::nonEmptyString($o, 'id', $path),
            FleetWire::nonEmptyString($o, 'event', $path),
            FleetWire::string($o, 'occurred_at', $path),
            FleetWire::string($o, 'payload', $path),
        );
    }
}
