<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use stdClass;

/**
 * The jobs pause in force on a node, as its heartbeat reports it
 * (`fleet.heartbeat.request` `$defs.JobsPauseState`, `v0.9.0`, handover 3.5).
 *
 * Read from the node's committed journal row, so the first beat after a restart
 * already carries it: the heartbeat is the source of truth for "stop offering jobs to
 * this node", because a pause restored at boot sends no `jobs.paused`.
 *
 * `source` is CLOSED: `fleet` (this enrollment's desired state) or `api` (a pause the
 * box held when it joined, until the first revision replaces it).
 */
final readonly class JobsPauseState
{
    public const SOURCE_FLEET = 'fleet';

    public const SOURCE_API = 'api';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_FLEET, self::SOURCE_API];

    public function __construct(
        public ?string $until,
        public string $reason,
        public string $source,
    ) {}

    /**
     * @throws FleetPayloadException
     */
    public static function fromWire(stdClass $o, string $path = '$.jobs_pause'): self
    {
        if (! property_exists($o, 'until')) {
            throw new FleetPayloadException("{$path}.until", 'is required');
        }

        if ($o->until !== null && ! is_string($o->until)) {
            throw new FleetPayloadException("{$path}.until", 'must be a string or null');
        }

        return new self(
            $o->until,
            FleetWire::string($o, 'reason', $path),
            FleetWire::enum($o, 'source', $path, self::SOURCES),
        );
    }

    /**
     * @param  array<string, mixed>  $a  a stored copy
     */
    public static function fromStored(array $a): self
    {
        return new self(
            is_string($a['until'] ?? null) ? $a['until'] : null,
            is_string($a['reason'] ?? null) ? $a['reason'] : '',
            is_string($a['source'] ?? null) ? $a['source'] : '',
        );
    }

    public function isFleet(): bool
    {
        return $this->source === self::SOURCE_FLEET;
    }

    /**
     * @return array{until: string|null, reason: string, source: string}
     */
    public function toArray(): array
    {
        return [
            'until' => $this->until,
            'reason' => $this->reason,
            'source' => $this->source,
        ];
    }
}
