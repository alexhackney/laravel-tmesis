# Routing and releasing artifacts

Since tmesis `v0.5.0` every sealed show and every recording is an **artifact
generation**: a durable ledger row with a committed inventory (path, size, SHA-256,
content type) and an `inventory_sha256` over it. The node copies a generation where a
**transfer plan** tells it, and deletes its local files **only after an explicit
decision**. The twelve operations are on `artifacts()`, named by their operationIds
(`createMaster` and `purgeArtifact` joined at `v0.8.0`):

```php
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Facades\Tmesis;

$artifacts = Tmesis::node()->artifacts();

$generation = $artifacts->getArtifact('show-2041-main', $id);   // ArtifactStatus

// Route it. expected_revision is the revision this plan REPLACES (0 for the first);
// the request id is a ULID minted here: keep it and resend the SAME object on retry.
$plan = PlanRequest::make($generation->inventorySha256(), expectedRevision: 0)
    ->withTargets(
        PlanTarget::hlsSealed('vod-bucket', prefix: 'shows/2041/'),   // a named destination on the node
    );

$status = $artifacts->replaceTransferPlan('show-2041-main', $id, $plan);   // PlanStatus
$status->created;   // true for revision 1 (201), false for a later revision or a retry (200)

// Watch transfer.completed / transfer.failed, or read:
$artifacts->allTransfers('show-2041-main', $id)->each(fn ($object) => /* ObjectStatus */ null);

// Then release, which is what lets the node delete its copy. $status is the
// PlanStatus YOUR replaceTransferPlan() answered, not a fresh read.
$decision = $artifacts->releaseArtifact(
    'show-2041-main', $id,
    ReleaseRequest::forPlan($status, 'copied to vod-bucket'),
);
$decision->recorded;   // true on the first answer (202), false for an exact duplicate (200)
```

Release the revision your own evidence covers (the `PlanStatus` you wrote, or the
`plan_revision` of the `transfer.completed` you acted on, through
`ReleaseRequest::make()`), never one read just before: if someone else replaced the
plan in between, a revision read then would release a plan you never saw copied, where
yours answers `409 transfer_plan_stale`.

**Nothing leaves a box without a release.** Retention expiry alone never deletes. A
webhook `2xx`, a completed transfer and a `recording.registered` are NOT release. A
control plane that never calls `releaseArtifact()` (or `overrideArtifact()`) for the
generations it copies fills the edge's disk. Wire it for every generation.

The rules the node enforces, and the package does not second-guess:

- **Plans are stale-guarded.** `expected_revision` must name the CURRENT revision, or
  `409 transfer_plan_stale`. Never fill it from a GET just before the PUT: that
  defeats the guard. Replacing a plan supersedes the old revision (its uploads are
  aborted; late results are refused and fire no event).
- **Every write is idempotent on `request_id`.** The same id with the same body
  answers `200` with the stored result; with a different body, `409
  request_conflict`. An omitted `reason` is a different body from `""`.
- **Target order matters.** For a recording awaiting registration, the first target of
  the first accepted plan is the registration target. The builder never reorders.
  Overwrite avoidance is yours: when an earlier request's outcome is ambiguous, use a
  fresh location.
- **Release needs every target complete** (`409 transfer_incomplete`); an override
  waives the targets it names. A generation under another stream is `404`.
- **An abandoned generation takes a discard.** An `hls_sealed` generation that can
  never finalize (a crash with a changed encoder shape, a packager that never built)
  is released by `overrideArtifact($stream, $id, OverrideRequest::discardAbandoned('reason'))`,
  or purged with `purgeArtifact($stream, $id, ReleaseRequest::purgeAbandoned('reason'))`
  (tmesis `v0.8.0`), and nothing else; release and override are
  `409 artifact_not_finalized` for it.
- `artifact.deleted` reports LOCAL removal only. The node never deletes a remote copy.

The nine artifact `409` codes (tmesis `v0.5.0`: `artifact_not_finalized`, `artifact_identity_mismatch`,
`transfer_plan_stale`, `transfer_identity_mismatch`, `transfer_incomplete`,
`request_conflict`, `release_conflict`, `artifact_retiring`,
`artifact_reconciliation_required`), and since `v0.8.0` `artifact_held` (purge) and
`master_disabled` (masters), throw `ConflictException`; branch on `code()`.
`transfer_plan_stale` and `artifact_identity_mismatch` are the two a caller answers by
re-reading and retrying with a NEW request id.

The six events are `ArtifactFinalized`, `ArtifactReleased`, `ArtifactDeleted`,
`TransferCompleted`, `TransferFailed` and `TransferAuthorizationRequired`, each with
the shared `identity()` (`stream()`, `session()`, `sessionEpoch()`, `jobId()`,
`generation()`, `kind()`, `inventorySha256()`, `labels()`). `RecordingRegistered::generation()`
names the recording's generation (null for one finalized before `v0.5.0`).

**A generation belongs to a stream or to a transcode job (`v0.6.0`).** Each output of
a [transcode job](./transcode-jobs.md#transcode-jobs) is its own generation, of one of six `job_*` kinds
(`ArtifactStatus::JOB_KINDS`), and on it `stream()`, `session()` and `sessionEpoch()`
are **null** while `jobId()` names the job; its events go to the job's `callback_url`.
Branch on `identity()->isJobGeneration()` and send the follow-up to the job's routes,
`jobArtifacts()` (the same operations with `Job` in the name, eleven: a job has no
master; keyed by the job id,
taking the same request objects), never to `artifacts()` with a null stream. The
location rule widens with the kinds: `prefix` for `hls_sealed`, `job_hls` and the
multi-file `job_mp4`, `job_thumbnails`, `job_transcript`; `key` for a recording,
`job_audio` and `job_probe` (`PlanTarget::jobHls()` ... `jobProbe()`, or `forKind()`
when the kind arrives as data). A job HLS master defaults to `playlist.m3u8`. The
signing seam below routes a job generation's `transfer.authorization_required` to the
job's routes by itself.

**Purging (tmesis `v0.8.0`).** A release lets the node delete a generation once its
retention has passed; a PURGE asks it to retire the local files NOW. The body is a
release's, so the same evidence rule holds:

```php
$artifacts->purgeArtifact('show-2041-main', $id, ReleaseRequest::forPlan($status, 'copied; free the disk'));
$artifacts->purgeArtifact('show-2041-main', $abandoned, ReleaseRequest::purgeAbandoned('crashed window'));
Tmesis::node()->jobArtifacts()->purgeJobArtifact($jobId, $generation, ReleaseRequest::make(1, $sha, 'delivered'));
```

The node records the purge on the current decision (releasing an unreleased generation,
or discarding an abandoned or failed held one, in the same transaction), and the files
go in seconds; `artifact.deleted` then says `purge()` true with its `purgeReason()`.
`retention_expires_at` never changes, and a remote copy is never touched. `409
artifact_held` is a hold no purge removes (`writer_active`, `recovery_active`,
`transfer_active`, `master_pending`, `local_integrity_failed`), `409
transfer_incomplete` a release the purge includes that is not covered yet, and `409
release_conflict` a purge already recorded under another request.

## Combining a show's parts at show end

A producer's reconnect is a part boundary: one show with two hiccups is three
`recording_ingest` generations (`recording.started` `resume` opens each later one).
Since tmesis `v0.8.0` the node joins them into ONE master MP4 when asked, after the
show; the master is a request, never a stream setting.

```php
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;

// $session is the publish session id the show ran under (from stream.sealed or your
// own record of it) and $showId is your own identifier for the show.

// At show end (stream.sealed, or your own "show over"): the ingest generations of
// the show, in any order. The node joins them in the order their recordings started.
$parts = Tmesis::node()->artifacts()->allArtifacts('show-2041-main')
    ->filter(fn ($g) => $g->kind() === ArtifactStatus::KIND_RECORDING_INGEST && $g->session() === $session)
    ->map->id()->values()->all();

// Mint the request ONCE and keep it (a job, a row): resending THIS object is the
// idempotent retry, a new MasterRequest is a new request id.
$request = MasterRequest::make(
    $parts,
    PlanTarget::recordingMaster('media', "shows/{$showId}/master.mp4"),   // the key, verbatim
)->withReason("show {$showId} ended");

$master = Tmesis::node()->artifacts()->createMaster('show-2041-main', $request);
$master->created;            // true for the 202, false for an exact retry (200)
$master->master()->state();  // queued, then running, completed or failed
```

- The target is one `recording_master` target: a `destination_ref`, or a signed bucket
  WITHOUT initial authorizations (`MasterRequest` refuses them; the signer supplies
  them when `transfer.authorization_required` names the object). The node never
  builds a name: `key` is the complete object key.
- While the master is queued or running, each part carries the hold reason
  `master_pending` and a purge of it is `409 artifact_held`.
- The events are the ordinary `recording.*` with `artifact: master` and
  `sourceGenerations()` (join order): `recording.started` when the remux begins,
  `recording.completed` with `master_finished` and `container: mp4`, then
  `recording.registered`. At completion the target is the master generation's plan
  revision 1 and the upload reports as `transfer.*` like any other.
- **Nothing auto-releases.** Release (or purge) the master after its
  `transfer.completed`, and the parts once you no longer need them.
- `409 master_disabled` (`recording.master.enabled` is off on the node; it defaults on
  wherever the ingest copy is on), `409 artifact_not_finalized` (a part is still
  recording), `422 invalid_source` and `422 sources_incompatible` (the details name
  each source or the first differing field), `503 master_unavailable` (no ffmpeg on
  the node).

## Signed transfers

A signed target's credentials never reach the node. When it needs signed requests for
an object it fires `transfer.authorization_required`, and this package answers it once
you give it a signer: implement `AlexHackney\Tmesis\Transfers\TransferSigner` and either bind
it in a service provider's `register()` or name the class in `TMESIS_TRANSFER_SIGNER`.
With neither, nothing listens and the object waits.

```php
use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Transfers\TransferAuthorization;
use AlexHackney\Tmesis\Transfers\TransferAuthorizationNeed;
use AlexHackney\Tmesis\Transfers\TransferSigner;

final class S3Signer implements TransferSigner
{
    public function sign(TransferAuthorizationNeed $need): ?TransferAuthorization
    {
        $target = $need->target();   // bucket, region, endpoint, addressing
        $key = $need->objectKey();

        return TransferAuthorization::make(
            put: $need->requires('put') ? $this->presign('PUT', $target, $key) : null,
            parts: array_map(
                fn ($part) => PartAuthorization::make($part->partNumber(), $this->presignPart($target, $key, $need->multipartUploadId(), $part)),
                $need->owedParts(),
            ),
            head: $need->requiresHead() ? $this->presign('HEAD', $target, $key) : null,
            // initiate:, complete:, abort: likewise, when $need->requires(...) them
        );
    }
}
```

The queued `RefreshTransferAuthorization` listener reads the plan, the object and any
owed parts, and hands `sign()` a `TransferAuthorizationNeed`. Return null to leave the
object waiting (the node does not ask again until a new need or a restart). The
package handles the binding fields, the authorization revision, splitting into
refreshes of at most 128 parts, and a `409` that means a newer need already won.
**Always be able to sign a HEAD:** `completion_probe_required` asks for a HEAD alone
after a lost write outcome, and the node writes nothing more for the object until a
HEAD settles it. The package ships no S3 client; use your own presigner.

Signed URLs and headers are credentials: nothing in the package logs, records,
queues or prints them, and signing happens on the queue worker. `SignedRequest` has
no URL accessor, holds the URL and header values wrapped in `SensitiveParameterValue`
(so `dump()`, `dd()`, `var_dump()`, `print_r()`, `var_export()` and an array cast show
nothing that signs), and refuses `serialize()`, so it cannot be queued or cached by
accident. The job goes on `TMESIS_TRANSFER_QUEUE`, or the default queue.

The request BODY is another matter: Telescope's HTTP client watcher, and any outbound
request logger, records request bodies, so the supply `PUT` (`supplyTransferAuthorization`)
and a plan carrying initial authorizations put signed URLs there, as a relay `POST`
already puts its URL. Exclude the node's `/artifacts/` routes from them or turn body
capture off.

Initial authorizations (`SignedDestination::make(..., authorizations: [...])`) take
only the inventory path, the object key and the bundle:
`InitialAuthorization::make('playlist.m3u8', 'backup/show/playlist.m3u8', $bundle)`. The plan
fills in `plan_revision` (its `expected_revision` plus 1), `inventory_sha256` and the
enclosing target's `target_id` when it is sent, the three the node refuses with `422`
when they do not match.
