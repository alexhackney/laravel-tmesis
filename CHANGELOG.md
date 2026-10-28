# Changelog

All notable changes to `alexhackney/laravel-tmesis` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.5] - 2026-10-28

The tmesis `v0.9.4` and `v0.9.5` contract, released as one package version. tmesis `v0.9.5` is
a patch that changes nothing under `docs/contract/` or `test/vectors/`: every contract
artifact is byte-identical to `v0.9.4`, and the package never tagged its own `0.9.4`.
tmesis `v0.9.4` is a patch that changes the contract in text and template values only: `openapi.json` (md5 `f3b048ab06f80154ead5ae1da103b227`), `test/vectors/` and the
examples are unchanged. Five artifacts moved: the two `caption.session_*` schemas
(description strings), the origin and transcoder role templates and `MD5SUMS`. No DTO,
event or field changed. Pinned to the tmesis `v0.9.5` tag (`bfad9a6`).

### Changed

- The origin and transcoder role templates' `production` ladder (C1): `1080p` moves to
  `fps: "60000/1001"` at `v_kbps: 6000`, `720p` to `fps: "60000/1001"` at `v_kbps: 3500`.
  The other rungs are unchanged. A config rendered from the template gets the new rates;
  an upgraded box keeps its own `encode.ladders.production` until the operator adopts it.
- Docblocks and README only (C2 to C4), nothing decodes differently:
  - every `caption.session_ended` now has a `caption.session_started` for the same caption
    session before it, on every path; a session that ends before its start went out gets
    its start just before the end with `providerRequestId()` null, on either provider, so
    a `self_hosted` start can carry null too;
  - `CaptionSessionEnded::REASON_STALLED` is only a CONNECTED provider not keeping up. An
    outage (unreachable, refusing or dropping connections) is never `stalled`: a short one
    rides through with no end, a long one ends `gave_up` after about 60 s. Read `gave_up`,
    not `stalled`, as "the provider is down";
  - `CaptionSessionStarted::switchedFrom()` is also set on the first open after a rejoin
    while the stream is on the fallback, naming the primary.

### Consumer notes (tmesis v0.9.5)

No shape changed; these are behaviours a webhook, timeline or health consumer may notice.

- `stream.sealed` is no longer lost when several bucket-delivery streams seal together
  (B1): v0.9.4 could drop it when the output sink used up the drain budget, so expect one
  for every seal. Deliveries are still unordered (order by `occurred_at`; `sealed` can
  arrive before `unpublished`) and receivers dedupe on `X-Tmesis-Delivery`. Seals lost
  before the upgrade are not replayed.
- `captions.skipped` timeline rows (B2, timeline only, no webhook): one PATCH turning
  captions on at full captioned capacity writes one row, not up to two; a failover onto a
  self-hosted fallback refused at capacity writes a row with `why: failover` (the first
  open's has `why: capacity`) and the stream stays on its primary until a slot frees. The
  `CaptionSessionRecord::state()` docblock carries the clause.
- Health `live_capacity.caption_sessions_total` can rise from 0 after a box re-benches
  (B3); read the health value, never a cached one.
- Bucket-delivery live playlists go stale for less time after a dropped connection (B4).

## [0.9.3] - 2026-10-25

The tmesis `v0.9.3` contract, pinned to
the tmesis `v0.9.3` tag. tmesis `v0.9.3`
is a patch that changes the contract additively (C1 to C6). Eight
artifacts moved (`openapi.json`, the two `caption.session_*` schemas, `job.completed` and
`job.progress`, the `caption.session_ended` example, the origin role template and
`MD5SUMS`), nothing was added, and `test/vectors/` did not change (98 provenance rows).
Repin before a box runs `0.9.3` against a control plane with `contract.validate_incoming`
on: the bundled `0.9.2` schemas reject `sockets`/`rotations` and the `stalled` reason.

### Added

- `CaptionSessionEnded::sockets()` and `rotations()` (C2b): every provider socket opened and
  every rotation, cumulative over the stream's captioner like `reconnects()`. Absent on a
  pre-0.9.3 node they read 0.
- `CaptionSessionEnded::REASON_STALLED` (C3): audio flowing, but no answers (or late ones)
  from the provider. It resumes by itself, so it is in `RESUMABLE_REASONS`, and
  `mayResume()` and `captionsContinue()` are true for it; `caption.session_started`
  `resumed_after` may be `stalled`.
- `JobOutputReport::provider()` (C4): the batch provider that transcribed a `transcript`
  output; null on every other kind and when no provider answered.
- `HealthLiveCapacity::captionSessionsTotal()` and `captionSessionsUsed()` (C5): the
  self-hosted captioned streams the box admits and the slots held now; both null when no
  `self_hosted` provider serves live captions or the caption step was never benched
  (captions are then admitted without a limit). `tmesis:health` shows them in a new
  `captions` column (`used/total`, `-` when unknown). `FakeTmesis`'s health answers null
  for both.

### Changed

- The origin role template names `TMESIS_TRANSCRIPTION_BATCH_API_KEY` and
  `TMESIS_TRANSCRIPTION_FALLBACK_API_KEY` (C1). They now behave like
  `TMESIS_TRANSCRIPTION_API_KEY`: a config render leaves `${...}` for the node's `.env`, a
  user-data render writes the supplied value into the document. Under a `self_hosted`
  batch role (`transcription.batch_provider`, empty following `provider`) or a
  `self_hosted` fallback role the key renders empty in both modes, whatever the
  `EnrollmentSecrets` seam supplies: a key there fails the node's config load. The same
  now holds for `TMESIS_TRANSCRIPTION_API_KEY` under `provider: self_hosted`. The roles are
  read from the document's own `transcription` block, non-secret placeholders filled; a
  config embedded in a block scalar, or a role the renderer cannot read exactly, is not
  detected and the key is placed as before.
- `caption.session_started` fires once per caption session (first open, resume, switch,
  rejoin), never per provider socket (C2); a start on the fallback because the box's
  captioned capacity is full carries `switched_from` (C6). Docblocks only: do not count
  starts as sockets or expect one after each reconnect.

## [0.9.2] - 2026-10-22

The tmesis `v0.9.2` contract, pinned to
the tmesis `v0.9.2` tag. tmesis `v0.9.2`
is a patch that changes the contract (C1 to C7): live captions fail over to a second
provider. Four artifacts moved (`openapi.json`, the two `caption.session_*` schemas and the
`caption.session_started` example), nothing was added, and `test/vectors/` and the role
templates did not change (98 provenance rows). A `0.9.1` package still decodes a `0.9.2`
node; with `contract.validate_incoming` on it logs a schema error for every
`caption.session_started` and `provider_switch` close until it is upgraded.

### Added

- `HealthTranscription::ROLE_FALLBACK` (C1): the `fallback` role, listed only when the
  node sets `transcription.fallback_provider`. A fallback that is down is degraded, never
  not ready; `tmesis:health` warns and does not fail.
- `CaptionSessionStarted::switchedFrom()`, `isSwitch()` and `isFallback()` (C2): the
  provider a failover or failback left, and whether the session runs on the fallback.
  Absent on a pre-0.9.2 node they read null and false. A start can carry both
  `resumed_after` and `switched_from`.
- `CaptionSessionEnded::REASON_PROVIDER_SWITCH`, `isProviderSwitch()` and
  `captionsContinue()` (C3): `provider_switch` is a node close whose
  `detail` says why, always followed by a start with `switched_from` in the same publish
  session. `captionsContinue()` is true for it and the four resumable reasons.
- `CaptionSessionRecord::provider()` and `fallback()` (C4): the provider in use, or the
  last journaled cue's once ended; both null while idle.
- `TranscriptCue::provider()` (C6): the provider that produced the cue.
- `FakeTmesis::withStreamTranscript()` takes an optional `primary:` and every read now answers
  `provider` as the contract does: the first returned cue's, else the primary (that argument,
  else the held stream's captions provider, else the seeded body's own). A seed whose
  `provider` disagreed with its first cue used to come back unchanged.
- `TMESIS_TRANSCRIPTION_BATCH_API_KEY` (tmesis `v0.9.1`) and
  `TMESIS_TRANSCRIPTION_FALLBACK_API_KEY` (`v0.9.2`) are reserved enrolment names:
  refused as facts, supplied only by `Enrollment\EnrollmentSecrets`, and written to the
  `.env` block (no role template names them).

### Changed

- `CaptionSessionEnded::mayResume()` and `RESUMABLE_REASONS` are unchanged: they are the
  same-provider resume. `provider_switch` is not in them; read `captionsContinue()`.
- The stream's `captions.provider` (`CaptionsRecord::provider()`,
  `StreamSpec::captionsProvider()`) is the node's PRIMARY; the session record, each
  `caption.session_started` and each transcript cue name the provider actually used (C5,
  C7). `TranscriptResponse::provider()` is the first cue's, or the primary when there is
  no cue. Docblocks only.

## [0.9.1] - 2026-10-20

The tmesis `v0.9.1` contract, pinned to the tmesis `v0.9.1`
tag. tmesis `v0.9.1` is a patch that changes the contract (K1 to K8):
four artifacts moved (`openapi.json`, the two `caption.session_*` schemas and the
`caption.session_started` example), nothing was added, and `test/vectors/` and the role
templates did not change (98 provenance rows). Repin before a box runs `0.9.1`: its
`ingest.dropped_bytes_total` changes value under an unchanged name.

### Added

- `Data\Node\HealthTranscription` and `HealthFull::transcription()` (K6): one entry per
  configured transcription role, live then batch (`role()`, `provider()`, `endpoint()`,
  `state()` up / down / unknown, `since()`, `unavailable()`, `detail()`); `[]` when both
  providers are none, and when a pre-0.9.1 node sends no list. `FakeTmesis`'s default
  health carries `[]`.
- `HealthFull::DEGRADED_TRANSCRIPTION_UNAVAILABLE` (K5): a configured provider, live or
  batch, down for 30 s. Captions fail soft: `ready` stays true. `tmesis:health` prints a
  warning per unavailable role and does not fail on it.
- `CaptionSessionStarted::resumedAfter()` and `isResume()` (K7): the `caption.session_ended`
  reason a start resumes from, null on every other start.
- `CaptionSessionEnded::RESUMABLE_REASONS` and `mayResume()` (K8), held equal to the
  `resumed_after` enum.

### Changed

- `gave_up`, `rate_limited`, `provider_closed` and `unreachable` no longer end captions for
  the show (K8): captions resume by themselves in the same publish session, and a
  `degraded` caption session returns to `live` unless its reason is `auth_error` or
  `quota_exceeded`. Docblocks on the caption events, `CaptionSessionRecord` and
  `CaptionSnapshot`, and the README, say so.
- `IngestSnapshot::droppedBytesTotal()` is no longer deprecated (K4): since tmesis `v0.9.1`
  it is payload bytes dropped; until `v0.9.0` it repeated `dropped_packets_total`.
- The caption `provider` is `deepgram` or `self_hosted` (K1, K2), and
  `TranscriptOpts`'s provider must equal the node's `transcription.batch_provider` (K3).
  Docblocks only: the package never validated the provider.

## [0.9.0] - 2026-10-13

The tmesis `v0.9.0` contract, pinned to the tmesis `v0.9.0` tag: live admission control and the `over_capacity` refusal, the encoder
pins, the told jobs pause, the early disk-floor warning, and four new node-scoped
events. 21 artifacts moved or arrived (98 provenance rows). The tag's `openapi.json`
differs from the md5 first measured in two description strings only (the doctor
`rerun` parameter and the jobs `503`), and `config.edge.yml` changed a comment; every
other measured md5 reproduces. Repin BEFORE any node is upgraded: a `0.9.0` node's
webhooks carry four event names an `0.8.x` package does not type.

### Added

- `Events\NodeCapacityChanged` (`node.capacity_changed`): `reason()`
  (`REASON_BENCHMARK`, `REASON_ENCODER`, `REASON_ADMISSION`, `REASON_DEVICE`),
  `full()`, `liveCapacity()` (`Data\Webhooks\NodeLiveCapacity`), `jobsCapacity()`
  (`NodeJobsCapacity`, null when jobs are off), `videoEngine()` / `jobsVideoEngine()`
  and `previous()` (`NodeLiveCapacityPrevious`, only the changed fields). Latched with a
  30 s hold-down, so it does not flap.
- `Events\NodeDiskFloorNear` (`node.disk_floor_near`): `headroomBytes()`
  is signed and goes negative once the floor is breached (`isBreached()`); there is no
  all-clear event, read health.
- `Events\JobsPaused` and `Events\JobsResumed`, node-scoped and not
  `JobEvent`s, paired by `JobsResumed::pausedAt()` == `JobsPaused::setAt()`;
  `CAUSE_EXPIRED`, `CAUSE_CLEARED`, `CAUSE_RELEASED`.
- `NodeOnline::videoEngine()`, `jobsVideoEngine()` (`Data\Webhooks\NodeVideoEngine`,
  with `requested()`, `usable()`, `isPinned()`) and `jobsPause()` (`NodeJobsPause`);
  `NodeCapacity::admission()` and `admissionOn()`: 0 slots while admission is not `on`
  means unmeasured, never "no capacity". Admission is on for a measured box AND for a
  told `limits.max_streams` (source `configured`), so it is not "the box was benched".
- `HealthFull::liveCapacity()` (`Data\Node\HealthLiveCapacity`, required: closed
  `admission` and `source`, `encodeSlotsTotal()` NULL whenever admission is not on,
  device sessions, `measuredAt()`); health `capacity()` stays the job slots.
  `HealthFull::jobsVideoEngine()`, `requested()` / `usable()` / `pinned()` on
  `HealthVideoEngine`, the `DEGRADED_*` constants (with `encoder_unavailable` and
  `jobs_encoder_unavailable`) and `degradedBecause()`. `HealthJobs::paused()` /
  `isPaused()` (`HealthJobsPause`).
- `DoctorResponse::jobsBackend()`; `requested()` and `usable()` on `DoctorBackend`.
  `usable()` is false only for a pin that failed at boot; absent (a node before
  `v0.9.0`) reads true.
- Stats: `CapacitySnapshot::admission()`, `source()`
  (`ADMISSIONS`, `SOURCES`), `unitLadder()`, `deviceSessionsTotal()`,
  `deviceSessionsUsed()` (its `encodeSlotsTotal()` is 0 where health says null);
  `NodeStats::jobsVideoEngine()`; `VideoEngineSnapshot::requested()` / `usable()`;
  `DiskSnapshot::totalBytes()` / `nearMarginBytes()`;
  `IngestSnapshot::arrivalGapMaxSeconds()` (null before two reads);
  `RingSnapshot::unit()` / `isBytes()` with the unit-neutral `depth()`, `capacity()`
  and `droppedTotal()`. An absent `unit` (a node before `v0.9.0`) reads `bytes` on the
  `ingest` entry and `fragments` on every rung.
- The standalone jobs pause: `transcodeJobs()->setTranscodePause()`,
  `getTranscodePause()` and `clearTranscodePause()`, with
  `Data\Jobs\JobPauseRequest::make($reason, $until = null)` (the same argument order
  and checks as `DesiredJobsPause`: `until` a required nullable key, any RFC 3339
  offset with every field in range as Go's `time.Parse` reads it; `reason` 1 to 200
  bytes of valid UTF-8; refused before the request with the node's sentences) and `Data\Jobs\JobPauseResponse` (exactly
  `{paused: false}` when inactive).
- `Exceptions\EncoderUnavailableException` (a subclass of `UnavailableException`) for
  a job submit's `503 encoder_unavailable`: it does not clear by
  waiting, so send the job to another box.
- `Exceptions\FleetOwnedException` (a subclass of `ConflictException`, `ownedPaths()`)
  for `409 fleet_owned`: the pause routes on an enrolled box, and the stream and relay
  routes' `fleet_owned` from tmesis `v0.7.0`, which used to throw a plain
  `ConflictException`.
- The told jobs pause in the desired state:
  `DesiredDocument::jobsPause(DesiredJobsPause::make($reason, $until))`. `jobs_pause`
  is always sent, null when none, and absent CLEARS: set it on every document while
  the pause should hold. A pause moves the revision; a document without one hashes as
  before, so the upgrade moves no node's revision.
- The heartbeat's `jobs_pause` (`Data\Fleet\JobsPauseState`, `source` `fleet` /
  `api`), recorded on `FleetNode::$jobsPause` / `jobsPaused()` (the stop-offering rule)
  and observed as `Events\Fleet\FleetJobsPauseChanged`. A beat without it, from a node
  not yet on `0.9.0`, reads as no pause. Kept in the `stats` column: no migration.
- `StreamPublishRejected::REASON_OVER_CAPACITY` and `REASON_ENCODER_UNAVAILABLE`.
- `FakeTmesis` models the jobs pause (`fleetOwnsJobsPause()`, `pauseJobsByFleet()`,
  `assertJobsPaused()`, `assertJobsNotPaused()`, health `jobs.paused`) and
  `jobsEncoderUnavailable()`, and `withoutJobEngine()` (every transcode route, the
  pause routes included, answers `501` naming M3). It echoes `until` in UTC to the
  second, as the node does. Its default health is a valid `v0.9.0` `HealthFull`
  (`live_capacity`, `jobs_video_engine`, and the top-level `admission` it lacked).
  `FakeFleet::withJobsPause()`.
- `tmesis:health` shows live capacity, device sessions, the jobs pause and both encoder
  pins, and warns on an unusable pin; `tmesis:doctor --live` warns per node on an
  unusable live or jobs pin.

### Changed

- `stream.publish_rejected` widens to 14 reasons (`over_capacity`,
  `encoder_unavailable`): a switch on the old values must take both. Neither is rate
  limited for a proven key, so expect bursts.
- `video_engine` is null on a transcoder (health, stats, `node.online`); its encoder
  is `jobsVideoEngine()`.
- The printed bootstrap order:
  `install.sh ... --no-start`, join or register, the live bench
  (`--profile live --store --if-live`, as the service) and the job bench, then
  `systemctl start tmesis tmesis-update.path`. `tmesis:fleet:join-token` stops
  `tmesis-update.path` too, and prints DEPLOY.md 6.5's `chown -R tmesis:tmesis
  /var/lib/tmesis/bench` before the live bench (a v0.8.x job bench left it root-owned).
- The bundled role templates follow the tag: `limits.disk_floor_gb` 10 to 5 in
  `config.standalone.yml` (the package never writes the key).
- The suite sets `memory_limit` 512M in `phpunit.xml.dist`: at 2,600+ tests it outgrew
  PHP's 128M CLI default.

## [0.8.3] - 2026-10-08

A package-only PATCH on the tmesis `v0.8.2` pin (no contract change, no repin): the
four findings of the first consumer adoption review.

### Added

- `webhooks.dedupe.table.columns.delivery`, null by default: a column on the
  `table` driver's host table that the claim fills with the delivery id. Under a unique
  index of its own it is the dedupe backstop when the cache key is lost inside the
  window (`cache:clear`, an eviction): the second sight is a unique violation, answered
  as a duplicate with no dispatch and no second row, and the cache key is re-seeded with
  the existing row id. A first sight costs no lookup. Null keeps the cache as the only
  dedupe, as before. `TableDeliveryDeduper::problems()` (and so `tmesis:doctor`) checks
  the column and its unique index when set.
  `forget()` clears the id from the column, so a hand replay is new again, and a row
  whose cache key is gone is found by the indexed column before the payload query.
- `tmesis:webhooks:prune`: the scheduled caller `DeliveryDeduper::prune()`
  never had, so on the default `database` driver every processed delivery, signed body
  included, was kept forever. It deletes processed rows stored before the dedupe
  retention (already at least `fleet.tombstone_days` while the fleet is on, through
  `FleetConfig::dedupeRetentionHours()`), never an unprocessed row, prints the count and
  succeeds; the `cache` and `table` drivers report 0 and say why. Schedule it daily.
  It deletes in chunks of `DatabaseDeliveryDeduper::PRUNE_CHUNK` (1000) rows by key,
  so a first run over a long backlog never holds one long lock on the table the
  receiver inserts into.
  `tmesis:doctor` gains a `delivery retention` row on the database driver that warns
  when processed rows past retention are waiting, naming the oldest and the command;
  `DatabaseDeliveryDeduper::prunableCount()`, `oldestPrunable()` and `retentionHours()`
  back it.

### Changed

- Enabling the fleet on the `table` dedupe driver is refused at boot unless
  `webhooks.dedupe.table.columns.delivery` is set: the fleet sends every
  event over two channels, so a cache-only dedupe there is a real duplicate path.
- **The admin bearer token is never sent over cleartext**. Outside the
  `local` and `testing` environments an admin call to a url that is not `https`, to a
  host that is not loopback (`localhost`, 127.0.0.0/8, `::1`), throws the new
  `InsecureTransportException` (naming the node and the scheme and host, never the
  token) before anything is sent; it used to send the token in clear. An enrolment
  registering such a url now stores none (the node is receive-only: webhooks and hooks
  still work, admin calls throw naming the missing url), and an explicit `http`
  `api_url` is `422 validation_failed`. `tmesis:doctor --live` fails the scheme row
  where it only warned. What an operator does: give the node a DNS name in its
  `api.tls.domain`, so it serves its own ACME certificate, and point its url at
  `https://<that name>:<port>` (re-enrol, or set the url, for a node registered
  receive-only). Loopback http and the tokenless liveness probe are unaffected.

### Fixed

- **A switched-off keyterms hook no longer clears every session's keyterms**. The route was registered only under `hooks.keyterms.enabled`, so a node
  pointed at a control plane with it off got a `404`, which the node reads as "no
  keyterms" (tmesis `internal/webhook/keytermshook.go`) and applied to every session.
  `GET {prefix}/hooks/keyterms` is now always registered; with the switch off it still
  verifies the signature and answers a signed request `503 keyterms_disabled` (logged
  with the node and delivery), which keeps the node's list. The switch is read per
  request, so a test's `config()->set()` turns it on without a reboot. The enabled
  route answers exactly as before. `tmesis:doctor` passes the route when off (it used to
  warn on a registered-but-off route) and warns or fails when it is missing.
- **The database dedupe driver bounds `stored_at` in UTC** (PR #15 review).
  `stored_at` is written as the receipt's UTC wall-clock time, but the retry query
  (`unprocessed()`), `unprocessedOlderThan()` (the doctor's "never processed" count)
  and the prune window compared it with `now()` in the APP timezone, so an app not on
  UTC retried, counted and pruned at a boundary off by its UTC offset. Every bound is
  now `now('UTC')` in the column's format, and `stored_at` reads back as UTC.

## [0.8.2] - 2026-10-08

The tmesis `v0.8.0` contract (told master, operator purge, caption keyterms and the keyterms hook, value-carrying role templates, fleet `update`), released as `0.8.2`. tmesis `v0.8.1` and `v0.8.2` changed no contract artifact; there is no package `0.8.0` or `0.8.1`.

### Added

- Told master: `artifacts()->createMaster($stream, MasterRequest)` joins a show's ingest recording parts into one MP4 at one told key. `MasterRequest` mints its `request_id` as a ULID and validates sources (none, more than 64, wrong target, signed initial authorizations). `ArtifactStatus::master()` returns a `MasterInfo` (`state`, `sources`, `capture_container`, secret-free `MasterTarget`, `error`). New `ErrorMap` codes: `master_disabled` (409), `invalid_source` and `sources_incompatible` (422), `master_unavailable` (503).
- Operator purge: `artifacts()->purgeArtifact()` and `jobArtifacts()->purgeJobArtifact()` (`ReleaseRequest::purgeAbandoned()` for an abandoned or failed held generation); `DecisionStatus::purgeRequestedAt()` / `purgeReason()`, `ArtifactStatus::purgeRequestedAt()` / `isPurged()`, and `artifact_held` (409) in `ErrorMap`.
- `StreamSpec::captionsKeyterms(?array)` (a list replaces, `[]` or null clears, omitting keeps), validated by the new `Support\Keyterms` with the node's sentences; `CaptionsRecord::keyterms()`.
- `sourceGenerations()` on `RecordingStarted`, `RecordingCompleted` and `RecordingRegistered`; `purge()` / `purgeReason()` on `ArtifactDeleted`.
- Enum constants: `PROTOCOL_RTMPS` (stream events, `HookRequest`, `StreamRecord`; `FleetStreamState::PROTOCOLS` takes `rtmps`), `StreamInputLost::REASON_NODE_DRAINING`, `StreamSealed::SEALED_BY_CRASH_RECOVERED`, `RecordingCompleted::REASON_MASTER_FINISHED`, `master_pending` in `ArtifactStatus::HOLD_REASONS`.
- Fleet commands `Tmesis::fleet()->update($nodeId, $version)` (version sent bare, checked against the node's grammar) and `reloadEnv($nodeId)`.
- Keyterms hook route `GET {prefix}/hooks/keyterms` (`tmesis.hooks.keyterms`), off unless `TMESIS_KEYTERMS_HOOK_ENABLED`, signed over the timestamp and an empty body. The app implements `Hooks\KeytermsProvider` (`TMESIS_KEYTERMS_PROVIDER`), which receives `Data\Hooks\KeytermsHookRequest`. An invalid list answers `500 invalid_keyterms` (reports `InvalidKeytermsException`) and a throwing provider `503`, both keeping the node's list in force. Default provider `NoKeyterms`; `tmesis:doctor` fails an enabled route that still uses it.
- `TMESIS_KEYTERMS_HOOK_SECRET`, minted per node by `tmesis:enroll-node` (same as the webhook secret unless `--distinct-keyterms-secret`): `NodeEnrollment::keytermsHookSecret()`, `nodes.*.keyterms_hook_secret`, `nodes_table.columns.keyterms_hook_secret`, `NodeConfig::$keytermsHookSecret`, `NodeRegistry::keytermsHookSecret()`. `tmesis:doctor` checks it for whitespace.
- `TMESIS_SRT_PASSPHRASE` is a reserved name the optional `EnrollmentSecrets` seam may supply; the package never mints it.
- `ConfigRenderer::templateCarries($role, $path)` and `Enrollment\TemplateValues`, an in-place YAML value reader/writer.
- `FakeTmesis`: `createMaster`, the purges (following the node's conflict rules), `startMaster()`, `finishMaster()`, `failMaster()`, `assertMasterRequested()`, `assertPurged()`, `assertJobArtifactPurged()`, stored caption keyterms, and `keytermsHook($stream)` (`Testing\PendingKeytermsHook` with `assertKeyterms()`, `assertKeptInForce()`, `assertStatus()`).
- A `vectors-keyterms` contract leg; the reference receiver turns the keyterms hook on (`TMESIS_RECEIVER_KEYTERMS_SECRET`, `TMESIS_RECEIVER_KEYTERMS`).
- `tmesis:doctor` checks the `TableNodeResolver` table (`TableNodeResolver::problems()`): a missing table or configured column fails by name instead of answering every webhook `503 node_lookup_failed`.

### Changed

- **BREAKING for an `EnrollmentFacts` implementation:** the interface gains `values(NodeEnrollment): array`, facts keyed by config path (`webhooks.url`, `ingest.verify_hook.url`, ...) that set the plain YAML value in the template in place. `placeholders()` stays for an app's own `${NAME}` templates. A fact is never dropped silently: unknown paths, mapping paths, reserved secrets, values containing `${` and unused placeholders are refused by name. Migrate by moving each `TMESIS_*` fact to its config path in `values()` and returning `[]` from `placeholders()`.
- `FleetControlPlane` gains `update()` and `reloadEnv()` (breaking only for another implementation of the interface).
- `NodeEnrollment` takes an optional last constructor argument, the keyterms hook secret (defaults to the webhook secret). Reserved names are now eleven (`TMESIS_KEYTERMS_HOOK_SECRET` minted, `TMESIS_SRT_PASSPHRASE` supplied); the `.env` block gains the keyterms line.
- `GET {prefix}/enroll/config` serves the bundled template with the app's values and the box's name written in, secrets left for `.env`.
- Sealed replay URLs are the live ones (`playlist.m3u8`, `<rung>.m3u8`, `captions_<lang>.m3u8`); `ArtifactStatus::MASTER_NAMES` says `playlist.m3u8` for `hls_sealed`.
- `recording.master` on a stream body is now `422 unknown field` at the node; the builder never sends it.
- `armSealingDrain` (`POST /api/v1/system/drain/seal`) is local-only and not wrapped.
- `ContractDir::eventSchemaFiles()` leaves out `keyterms.hook.response`.

### Fixed

- User-data renders escape each secret the way the node's interpolator reads it (escaped in double quotes, plain or quoted as a bare scalar, refused by name in block scalars, partial scalars, or when containing `${`). Previously secrets went in raw and could be misread or break the file.
- One `EnrollmentFacts` now serves both enrolment documents: the template file decides the kind (`TemplateKind::forPath()`), so `values()` fill `config.*` documents and `placeholders()` fill `cloud-init.*` app templates.
- A supplied `TMESIS_SRT_PASSPHRASE` outside 10 to 79 bytes is refused; `--distinct-hook-secret` / `--distinct-keyterms-secret` are refused when `TableNodeResolver` has no column to store them; `FleetControlPlane::update()` refuses a version with a trailing newline.
- `SignedDestination::make()` and `JobSignedTarget::make()` refuse endpoints the node refuses (non-http(s), userinfo, query, fragment) without echoing the value, so presigned URLs no longer reach request bodies or `FakeTmesis`'s call log.
- `FakeTmesis` masters follow the node (409 `artifact_not_finalized` on purge of a queued or running master, retention deadline on completion, node default target values).
- `Support\Keyterms` folds case per rune like the node, so equivalent terms deduplicate.
- The renderer escapes every character the node's YAML parser refuses or folds in double-quoted strings and refuses invalid UTF-8 by path.
- `Enrollment\TemplateValues` treats anything it cannot read exactly (multi-line scalars, nested block sequences, unparsable flow collections, anchors, aliases, tags, second documents) as opaque and refuses to set paths at or inside it (`EnrollmentException::unreadableValue()`).
- CI checked tmesis out at the wrong tag for the `v0.8.0` pin; the tag and pin are now checked against each other.

## [0.7.1] - 2026-09-29

### Added

- `GET {prefix}/enroll/config`: a bearer bootstrap token fetches the bundled role's `config.<role>.yml` in config mode without burning the token or exposing enrolment secrets. `install` serves user-data only from a configured `cloud-init.<role>.yml`; with only bundled config templates it returns `404 no_template`. No contract change (tmesis `v0.7.0`).

## [0.7.0] - 2026-09-29

The fleet control plane for tmesis `v0.7.0`.

### Added

- Fleet control plane, off unless `TMESIS_FLEET_ENABLED` (refused at boot on the `cache` dedupe driver, without a provider, or under a contract floor): `POST {prefix}/fleet/enroll`, `POST {prefix}/fleet/heartbeat` and `DELETE {prefix}/fleet/enroll`, each on a bearer token, backed by `tmesis_fleet_*` tables (published with `tmesis-migrations`).
- Enrolment with one-time join tokens (`Tmesis::fleet()->joinToken()`, `tmesis:fleet:join-token`; only the sha256 is stored). A repeat within 15 minutes returns the same node id with a fresh fleet token; a new join token for an enrolled name supersedes the older node. `tmesis:enroll-node --fleet` renders `${TMESIS_FLEET_JOIN_TOKEN}` and `${TMESIS_FLEET_ENROLL_URL}`; `GET enroll/install` mints a token when the template names it.
- Heartbeat: reports and command acks are recorded and event ids claimed durably before the answer; full desired state is sent when the applied revision or revocation digest differs; commands are re-sent until acked (at most 100 per answer); dispatch runs after the response is sent.
- `DesiredStateProvider` and the `DesiredDocument` / `DesiredStream` / `DesiredRecording` / `DesiredRelay` builders. The package owns the revision (a keyed hash of the canonical document, re-keyed under `app.previous_keys`) and never stores the document.
- Fleet events dispatch as the same `WebhookEvent` classes, deduped across both channels (`ReceivedDelivery::$channel` says which); delivery ids are kept 14 days while the fleet is on.
- `FleetNode` status (`converged()`), `Events\Fleet\*` observations, `tmesis:fleet:check-stale`, `tmesis:fleet:prune`.
- Commands `drain()`, `kick()`, `seal()` (`grace_seconds` 0 to 300; only undelivered commands expire, per `command_ttl_seconds`).
- `Tmesis::fake()->fleet()`: a `FakeFleet` over the real receiver with a scripted node and assertions.
- `tmesis:doctor` rows for the fleet, dedupe retention and stale nodes.
- Reference receiver fleet mode (`TMESIS_RECEIVER_FLEET=1`) and a `fleet-node` acceptance group driving a real node binary (`TMESIS_BIN`).
- Admin API fields: `HealthFull::admission()` (`ADMISSION_OPEN`, `ADMISSION_DRAINING`, `ADMISSION_SHUTTING_DOWN`); on the stats integration block `admission()`, `fleetRevision()`, `lastHeartbeatAt()` / `lastHeartbeatAtRaw()`; `RelayRecord::SOURCE_FLEET`, `NodeDraining::REASON_FLEET`, `StreamPublishRejected::REASON_CLOSED`.
- A `fleet-receiver` contract leg.

### Changed

- `ConfigRenderer::reserved()` gains `TMESIS_FLEET_JOIN_TOKEN`.
- `leaveFleet` is local-only and not wrapped.

## [0.6.0] - 2026-09-27

The tmesis `v0.6.0` contract (the cutover release: hook response as contract, role templates as contract, `destination_ref`, `fallback.end_seconds`, delivery `both`, preview grants, markers, transcode jobs) plus package-only mechanics for node enrolment, typed hook patches, `http.verify`, the `WebhookEvent` interface and `tmesis:health --emit`.

### Added

- Typed hook patches: `HookDecision::allow(patch:)` takes a `StreamSpec`; `HookDecision::toRedactedArray()` and `PublishHookDecided::redactedDecision()` redact relay URLs to scheme, host and port.
- `tmesis.http.verify` (`TMESIS_HTTP_VERIFY`): `true` (default), `false` (refused outside `local` and `testing`), or a CA bundle path, applied to every admin call.
- `AlexHackney\Tmesis\Events\WebhookEvent`, an interface implemented by every typed event and unknown delivery, so `Event::listen(WebhookEvent::class, ...)` sees every delivery.
- `tmesis:health --emit` dispatches `AlexHackney\Tmesis\Health\NodeProbed` (`name`, `reachable`, `health`, `errorClass`, `errorCode`, `elapsedMs`, `probedAt`) per probed node; receive-only nodes and configuration failures are not emitted.
- Node enrolment: `tmesis:enroll-node {name} --role= --region= --plan= [--template=] [--mode=config|user-data] [--out=] [--replace] [--distinct-hook-secret]` mints admin token, webhook secret, hook secret and a one-use bootstrap token (`tmesis.enrollment.bootstrap_ttl_minutes`, default 15), stores them through the app's `Enrollment\NodeRepository`, renders the role template with the app's `EnrollmentFacts`, and shows the secrets once. `ConfigRenderer` returns a `RenderedDocument`; `NodeEnrollment` holds secrets as `SensitiveParameterValue`s. Behind `TMESIS_ENROLLMENT_ENABLED` (default off): `POST {prefix}/enroll/register` and `GET {prefix}/enroll/install`. `Exceptions\EnrollmentException`, `Testing\ArrayNodeRepository`.
- The enrolment register body takes what `tmesis version --json` reports and derives the admin URL (`NodeRegistration::deriveUrl()`); optional `doctor` and `bench` objects are recorded; `NodeRegistration::isReceiveOnly()`.
- Reserved secrets `TMESIS_TRANSCRIPTION_API_KEY`, `TMESIS_LIVE_ACCESS_KEY`, `TMESIS_LIVE_SECRET_KEY`, supplied by an optional `Enrollment\EnrollmentSecrets` seam (`tmesis.enrollment.secrets` / `TMESIS_ENROLLMENT_SECRETS`); `EnrollmentException::invalidSecret()`.
- `bin/repin` also copies the YAML role templates, their `MD5SUMS` and `test/vectors/hook.json`; `ContractDir::eventSchemaFiles()` defines which schemas are deliveries.
- The hook response is contract: `hook-endpoint` validates every `HookDecision` shape against `publish.verify.response`, a new `vectors-hook` leg reproduces the worked responses, and `PendingHook::decision()` fails on an invalid response when `opis/json-schema` is installed. `HookDecision::RESPONSE_SCHEMA`.
- `RelayDestination::aliases()` and `matches(string $id)` for destinations folded into a static relay.
- `StreamSpec::ingestDestinationRef(?string)` (`recording.ingest.destination_ref`; refused beside a non-empty `ingestDestination()`) and `IngestRecordingRecord::destinationRef()`.
- `StreamSpec::fallbackEndSeconds(?int)` and `StreamRecord::fallback()` returning the new `FallbackRecord`.
- Delivery facts on the stats body: `DeliverySnapshot::mode()` (`MODE_BUCKET`, `MODE_ORIGIN`, `MODE_BOTH`), `originServing()`, `liveDestination()`.
- `PreviewGrants` resource (`Tmesis::node()->previewGrants()`): `createPreviewGrant`, `listPreviewGrants`, `updatePreviewGrant`, `revokePreviewGrant`, with `PreviewGrantInput`, `PreviewGrantPatch`, `PreviewGrant`, `PreviewGrantListResponse`.
- `Markers` resource: `placeMarker($stream, MarkerRequest)`, `listMarkers($stream)`, with `MarkerRequest`, `MarkerRecord`, `MarkerResponse`, `MarkerListResponse`, `MarkerPlacement`; `marker_exists` maps to `ConflictException`; `Events\MarkerPlaced`.
- Transcode jobs: `Tmesis::node()->transcodeJobs()` (`createTranscodeJob`, `listTranscodeJobs`, `allTranscodeJobs`, `getTranscodeJob`, `cancelTranscodeJob`) with the `Data\Jobs\` DTOs (`Spec` with a caller-minted `idempotency_key`, `JobSource`, `JobTarget`, `JobSignedTarget`, `AudioOpts`, `ThumbnailOpts`, `MP4Opts`, `TranscriptOpts`, `JobAccepted`, `JobStatus`, `JobListPage`, `JobListItem`, `JobCancelled`, `JobError`, `JobOutputReport`, `JobProgressStatus`, `JobSourceReport`); new `ErrorMap` codes `destination_busy`, `queue_full`, `source_not_supported`, `output_not_supported`, `destination_not_supported`.
- Job artifacts: `Tmesis::node()->jobArtifacts()` with ten job-scoped routes (`listJobArtifacts`, `getJobArtifact`, `getJobArtifactInventory`, `getJobTransferPlan`, `replaceJobTransferPlan`, `listJobTransfers`, `listJobTransferParts`, `supplyJobTransferAuthorization`, `releaseJobArtifact`, `overrideJobArtifact`) and lazy `allJob*()` walkers. Six `job_*` artifact kinds (`ArtifactStatus::KIND_JOB_*`, `JOB_KINDS`, `PREFIX_KINDS`, `MASTER_NAMES`), `jobId()` / `isJobGeneration()` on status, event and identity, and `PlanTarget::jobHls()` etc.
- Five `job.*` events on a shared `Events\JobEvent`: `JobQueued`, `JobStarted`, `JobProgress`, `JobCompleted`, `JobFailed`, plus `JobProbeSummary` and `JobLandedObject`.
- `HealthJobs::slots()` and `hasFreeSlot()`.
- `FakeTmesis` ledgers for grants (`grants()`, `assertGranted()`, `assertRevoked()`), markers (`markers()`, `assertMarkerPlaced()`, `landMarker()`), and jobs (`startJob()`, `progressJob()`, `completeJob()`, `failJob()`, `cancelJob()`, `jobLanded()`, `job()`, `artifactFinalizedForJob()`, `completeJobTransfer()`, `jobArtifactIdentity()`, `assertJobSubmitted()`, `assertJobCancelled()`, `assertJobArtifactReleased()`); the fake stores and echoes `destination_ref` and `fallback`.
- README sections for enrolment, TLS on the admin API, polling node health, transcode jobs, and test recipes for hook deciders.

### Changed

- **`delivery_mode` widens to `both`** on `StreamRecord`, `stream.live` and `stream.sealed` (the box serves and the bucket receives). A receiver switching on the two old values must handle the third. `DELIVERY_MODE_BOTH`, `StreamRecord::DELIVERY_MODES`, `servesFromBucket()`, `servesFromOrigin()`.
- **`ArtifactIdentity::stream()`, `session()` and `sessionEpoch()` are nullable** (null on a job generation, which names `jobId()`); `ArtifactStatus::session()` is `?string` and `sessionEpoch()` is `?int`. Callers passing `identity()->stream()` to `artifacts()` must branch on `isJobGeneration()` and use `jobArtifacts()`. `TransferAuthorizationNeed::stream()` is nullable too; the signing seam routes job refreshes itself.
- `NodeRepository::register()` is now a compare-and-set: `register(string $bootstrapTokenSha256, NodeRegistration $registration): bool`, false when no pending enrolment holds that token (the route answers `404 unknown_token`). Implementations must do it in one conditional statement; this closes a race with `--replace`.
- `NodeRegistration::$apiUrl` is nullable (receive-only registration) and the constructor takes optional `doctor` and `bench` arrays; a register body with no `api_url` is no longer `422`.
- `tmesis.enrollment.templates` defaults to the contract's bundled `config.<role>.yml`; `ConfigRenderer::renderRole()` and `--mode` take the render mode from the caller; with no `EnrollmentFacts` the command prints the `.env` block alone.
- `NodeRegistry::names()` omits the credential-less `nodes.default` stub when a node resolver is configured.
- `tmesis:doctor` reports `http.verify`, a per-node `scheme` row under `--live` (warns on cleartext non-loopback), node enrolment state, and warns on the credential-less default node.
- `StreamSpec::ingestDestination()` refuses a non-absolute path with the node's sentence pointing at `destination_ref`.
- `PlanTarget::forKind()` takes all nine kinds; `FakeArtifactLedger::seedFinalized()` refuses `job_*` kinds.
- CI runs on `ubuntu-24.04`.

### Fixed

- README: a listener on `TmesisWebhookReceived` does not see every delivery (only unknown names); the docs now point at `WebhookEvent`.

## [0.5.0] - 2026-09-26

The tmesis `v0.5.0` contract: the destination chain (durable artifact ledger, told transfer plans, signed transfer execution, release, override and discard), with the `Artifacts` resource and signing seam. Purely additive contract.

### Added

- Node resolver seam for dynamic fleets: `AlexHackney\Tmesis\Client\NodeResolver` (`find()`, `all()`), bound in the container or named in `tmesis.nodes_resolver` (`TMESIS_NODES_RESOLVER`). Config nodes win a clash; answers are memoized per request or job; an unknown node is `401`; a throwing resolver (`NodeResolverException`) is `503 node_lookup_failed`. `TableNodeResolver` resolves nodes from an app table through `tmesis.nodes_table`; `Testing\ArrayNodeResolver` for tests.
- `Artifacts` resource (`Tmesis::node()->artifacts()`): `listArtifacts`, `getArtifact`, `getArtifactInventory`, `getTransferPlan`, `replaceTransferPlan`, `listTransfers`, `listTransferParts`, `supplyTransferAuthorization`, `releaseArtifact`, `overrideArtifact`, plus lazy `all*()` walkers (which throw `ServerException` on a repeated cursor).
- `Data/Artifacts/` DTOs and request builders (`PlanRequest`, `PlanTarget`, `OverrideRequest::discardAbandoned()`, `InitialAuthorization::make()`, `ReleaseRequest::forPlan()`); `SignedRequest` is a credential, redacted in dumps and unserializable.
- Nine `409` codes mapped to `ConflictException`.
- Six events: `ArtifactFinalized`, `ArtifactReleased`, `ArtifactDeleted`, `TransferCompleted`, `TransferFailed`, `TransferAuthorizationRequired` (on an `ArtifactEvent` base with `ArtifactIdentity`); `RecordingRegistered::generation()`.
- Signing seam in `src/Transfers/`: `TransferSigner`, `TransferAuthorizationNeed`, `TransferAuthorization`, and the queued `RefreshTransferAuthorization` listener, registered when a signer is bound or named in `transfers.signer` (`TMESIS_TRANSFER_SIGNER`, `TMESIS_TRANSFER_QUEUE`).
- `FakeTmesis` artifact ledger (`artifactFinalized()`, `artifactAbandoned()`, `completeTransfer()`, `failTransfer()`, `artifactIdentity()`, `assertTransferPlanPut()`, `assertReleased()`, `assertNotReleased()`, `assertEveryFinalizedGenerationReleased()`, `assertEveryGenerationDecided()`) and `FakeTransferSigner`.
- `tmesis:webhooks:retry {--id=*} {--older-than=60} {--limit=100} {--node=}` re-dispatches stored unprocessed deliveries under a lease (database and table drivers).
- The delivery table migration carries retry columns (`failed_attempts`, `last_error`, `failed_at`, `leased_until`).
- Third dedupe driver `table` (`Webhooks\TableDeliveryDeduper`) for apps that keep inbound webhooks in one shared table, configured via `webhooks.dedupe.table.*` (`TMESIS_DEDUPE_TABLE_CONNECTION`, `TMESIS_DEDUPE_TABLE`, `TMESIS_DEDUPE_TABLE_SOURCE`) and a `columns` map; checked by `tmesis:doctor`.
- `StoredDelivery::$timestamp`, `DeliveryDeduper::recordFailure()`, `lease()`, `unprocessed()`, `supportsRetry()`, `Webhooks\StoredDelivery`, `Webhooks\DeliveryDispatcher`, `Testing\FakeTransportAware`.
- `symfony/uid` (`^7.2|^8.0`) is now required for ULID request ids.

### Changed

- Nothing leaves a node without an explicit release, override or discard (node behaviour); registration and a completed transfer are not a release.
- **`DeliveryDeduper` gained four methods; custom implementations must add them.**
- `tmesis:doctor` points at `tmesis:webhooks:retry` for unprocessed deliveries and reports a dedupe store that refuses its config as a failed check.

### Fixed

- A webhook whose dispatch throws is no longer lost: the claim and stored body are kept, unprocessed, with the failure recorded, for `tmesis:webhooks:retry`.
- `withRequestId()` on a faked client no longer reaches the real node.
- `contract.validate_incoming` validates raw bytes, so valid empty objects (`"labels": {}`) are no longer misreported.
- Identifiers (stream id, epochs, key MAC, RFC 3339 timestamps, schema names, webhook timestamp) no longer accept a trailing newline.
- `RelayInput` and `RelayPatch` redact under `var_dump()` and `print_r()`.

## [0.4.0] - 2026-09-25

The tmesis `v0.4.0` contract: production edges and the contract-fix wave.

### Added

- Relay feed: `RelayInput::make()` and `RelayPatch::make()` take a `feed` (`auto`, `ingest`, `rung:<name>`) spelled by `Support\RelayFeed`; `RelayRecord::feed()` and `feedResolved()`; `RelayDestination::feed()` on the `relay.*` events. A feed change on a running destination replaces it.
- `audio_codec` on the probed source (`SourceSpec::audioCodec()`, `SourceSnapshot::audioCodec()`, `AUDIO_NONE`).
- `lease.fenced`: `Events\LeaseFenced` (`prefix()`, `cause()`, `isSuperseded()`, `heldEpoch()`, `observedEpoch()`, `newHolder()`, `writesStoppedAt()`, `abandonedUploads()`).
- The headers contract leg checks shared node headers per listener.

### Changed

- `include` is a comma set (`include=preview,captions` validates as a whole).
- Relay and recording event examples and derived response fixtures updated for the new fields.

### Removed

- **BREAKING: `StreamSpec::ingestDestination()` refuses `s3://`.** The node answers `422` for any URL on `recording.ingest.destination`; bucket delivery is a named storage destination plus an artifact transfer plan. A stream whose stored destination still reads `s3://` holds its finalized recordings on the box until PATCHed to a directory. Migrate `s3://` destinations to a path before upgrading the node.

## [0.3.1] - 2026-09-23

### Added

- `Node::getPublicHealth()` and `Tmesis::node()->getPublicHealth()`: the unauthenticated liveness probe, decoded into `HealthPublic`. It sends no `Authorization` header, stripping any a host app sets globally.
- Contract tooling: generated examples are copied and verified by `bin/repin`; a `schemas-requests` leg validates every request body and query parameter; `schemas-responses` walks required response headers; the release job enforces the version rule against the pinned tmesis tag.

### Changed

- The tests read the generated examples in place instead of derived payload fixtures.
- `FakeTmesis` records `getHealth` with a `token` flag (`true` for `getHealth()`, `false` for `getPublicHealth()`).
- Caption DTO docblocks clarify that `CaptionSnapshot` and `CaptionSessionRecord` are different shapes.

### Fixed

- `StreamSpec::labels([])` now sends `"labels": {}` instead of `[]` (new `StreamSpec::toRequestBody()`); `labels([])` clears the set.

## [0.3.0] - 2026-09-22

The tmesis `v0.3.0` contract (live captions). From this release the package version is the same version string as the tmesis release whose contract it carries; a package-only change takes the next PATCH on that line. The contract change is additive; `captions` is required on `StreamRecord` and `StreamStatsResponse`, though the DTOs tolerate its absence. Also pins tmesis `v0.2.0`'s fuller OpenAPI document (request bodies, parameters, security scheme and error responses on every route).

### Added

- Transcript route: `Streams::getStreamTranscript($id, ?$lang, ?$since)` returning `TranscriptResponse` (`TranscriptCue`, `TranscriptWord`, `lastSeq()`), and `Streams::getStreamTranscriptText($id, $format, ?$lang, ?$since)` for `vtt` or `srt`. Invalid formats or negative `since` throw `InvalidConfigurationException`. `Streams::INCLUDE_CAPTIONS`, `Streams::includeTokens()`, `Streams::TRANSCRIPT_TEXT_FORMATS`, `Transport::getText()`.
- `getStream($id, includeCaptions: true)` adds the provider session as `captions()->session()` (`CaptionSessionRecord`).
- `StreamRecord::captions(): CaptionsRecord` (effective settings) and `StreamStats::captions(): ?CaptionSnapshot`.
- `StreamSpec` methods `captionsEnabled()`, `captionsAdvertise()`, `captionsProvider()`, `captionsLanguages()` (one tag; these are `next_segment` changes reported in `pending`).
- Events `caption.session_started` (`CaptionSessionStarted`) and `caption.session_ended` (`CaptionSessionEnded`, with `ENDED_REASONS`), fired per socket; `EventMap` now has 21 names.
- `Data\Webhooks\CaptionPlayback` and `StreamPlayback::captions()` on `stream.live` and `stream.sealed`.
- `Data\Node\HealthPublic` for the liveness-only body of `GET /api/v1/health`.
- `FakeTmesis`: `withStreamTranscript()`, `withStreamTranscriptText()`, `includeCaptions`, and `captions.*` landing in `pending` on a publishing stream.

### Changed

- The package version is the tmesis version (see above); the README wording is updated.
- Pinned to the tmesis `v0.3.0` tag (and, folded in, the `v0.2.0` tag's OpenAPI changes).
- `StreamSpec` no longer lists `captions` among the fields it refuses.
- Contract tests resolve `oneOf` responses to the branch the package decodes and cover request components through a named-emitter table.

## [0.2.0] - 2026-09-19

Pinned to the tmesis `v0.2.0` branch's first step (rejoin model). Additive: new `input_lost` reason, `resume` start, a WHEP header and the segment grid `422`; nothing removed or renamed.

### Added

- `RecordingCompleted::REASON_INPUT_LOST`, the part a hold closed when the publisher left.

### Changed

- Recording events describe a hold as a part boundary: the rejoin opens the next part under a new `recording_id` with `started_by: resume`; `parts` is always 1.
- `relay.started` and `relay.reconnected` carry `destination.state: connecting` (`RelayDestination::STATE_CONNECTING`); `relay.reconnected` previously carried `failed`.
- The segment grid `422` is documented on the builder and writes, not mirrored client-side.
- After a stop a provisioned stream reads `provisioned` again (`StreamRecord::STATE_PROVISIONED`, `STATE_INPUT_LOST`, `STATE_SEALED`); `sealed` is reached only through `DELETE` without `retain_config`.
- Docs: every WHEP `201` carries `X-Tmesis-Stream-State: live|held`; `StreamSealed::discontinuityCount()` counts `EXT-X-DISCONTINUITY` tags; `StreamEvent::kind()` lists the `window.*` timeline kinds.
- Releasing is a merge: a push to `main` whose first CHANGELOG heading is an untagged version runs the gates, tags it and publishes the GitHub release with that section as the notes.

### Fixed

- `Streams::STATES` takes `input_lost`, so `listStreams('input_lost')` and `tmesis:streams --state=input_lost` work.
- `getStreamEvents` fixture kinds follow the node's dot-separated slugs.

## [0.1.0] - 2026-09-18

Pinned to tmesis `v0.1.0-rc7` (the tmesis `v0.1.0` tag): the ingest recording and low-delay preview blocks. Additive.

### Added

- Stream record blocks: `RecordingRecord` (`ingest(): IngestRecordingRecord` with `enabled()`, `retentionHours()`, `destination()`) and `PreviewRecord` (`enabled()`, `maxMonitors()`) on `StreamRecord::recording()` and `preview()`; `PreviewMonitorRecord` (`whepPath()`, `whepUrl()`) on `previewMonitor()`.
- `getStream($id, includePreview: true)` on `Streams`, `PendingNode` and the fake.
- Stats additions: `PreviewSnapshot` on `StreamStats::preview()`, `RecordingSnapshot::path()`, `IngestSnapshot::droppedPacketsTotal()` and `tsCcErrorsTotal()`, `SessionSnapshot::latencyMs()`, `TransportRungSnapshot::isPreviewTap()`.
- `StreamSpec`: `ingestRecordingEnabled()`, `ingestRetentionHours()`, `ingestDestination()`, `previewEnabled()`, `previewMaxMonitors()`, mirroring the node's refusals; `mentions()` takes a dotted path.
- `getStreamEvents(..., order:)` (`asc` or `desc`).
- `Data\Webhooks\PreviewPlayback` and `StreamPlayback::preview()`.
- `CONTAINER_FLV` on `RecordingCompleted` and `RecordingRegistered`.
- The routes contract leg classifies off-listener operations (the WHEP routes).
- `FakeTmesis` answers PATCH by dotted path (`applied` / `pending`) and carries standalone defaults for both blocks.

### Changed

- The five `recording.*` event classes document what the node emits (one `recording_id` per file, `parts` always 1, `container` equals the capture container, and so on).
- The durability reason for the `database` dedupe default is documented everywhere: the node is at-least-once and never reads the `recording.registered` response.
- The events route reads oldest first by default.

### Deprecated

- `IngestSnapshot::droppedBytesTotal()`: the same count as `droppedPacketsTotal()` under a misleading name; still callable because the field is still on the wire.

### Fixed

- `FakeTmesis::createdStream()`'s default record now validates against the `StreamRecord` component (`playback.master` was null).

## [0.1.0-rc6] - 2026-09-14

Pinned to tmesis `v0.1.0-rc6`. No contract artifact moved; node-side playlist naming changes reach the package only through example payloads.

### Changed

- Playback fixtures follow the new playlist naming (`<rendition>_dvr.m3u8`, `<rendition>_vod.m3u8`) and `stream.sealed` carries `sessions: 1`.

### Fixed

- The release workflow's archive check no longer hardcodes the schema count.

## [0.1.0-rc5] - 2026-09-14

The first tag: the contract core, provisioning, the publish veto and the reference receiver, pinned to tmesis `v0.1.0-rc5`. From here the package version follows the tmesis version it is proven against.

### Added

- Publish keys: `StreamKeyMinter` mints and verifies `stream.epoch.mac` keys with a rotation window over `keys.previous_secrets`; `KeyEpoch`, `SessionEpoch` and `FencingEpoch` are separate types.
- Webhook receiver `POST {prefix}/tmesis`, off until `webhooks.enabled`: raw-byte HMAC verification, a 300 second window, node-keyed secrets, dedupe on the signed `delivery`, `204` returned before dispatch (done in the route middleware's `terminate()`).
- Delivery store: `database` (durable, default) and `cache` drivers behind `DeliveryDeduper`, with a publishable migration under the `tmesis-migrations` tag.
- Typed events for all registered webhooks (19 at this tag, including `StreamInputLost` and `StreamInputReturned`), plus `HookRequest` and `HookDecision`; an unknown event dispatches the base `TmesisWebhookReceived`.
- Client with DTOs named after their OpenAPI components, multi-node support and `connect()` for a node not in config; exception hierarchy mapped on `error.code` in one place, with `NotImplementedException::milestone()` for `501`s.
- Stream provisioning: `createStream`, `updateStream`, `deleteStream`, `kickStream`, `takeoverStream`, `pushKeyRevocation`, with the immutable `StreamSpec` builder; `PatchResponse` reports `applied`, `pending` and `unchanged` separately.
- Relay provisioning: `createRelay`, `updateRelay`, `deleteRelay`, `stopRelays`, with `RelayInput` and `RelayPatch`.
- `publish.verify` endpoint `POST {webhooks.prefix}/hooks/publish`, off until `hooks.enabled`, signed with the node's hook secret (falling back to the webhook secret); a throwing decider answers the configured `hooks.on_error` posture with a `200`; `PublishHookDecided` is dispatched after the response.
- Twenty typed snapshot DTOs under `Data\Node\Stats\` and `Data\Streams\Stats\`; `StreamLive::ladderResolved()` (`LadderResolved`, `SourceSpec`, `ResolvedRung`), `StreamStats::source()`, `SessionSnapshot::heldSince()`, `EncodeSnapshot::resolvedFps()`; enum constants `StreamSealed::SEALED_BY_FALLBACK_END`, `StreamPublishRejected::REASON_ALREADY_PUBLISHING`, `StreamRecord::STATE_INPUT_LOST`.
- `contract.validate_incoming` (off by default; needs `opis/json-schema`, a dev dependency).
- `FakeTmesis` with canned reads, call assertions, the write surface, `webhook()->deliverTo()` and `FakeTmesis::hook()`.
- Commands `tmesis:health`, `tmesis:streams`, `tmesis:doctor`, `tmesis:mint-key`.
- A contract suite that runs against the bundled artifacts or a tmesis checkout (`TMESIS_CONTRACT_DIR`), with provenance enforced first; `bin/repin` (`composer repin`) automates repinning.
- A reference receiver application (Testbench Workbench) with both routes on and a file-backed delivery store.

### Changed

- **BREAKING:** `NodeStats` and `StreamStats` nested accessors return typed snapshot DTOs instead of arrays (`->disk()['available']` becomes `->disk()->statfsOk()`).
- **BREAKING:** `@provisional` markers removed; `NodeStats`, `StreamList`, `StreamStats` and `StreamEventList` are pinned to their OpenAPI components.
- `DELETE /streams/:id/relays/:rid` decodes into `RelayStopResponse` (`{stopped: 1}`).
- `tmesis:doctor` checks the hook route, the decider, the `on_error` posture and whether runtime validation can run, and derives its expected schema set from the provenance table.

### Fixed

- `FakeTmesis` applies a `StreamSpec`'s `relays` array to `listRelays()` and allocates relay ids without collisions.
- The `schemas-responses` leg can no longer skip silently.

### Security

- Relay destination URLs are redacted to scheme, host and port in the client, events and `FakeTmesis`; nothing logs or persists an unredacted one.
- A publish key is never logged; `StreamKey::fragment()` is the only loggable form.

[0.3.0]: https://github.com/alexhackney/laravel-tmesis/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/alexhackney/laravel-tmesis/compare/0.1.0...v0.2.0
[0.1.0]: https://github.com/alexhackney/laravel-tmesis/compare/v0.1.0-rc6...0.1.0
[0.1.0-rc6]: https://github.com/alexhackney/laravel-tmesis/compare/v0.1.0-rc5...v0.1.0-rc6
[0.1.0-rc5]: https://github.com/alexhackney/laravel-tmesis/releases/tag/v0.1.0-rc5
