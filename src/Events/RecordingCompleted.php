<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * An artifact was finalized on local disk: writing stopped, the file fsynced and
 * closed, the counters final. SHIPPED for `artifact: ingest` since Tmesis rc7
 * (final plan 11.34), and for the told `master` since tmesis `v0.8.0` (reason
 * `master_finished`, `container: mp4`, sourceGenerations() the joined parts). This
 * is NOT the registration; the file is
 * still only on the node, and recording.registered is the event a control plane
 * attaches an asset on.
 *
 * As built for the ingest copy there is no remux and no stitching: `container`
 * equals the capture container, `parts` is always 1, and `duration_seconds` is
 * wall clock from open to close. A hold is a part boundary: the publisher leaving
 * a held session closes the open part here (`reason: input_lost`) and the rejoin
 * opens the next one as its own row (`recording.started` with `started_by:
 * resume`, a new `recording_id`). So one show with a producer hiccup is several
 * files, contiguous in time, each registered on its own; anything that assumed
 * one recording per session, or that `publisher_stop` means the show ended,
 * reads reason().
 */
class RecordingCompleted extends TmesisWebhookReceived
{
    public const EVENT = 'recording.completed';

    public const ARTIFACT_INGEST = 'ingest';

    public const ARTIFACT_MASTER = 'master';

    public const STATE_FINALIZED = 'finalized';

    public const REASON_PUBLISHER_STOP = 'publisher_stop';

    public const REASON_API_STOP = 'api_stop';

    public const REASON_STREAM_SEALED = 'stream_sealed';

    public const REASON_NODE_DRAIN = 'node_drain';

    public const REASON_ERROR = 'error';

    /**
     * The part closed when the publisher left and the session was held
     * (stream.input_lost): the file is complete and clean to its last byte, and a
     * rejoin opens the next part as its own row (recording.started `resume`).
     * Since tmesis `v0.2.0` step 1 (final plan 11.38).
     */
    public const REASON_INPUT_LOST = 'input_lost';

    /**
     * The told master's remux made its MP4 (tmesis `v0.8.0`, `createMaster()`): the
     * ONLY reason a master's recording.completed carries, with `container: mp4`.
     */
    public const REASON_MASTER_FINISHED = 'master_finished';

    public const CONTAINER_MP4 = 'mp4';

    public const CONTAINER_MPEGTS = 'mpegts';

    public const CONTAINER_FLV = 'flv';

    public const CONTAINER_NONE = 'none';

    public const CAPTURE_CONTAINER_MPEGTS = 'mpegts';

    public const CAPTURE_CONTAINER_FLV = 'flv';

    public const CAPTURE_CONTAINER_FMP4 = 'fmp4';

    /**
     * The one session the file belongs to (one file, one session, as built). A hold
     * closes the recording (`input_lost`) and the rejoin opens the next part under
     * a new recording_id in the SAME session; correlate on recording_id.
     */
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

    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * CLOSED set: what ended the capture. `publisher_stop` is the publisher leaving
     * on a node with NO hold (an edge, a passthrough box); `input_lost` is the hold
     * closing the part (the publisher left and the session is held, since tmesis
     * `v0.2.0` step 1); a hold that EXPIRES, a kick, a DELETE and a drain on a HELD
     * session close nothing, because the part is already final under `input_lost`.
     * `api_stop` (a PATCH turned it off, or a kick or takeover on a LIVE session),
     * `stream_sealed` (an API DELETE on a live session), `node_drain` (SIGTERM), or
     * `error`: a row a dead process left running, closed from the file at the next
     * boot by orphan recovery, with `bytes` taken from the file's size.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * Always 1: one row is one file and nothing is stitched (11.34 D4, 11.38); the
     * schema pins it with `const: 1`. A rejoin after a hold opens a new row rather
     * than a longer file, so this never counts a session's parts: a session with
     * three rejoins is four rows, `part` 0 to 3, each with its own
     * recording.completed.
     */
    public function parts(): int
    {
        return $this->int('parts');
    }

    public function path(): string
    {
        return $this->string('path');
    }

    /**
     * The finalized container. EQUALS the capture container for the ingest copy
     * (`mpegts` or `flv`, never remuxed); `mp4` is the master's, after its
     * remux joined its sources into one faststart MP4 (tmesis `v0.8.0`).
     */
    public function container(): string
    {
        return $this->string('container');
    }

    /**
     * What it was written as while live. Finalize is the only point at which a
     * non-progressive container is allowed to exist.
     */
    public function captureContainer(): string
    {
        return $this->string('capture_container');
    }

    public function bytes(): int
    {
        return $this->int('bytes');
    }

    /**
     * Non-zero means writes were shed, the file is incomplete, and a
     * recording.degraded explained why. A real byte count (unlike the stats body's
     * `ingest.dropped_bytes_total`, which is packets).
     */
    public function droppedBytesTotal(): int
    {
        return $this->int('dropped_bytes_total');
    }

    /**
     * WALL CLOCK from open to close, as built: neither container is parsed, so it is
     * the session's span for that part rather than a media duration. A file with
     * shed writes therefore reads a FULL duration here and a shorter one in a
     * player; droppedBytesTotal() is the number that says so.
     */
    public function durationSeconds(): float
    {
        return $this->float('duration_seconds');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function startedAtRaw(): string
    {
        return $this->required('started_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function startedAt(): DateTimeImmutable
    {
        return $this->timestamp('started_at');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function endedAtRaw(): string
    {
        return $this->required('ended_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function endedAt(): DateTimeImmutable
    {
        return $this->timestamp('ended_at');
    }

    /**
     * SHA-256 of the file as closed, hashed by the writer as it wrote (or from disk,
     * for a recording a previous process left running and this boot closed), so the
     * destination copy can be verified independently.
     */
    public function checksumSha256(): string
    {
        return $this->string('checksum_sha256');
    }

    public function destination(): ?string
    {
        return $this->nullableString('destination');
    }

    /**
     * Always null on this event: the schema pins it with const null. Retention is
     * decided at registration, and this event fires before that. The accessor
     * exists so the shape is complete and so the day it becomes a real value is an
     * additive change rather than a new method.
     */
    public function retentionExpiresAtRaw(): ?string
    {
        return $this->nullableString('retention_expires_at');
    }

    /**
     * Always null on this event: the schema pins it with const null. Retention is
     * decided at registration, and this event fires before that. The accessor
     * exists so the shape is complete and so the day it becomes a real value is an
     * additive change rather than a new method.
     */
    public function retentionExpiresAt(): ?DateTimeImmutable
    {
        return $this->nullableTimestamp('retention_expires_at');
    }

    /**
     * The told master's source generations (`recording_ingest` generation ids), in
     * JOIN order: the order their recordings started, whatever order the request
     * listed them in. Always `[]` for the ingest artifact. REQUIRED since tmesis
     * `v0.8.0` (`Artifacts::createMaster()`).
     *
     * @return list<string>
     */
    public function sourceGenerations(): array
    {
        return $this->stringList('source_generations');
    }
}
