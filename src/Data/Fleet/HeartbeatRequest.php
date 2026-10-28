<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use stdClass;

/**
 * One heartbeat (`fleet.heartbeat.request`, docs/API.md 15.2), parsed strictly.
 *
 * Every field the schema requires must be PRESENT; unknown fields are ignored (a newer
 * node's additions never fail an older receiver). A failure names the JSON path and
 * never a value: `events[].payload` is a whole webhook body.
 *
 * `jobs_pause` (`v0.9.0`, handover 3.5) is the pause in force on the node, null when
 * none is active. Required since tmesis `v0.9.0`; absent means a pre-0.9.0 node, read
 * as no pause (the package ships before the fleet upgrades, so refusing it would take
 * every v0.8.x node offline mid-upgrade). A present value is still checked strictly.
 * A control plane that stops offering jobs must read it here, not only from
 * `jobs.paused`, which a pause restored at boot never sends.
 */
final readonly class HeartbeatRequest
{
    public const ADMISSIONS = ['open', 'draining', 'shutting_down'];

    /**
     * @param  list<FleetStreamState>  $streams
     * @param  list<FleetHeartbeatEvent>  $events
     * @param  list<string>  $ackedCommandIds
     */
    public function __construct(
        public string $node,
        public string $role,
        public string $version,
        public string $startedAt,
        public string $observedAt,
        public int $desiredRevisionApplied,
        public string $admission,
        public string $revocationsDigest,
        public ?RejectedRevision $rejected,
        public FleetNodeStats $stats,
        public array $streams,
        public array $events,
        public array $ackedCommandIds,
        public ?JobsPauseState $jobsPause = null,
    ) {}

    /**
     * @throws FleetPayloadException
     */
    public static function fromRaw(string $raw): self
    {
        return self::fromObject(FleetWire::decode($raw));
    }

    /**
     * @throws FleetPayloadException
     */
    public static function fromObject(stdClass $o): self
    {
        $rejected = null;

        if (! property_exists($o, 'desired_revision_rejected')) {
            throw new FleetPayloadException('$.desired_revision_rejected', 'is required');
        }

        if ($o->desired_revision_rejected !== null) {
            if (! $o->desired_revision_rejected instanceof stdClass) {
                throw new FleetPayloadException('$.desired_revision_rejected', 'must be an object or null');
            }

            $rejected = RejectedRevision::fromWire($o->desired_revision_rejected, '$.desired_revision_rejected');
        }

        // Required since tmesis v0.9.0; absent means a pre-0.9.0 node, read as no pause.
        // The package ships before the nodes upgrade, so a v0.8.x beat must still land.
        $jobsPause = null;

        if (property_exists($o, 'jobs_pause') && $o->jobs_pause !== null) {
            if (! $o->jobs_pause instanceof stdClass) {
                throw new FleetPayloadException('$.jobs_pause', 'must be an object or null');
            }

            $jobsPause = JobsPauseState::fromWire($o->jobs_pause, '$.jobs_pause');
        }

        $streams = [];

        foreach (FleetWire::list($o, 'streams', '$') as $i => $item) {
            if (! $item instanceof stdClass) {
                throw new FleetPayloadException("\$.streams[{$i}]", 'must be an object');
            }

            $streams[] = FleetStreamState::fromWire($item, "\$.streams[{$i}]");
        }

        $events = [];

        foreach (FleetWire::list($o, 'events', '$') as $i => $item) {
            if (! $item instanceof stdClass) {
                throw new FleetPayloadException("\$.events[{$i}]", 'must be an object');
            }

            $events[] = FleetHeartbeatEvent::fromWire($item, "\$.events[{$i}]");
        }

        $digest = FleetWire::string($o, 'revocations_digest', '$');

        if (preg_match('/^[0-9a-f]{64}\z/', $digest) !== 1) {
            throw new FleetPayloadException('$.revocations_digest', 'must be 64 lowercase hex characters');
        }

        return new self(
            node: FleetWire::nonEmptyString($o, 'node', '$'),
            role: FleetWire::enum($o, 'role', '$', EnrollRequest::ROLES),
            version: FleetWire::nonEmptyString($o, 'version', '$'),
            startedAt: FleetWire::string($o, 'started_at', '$'),
            observedAt: FleetWire::string($o, 'observed_at', '$'),
            desiredRevisionApplied: FleetWire::integer($o, 'desired_revision_applied', '$', 0),
            admission: FleetWire::enum($o, 'admission', '$', self::ADMISSIONS),
            revocationsDigest: $digest,
            rejected: $rejected,
            stats: FleetNodeStats::fromWire(FleetWire::object($o, 'stats', '$')),
            streams: $streams,
            events: $events,
            ackedCommandIds: FleetWire::stringList($o, 'acked_command_ids', '$'),
            jobsPause: $jobsPause,
        );
    }
}
