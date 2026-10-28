<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * A recording artifact could not be captured, finalized, uploaded, or
 * registered. SHIPPED for `artifact: ingest` since Tmesis rc7 (final plan 11.34),
 * M4 for `master`. TERMINAL: nothing is uploaded and nothing is registered
 * afterwards, and the control plane's only recovery path is operator action on
 * the node's preserved file, which is never swept automatically at any age.
 * reason is prose and not a closed set: do not branch on it.
 */
class RecordingFailed extends TmesisWebhookReceived
{
    public const EVENT = 'recording.failed';

    public const ARTIFACT_INGEST = 'ingest';

    public const ARTIFACT_MASTER = 'master';

    public const STAGE_CAPTURE = 'capture';

    public const STAGE_FINALIZE = 'finalize';

    public const STAGE_UPLOAD = 'upload';

    public const STAGE_REGISTER = 'register';

    /**
     * The one session the file belongs to (one file, one session, as built).
     * Correlate on recording_id.
     */
    public function session(): string
    {
        return $this->string('session');
    }

    public function recordingId(): string
    {
        return $this->string('recording_id');
    }

    public function artifact(): string
    {
        return $this->string('artifact');
    }

    /**
     * CLOSED set of four: which step failed. It drives routing, because a capture
     * failure is a venue problem and an upload failure is a credentials problem.
     * As built: `capture` is a file that never opened (the disk floor already
     * breached, a create failure), with raw_parts_preserved false and a null path;
     * `finalize` is a row a dead process left whose file is gone at boot, OR a
     * close whose ledger write was refused: the file is complete on disk and kept
     * (raw_parts_preserved true, path set), and nothing uploads or registers it
     * (since tmesis `v0.2.0` step 1; before it that path emitted nothing);
     * `upload` is a permanent (a 403, a missing bucket) or exhausted upload, with
     * the file kept and raw_parts_preserved true; `register` is a finalized file
     * that cannot be read back.
     */
    public function stage(): string
    {
        return $this->string('stage');
    }

    /**
     * One sentence. Free text, because the useful part is the underlying ffmpeg or
     * S3 message.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * true means the captured source parts are still on the node and a human can
     * recover from them. false means there was nothing to preserve.
     */
    public function rawPartsPreserved(): bool
    {
        return $this->bool('raw_parts_preserved');
    }

    /**
     * How many parts the artifact had when it failed. Always 1 as built (one row
     * is one file, 11.34 D4).
     */
    public function parts(): int
    {
        return $this->int('parts');
    }

    /**
     * Where the preserved raw parts are, when raw_parts_preserved is true. null
     * otherwise. The schema enforces the recovery invariant: a non-null path
     * requires raw_parts_preserved true, and a null path requires
     * raw_parts_preserved false (crossfield tag, pinned by
     * TestRecordingFailedSchemaAcceptsAndRejects).
     */
    public function path(): ?string
    {
        return $this->nullableString('path');
    }
}
