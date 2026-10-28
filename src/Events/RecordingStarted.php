<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Keys\FencingEpoch;

/**
 * A recording artifact opened its file, at the start of a session or as a numbered
 * continuation part. SHIPPED for `artifact: ingest` since Tmesis rc7 (final plan
 * 11.34), and for `artifact: master` since tmesis `v0.8.0`: the TOLD master
 * (`Artifacts::createMaster()`) fires this once when its remux begins, with
 * `container` its SOURCES' capture container and sourceGenerations() the joined
 * parts. artifact is ingest or master and they are DIFFERENT
 * artifacts with different retention, destinations and meaning; never merge them
 * into one record and never assume which from the event name.
 *
 * As built, one recording_id is ONE FILE in ONE SESSION (11.34 D4), and a hold is
 * a part boundary (11.38, since tmesis `v0.2.0` step 1): a hold closes the open
 * part at stream.input_lost (recording.completed `input_lost`), and the rejoin
 * inside `fallback.end_seconds` opens the next part under a NEW recording_id with
 * `started_by: resume`, byte for byte from the rejoining publisher's first byte.
 * A node restart ends the session, so the next publish opens a new id; a PATCH
 * off then on opens the next part. The protocol decides nothing. Correlate on
 * recording_id still. `path` is
 * `<data_dir>/recordings/<stream>/<session>-ingest-<part>.<ts|flv>`, `container`
 * follows the publish protocol and is never chosen, `source` is `publisher`, and
 * `fencing_epoch` is 0 on a node with no lease.
 *
 * `recording.ingest.enabled` changed by a PATCH while the session is held takes
 * effect AT the rejoin (off opens nothing then, on opens the part then as
 * `resume`), never during the hold.
 */
class RecordingStarted extends TmesisWebhookReceived
{
    public const EVENT = 'recording.started';

    public const ARTIFACT_INGEST = 'ingest';

    public const ARTIFACT_MASTER = 'master';

    public const CONTAINER_MPEGTS = 'mpegts';

    public const CONTAINER_FLV = 'flv';

    public const CONTAINER_FMP4 = 'fmp4';

    public const SOURCE_PUBLISHER = 'publisher';

    public const SOURCE_TOP_RUNG = 'top_rung';

    public const SOURCE_MEZZANINE = 'mezzanine';

    public const STARTED_BY_PUBLISHER = 'publisher';

    public const STARTED_BY_API = 'api';

    /**
     * LIVE since tmesis `v0.2.0` step 1 (final plan 11.38): the part a rejoin opened
     * after a hold, when the publisher returned inside `fallback.end_seconds` with
     * recording on. The stream.input_returned that precedes it carries the same
     * session and epoch and no stream.published, so this is the recording-side
     * signal that the show continued.
     */
    public const STARTED_BY_RESUME = 'resume';

    /**
     * The one session the file belongs to. As built a recording never outlives its
     * session: a hold closes the open part and the rejoin opens the next one under
     * a new recording_id in the SAME session, and a node restart ends the session
     * so the next publish opens a new one. Correlate on recording_id.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    public function epoch(): int
    {
        return $this->int('epoch');
    }

    /**
     * Ledger id, stable across every event for this file: its recording.completed,
     * recording.registered and recording.failed carry the same one. As built it is
     * one file in one session: a hold closes it (`reason: input_lost`) and the
     * rejoin opens a new one; a PATCH off then on and a node restart open a new
     * one too.
     */
    public function recordingId(): string
    {
        return $this->string('recording_id');
    }

    /**
     * CLOSED set. ingest is exactly what the publisher sent, pre-ladder and
     * pre-splice; master is the as-aired program. Read this before doing anything
     * with the event.
     */
    public function artifact(): string
    {
        return $this->string('artifact');
    }

    /**
     * 0-based, per session and artifact. Above 0 is a PATCH that turned recording
     * off then on, or a rejoin after a hold (the part before it closed at the hold
     * with `reason: input_lost`), inside one session; each part is its own file
     * under its own recording_id and parts are NEVER stitched. A restart ends the
     * session, so a part never continues across one.
     */
    public function part(): int
    {
        return $this->int('part');
    }

    /**
     * Local path on the node. NOT a URL and not fetchable over the API.
     */
    public function path(): string
    {
        return $this->string('path');
    }

    /**
     * CLOSED set. The progressive capture container: `mpegts` for an SRT publish,
     * `flv` for an RTMP publish (both byte-copied, because the ingest bytes already
     * are that container). For the told master (tmesis `v0.8.0`) it is its SOURCES'
     * capture container; the master's own MP4 is reported on recording.completed
     * and recording.registered. `fmp4` is in the set and not emitted. A plain MP4
     * with a trailing moov is forbidden for live capture.
     */
    public function container(): string
    {
        return $this->string('container');
    }

    public function source(): string
    {
        return $this->string('source');
    }

    /**
     * The lease epoch this node held when it opened the file. Not the session
     * epoch and not the key epoch.
     */
    public function fencingEpoch(): int
    {
        return $this->int('fencing_epoch');
    }

    /**
     * The same value as fencingEpoch(), typed.
     *
     * The FENCING epoch: which node may write this output prefix. Not the session
     * epoch and not the key epoch (docs/API.md 8.3).
     */
    public function fencingEpochValue(): FencingEpoch
    {
        return FencingEpoch::fromInt($this->fencingEpoch());
    }

    /**
     * CLOSED set. `publisher` is the session's first part (it opened with recording
     * enabled); `api` is a PATCH that turned it on mid-show, a normal, expected
     * value ("start recording, we are going live in ten seconds" is a real producer
     * request); `resume` is LIVE since tmesis `v0.2.0` step 1, the part a rejoin
     * opened after a hold when the publisher returned inside `fallback.end_seconds`
     * with recording on. It matters because the stream.input_returned before it
     * carries the same session and epoch and no stream.published: `resume` is the
     * recording-side signal that the show continued. A PATCH that changed
     * `recording.ingest.enabled` during the hold takes effect at that rejoin, never
     * during the hold.
     */
    public function startedBy(): string
    {
        return $this->string('started_by');
    }

    /**
     * Where the finalized artifact will be delivered. Told, never derived. null
     * means it stays local.
     */
    public function destination(): ?string
    {
        return $this->nullableString('destination');
    }

    public function retentionHours(): int
    {
        return $this->int('retention_hours');
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
