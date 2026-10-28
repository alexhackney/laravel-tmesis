<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use DateTimeImmutable;

/**
 * An artifact is durable at its destination and this delivery is its registration
 * with the control plane. SHIPPED for `artifact: ingest` since Tmesis rc7 (final
 * plan 11.34), and for the told `master` since tmesis `v0.8.0` (`container: mp4`,
 * sourceGenerations() the joined parts, in join order). This is the event a
 * control plane attaches an asset on.
 *
 * THE NODE DOES NOT READ THE RESPONSE. As built (11.34 D5) the ledger flips to
 * `registered` on the upload (or on finalize alone when the stream has no
 * destination), this event is enqueued, and the sweep of the local copy runs on
 * `registered_at + retention_hours`, checked every five minutes, whatever the
 * receiver answered. The acknowledgement-gated sweep rides with FLEET.
 *
 * FROM tmesis `v0.5.0` REGISTRATION IS NOT RELEASE: a recording with a generation()
 * leaves the box only after an explicit release, override or discard of that
 * generation AND its retention (API.md 12a). The `local_path` description
 * in the schema still describes the older registration-clock sweep.
 *
 * Why the dedupe driver still defaults to `database`: the node is at-least-once
 * and retries, and a dedupe store that forgets on restart re-dispatches every
 * delivery the node retries after the restart, so every listener runs twice for
 * those. This is the event where a double dispatch costs most: an asset attached
 * twice.
 */
class RecordingRegistered extends TmesisWebhookReceived
{
    public const EVENT = 'recording.registered';

    public const ARTIFACT_INGEST = 'ingest';

    public const ARTIFACT_MASTER = 'master';

    public const STATE_REGISTERED = 'registered';

    public const CONTAINER_MP4 = 'mp4';

    public const CONTAINER_MPEGTS = 'mpegts';

    public const CONTAINER_FLV = 'flv';

    public const CONTAINER_NONE = 'none';

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

    /**
     * The two are registered separately and retained separately. A receiver that
     * merges them loses the distinction between the insurance copy and the
     * as-aired program.
     */
    public function artifact(): string
    {
        return $this->string('artifact');
    }

    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * Where the artifact is durable. Told, never derived. This is the path a
     * control plane attaches: the absolute path after a directory copy
     * (`/mnt/archive/ingest/<stream>/<file>` in the generated example), or the
     * node's LOCAL path when no destination was configured (then it equals
     * localPath(), and "durable" means on that box's disk for retention_hours).
     * An `s3://` URL here was a `v0.3.0` form; since tmesis `v0.4.0` bucket
     * delivery is a storage destination and, later, an artifact transfer, and a
     * stream still pointed at `s3://` HOLDS its recordings and never registers
     * them (`StreamSpec::ingestDestination()`).
     */
    public function destination(): string
    {
        return $this->string('destination');
    }

    /**
     * EQUALS the capture container for the ingest copy (`mpegts` or `flv`, never
     * remuxed); `mp4` is the told master's (tmesis `v0.8.0`).
     */
    public function container(): string
    {
        return $this->string('container');
    }

    public function bytes(): int
    {
        return $this->int('bytes');
    }

    public function droppedBytesTotal(): int
    {
        return $this->int('dropped_bytes_total');
    }

    public function durationSeconds(): float
    {
        return $this->float('duration_seconds');
    }

    public function checksumSha256(): string
    {
        return $this->string('checksum_sha256');
    }

    /**
     * The node's copy. Swept once `registered_at + retention_hours` has passed, on
     * the ledger's state and clock, not on this delivery's acknowledgement. When
     * destination() equals this path the sweep removes the only copy, which is what
     * a local-only retention window means.
     */
    public function localPath(): string
    {
        return $this->string('local_path');
    }

    /**
     * The recording's artifact generation, the id the `/artifacts` routes key on
     * (tmesis `v0.5.0`). Null for a recording finalized before `v0.5.0`, which has no
     * artifact row. Always present in the payload, so null is a value, not an
     * absence.
     *
     * Registration is not release: see the class docblock.
     */
    public function generation(): ?string
    {
        return $this->nullableString('generation');
    }

    /**
     * When registration was confirmed. retention_expires_at counts from HERE, not
     * from finalize.
     */
    public function registeredAtRaw(): string
    {
        return $this->required('registered_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function registeredAt(): DateTimeImmutable
    {
        return $this->timestamp('registered_at');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function retentionExpiresAtRaw(): string
    {
        return $this->required('retention_expires_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function retentionExpiresAt(): DateTimeImmutable
    {
        return $this->timestamp('retention_expires_at');
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
