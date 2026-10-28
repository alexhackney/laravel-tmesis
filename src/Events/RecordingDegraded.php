<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * The node shed recording writes to protect the live path. SHIPPED for
 * `artifact: ingest` since Tmesis rc7 (final plan 11.34), M4 for `master`.
 * Correct behaviour, not an outage: nothing about the show changed, and a box that
 * drops a recording to keep the stream up made the right call. Route it to
 * operations, not to whoever gets paged about a show.
 *
 * As built: emitted ONCE per recording PER REASON, on the writer's 5 second tick
 * (so it can lag the first drop by up to a tick; `since` is the real moment), and
 * `recovered` is always false: a stopped writer never resumes for that recording,
 * and the next one (the next session, or a PATCH back on) opens fresh and checks
 * the floor again.
 */
class RecordingDegraded extends TmesisWebhookReceived
{
    public const EVENT = 'recording.degraded';

    public const ARTIFACT_INGEST = 'ingest';

    public const ARTIFACT_MASTER = 'master';

    public const STATE_DEGRADED = 'degraded';

    public const REASON_DISK_FLOOR = 'disk_floor';

    public const REASON_WRITE_DROPS = 'write_drops';

    public const REASON_SLOW_DISK = 'slow_disk';

    public const REASON_WRITE_ERROR = 'write_error';

    public function session(): string
    {
        return $this->string('session');
    }

    public function epoch(): int
    {
        return $this->int('epoch');
    }

    public function recordingId(): string
    {
        return $this->string('recording_id');
    }

    public function artifact(): string
    {
        return $this->string('artifact');
    }

    public function part(): int
    {
        return $this->int('part');
    }

    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * CLOSED set of four: why the node shed recording writes. `write_drops` (the
     * 4 MiB ring evicted its oldest bytes because the disk did not keep up; the
     * file has a hole), `disk_floor` (the node is at limits.disk_floor_gb: the
     * writer STOPPED and every byte since is counted as dropped), `write_error`
     * (the filesystem refused a write: the writer stopped, same accounting), or
     * `slow_disk` (the drain did not finish inside the close budget when the
     * session ended).
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * A human sentence with the numbers behind the reason.
     */
    public function detail(): string
    {
        return $this->string('detail');
    }

    /**
     * Always false as built: a stopped writer does not resume for that recording.
     * The schema keeps the field so the day it reports a resume is an additive
     * change rather than a new event.
     */
    public function recovered(): bool
    {
        return $this->bool('recovered');
    }

    /**
     * Shed since the last recording.degraded for this artifact.
     */
    public function droppedBytes(): int
    {
        return $this->int('dropped_bytes');
    }

    /**
     * Shed over the life of this artifact. It appears again on
     * recording.completed.
     */
    public function droppedBytesTotal(): int
    {
        return $this->int('dropped_bytes_total');
    }

    public function bytes(): int
    {
        return $this->int('bytes');
    }

    /**
     * When the degradation started, which is before this event was enqueued: the
     * node sheds first and tells anyone afterwards.
     */
    public function sinceRaw(): string
    {
        return $this->required('since');
    }

    /**
     * Parsed from the raw value above.
     */
    public function since(): DateTimeImmutable
    {
        return $this->timestamp('since');
    }
}
