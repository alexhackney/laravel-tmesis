<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * One enrolled node as the control plane knows it: its identity from enrolment, the
 * last heartbeat's report, and what the package intends it to run.
 *
 * Read-only and built from the fleet store. It never holds a token: the store keeps
 * only the fleet token's sha256, and nothing here exposes even that.
 *
 * `converged()` is the one question a UI usually asks: the node has applied the
 * revision the package intends AND its revocation list matches the intended one. Both
 * halves matter, because the admin API is a second revocation feeder and can move the
 * node's list without a revision change (tmesis WP5).
 *
 * `jobsPause` (`v0.9.0`, handover 3.5) is the pause the last heartbeat reported, null
 * when none was active (and before the first beat). `jobsPaused()` is the stop-offering
 * rule: offer no transcode job to a node it answers true for. The node reports only an
 * ACTIVE pause, but `jobsPause` stays what the LAST beat said, so between beats (and on
 * a stale node that stopped beating) its `until` can pass. `jobsPaused()` therefore
 * applies the node's own rule (`until` null, or now before `until`; tmesis
 * `internal/journal/jobpause.go`) on Laravel's clock, and an `until` it cannot read
 * counts as active: offering a job to a paused box is the worse mistake.
 */
final readonly class FleetNode
{
    /**
     * @param  array{rtmp?: string, srt?: string}  $listen
     * @param  array<string, bool>  $capabilities
     * @param  array<string, string>  $labels  the join token's labels
     * @param  list<FleetStreamState>  $streams
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $role,
        public string $version,
        public array $listen,
        public array $capabilities,
        public array $labels,
        public DateTimeImmutable $enrolledAt,
        public ?DateTimeImmutable $leftAt,
        public ?DateTimeImmutable $lastHeartbeatAt,
        public ?string $startedAt,
        public ?string $admission,
        public ?FleetNodeStats $stats,
        public array $streams,
        public int $appliedRevision,
        public ?RejectedRevision $rejected,
        public ?string $revocationsDigest,
        public ?int $intendedRevision,
        public ?string $intendedRevocationsDigest,
        public ?DateTimeImmutable $staleSince,
        public ?JobsPauseState $jobsPause = null,
    ) {}

    public function enrolled(): bool
    {
        return $this->leftAt === null;
    }

    /**
     * Applied the intended revision, and holds the intended revocation list.
     */
    public function converged(): bool
    {
        return $this->intendedRevision !== null
            && $this->appliedRevision === $this->intendedRevision
            && $this->revocationsDigest === $this->intendedRevocationsDigest;
    }

    public function isStale(): bool
    {
        return $this->staleSince !== null;
    }

    /**
     * Whether the last reported pause is still in force at `$now` (Laravel's clock when
     * omitted): stop offering jobs while this is true.
     */
    public function jobsPaused(?DateTimeInterface $now = null): bool
    {
        if ($this->jobsPause === null) {
            return false;
        }

        if ($this->jobsPause->until === null) {
            return true;
        }

        try {
            $until = Rfc3339::parse($this->jobsPause->until);
        } catch (InvalidArgumentException) {
            return true;
        }

        return ($now ?? Date::now()) < $until;
    }

    public function isDraining(): bool
    {
        return $this->admission === 'draining';
    }

    public function stream(string $id): ?FleetStreamState
    {
        foreach ($this->streams as $stream) {
            if ($stream->stream === $id) {
                return $stream;
            }
        }

        return null;
    }

    public function hasCapability(string $name): bool
    {
        return ($this->capabilities[$name] ?? false) === true;
    }
}
