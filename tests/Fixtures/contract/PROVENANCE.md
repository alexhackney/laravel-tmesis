# Contract fixture provenance

Every file under `tests/Fixtures/contract/` is a **verbatim byte copy** of a generated
artifact from the Tmesis node repository. The relative paths mirror that repository, so
`tests/Fixtures/contract/docs/contract/openapi.json` here is `docs/contract/openapi.json`
there.

| | |
|---|---|
| Source repository | `Hackney-Enterprises-Inc/tmesis` (private) |
| Tag | `v0.9.6` |
| Commit | `53429cb98367410c9124e409f83d673488b3f5e0` |
| Copied on | 2026-10-29 |

The header rows and the table below are written by `bin/repin` from the checkout's git
state (the Commit row is `HEAD`, the Tag row is `HEAD`'s exact tag or the `--tag` given
when the tag is cut after the repin, as rc5's was). The sha256 table is what actually
verifies the pin, not the commit, and a hash that does not reproduce this table is a
stop-and-report case, not something to absorb.

**The rc5 repin (2026-09-14).** Tmesis pulled the session ladder and the hold forward
into M1 (its final plan 11.30): two new events (`stream.input_lost`,
`stream.input_returned`), `ladder_resolved` on `stream.live`, `fallback_end` on
`stream.sealed`, `already_publishing` on `stream.publish_rejected`, `input_lost` in
every `state` enum, and `source`, `held_since` and `resolved_fps` on the stats body.
Four artifacts moved and two arrived (28 copied files, 30 rows); every change is
additive. The repin was made against the tmesis branch commit first (`b4bfe3e`, so
tmesis CI could prove the pin before tagging), and the rows above were re-run against
the `v0.1.0-rc5` tag once it existed; the bytes did not move between the two.

**The rc6 repin (2026-09-14).** Nothing moved: all 30 rows reproduce, so the repin is
the header rows and the tag strings. rc6 is node-side (the live window as a second
playlist beside the full DVR one, every playlist at the stream root with `_dvr` and
`_vod` suffixes; tmesis final plan 11.31), and reaches the contract only through the
`docs/WEBHOOKS.md` examples: `stream.live`'s `playback.renditions` now name
`<rendition>_dvr.m3u8` and `stream.sealed`'s `vod.renditions` name
`<rendition>_vod.m3u8`, and the two payload fixtures below follow their fences. The
`stream.sealed` fixture also takes the fence's `sessions: 1` (the fence changed at rc5
and the fixture had not followed; 8.9 says `1` is the only value this build emits).

**The rc7 repin (2026-09-16).** Tmesis shipped the ingest recording (final plan 11.34)
and the low-delay preview (11.36). One openapi document and six schema files moved
(`stream.live`, `stream.sealed`, and the four `recording.*` except `degraded`, on
descriptions and enums); 21 rows unchanged, nothing arrived, nothing vanished. The
openapi document: five new components (`RecordingRecord`, `IngestRecordingRecord`,
`PreviewRecord`, `PreviewMonitorRecord`, `PreviewSnapshot`; 59 in all), `recording`
and `preview` required on `StreamRecord` with `preview_monitor` optional, `preview`
required on `StreamStatsResponse`, `path` on `RecordingSnapshot`,
`dropped_packets_total` and `ts_cc_errors_total` on `IngestSnapshot` (with
`dropped_bytes_total` deprecated), `latency_ms` on `SessionSnapshot`, and four WHEP
operations carrying `x-tmesis-listener: preview` (35 operations). The schemas: a new
shared `$def` `PreviewPlayback` and `preview` required on `StreamPlayback` (39
`$defs`), and `flv` in the `container` enums of `recording.completed` and
`recording.registered`. Every change is additive. The repin was made against the
tmesis branch commit first (`f795624` on `feat-tmesis-ingest-recording`, so tmesis CI
could prove the pin before tagging, the rc5 precedent), and the rows above were re-run
against the `v0.1.0-rc7` tag (`47e3f48`, 2026-09-17) once it existed; the bytes did not
move between the two.

**The v0.2 step 1 repin (2026-09-19).** The rc7 DC findings, on the tmesis `v0.2.0`
branch (the rejoin as a part boundary, tmesis final plan 11.38; the WHEP held header,
`API.md` 9.20). Four artifacts moved (the openapi document,
`recording.completed`, `recording.started`, `recording.failed`); 24 rows unchanged,
nothing arrived, nothing vanished. The openapi document: the `whepJoin` `201` response
gains `headers` (`Location`, and `X-Tmesis-Stream-State` with the enum `live`, `held`,
which says whether frames follow the answer or wait for the publisher's rejoin), the
first `headers` object in the document; no path, operation, component or milestone
moved. `recording.completed`: `reason` gains `input_lost` as a sixth value (the part a
hold closed), `parts` is described as always 1 (a rejoin after a hold opens a new row
rather than a longer file) and carries `const: 1`, and `session` says a hold closes the
recording and the rejoin opens the next part under a new `recording_id` in the same
session. `recording.started`: descriptions only; `started_by` gives all three values a
meaning (`resume` is the part a rejoin opened after a hold), and `part`, `recording_id`
and `session` drop "a hold and a same-protocol rejoin keep writing it" for the
close-then-open model. `recording.failed`: the `stage` description widens `finalize` to
a close whose ledger write was refused (the file is complete on disk and kept); the
enum of four is unchanged. Every change is additive but for `parts`, pinned by `const`
to the 1 the emitter always sent. The pin is a branch commit with no tag (the rc5 and
rc7 precedent for the first half; the second half, the tag, waits for tmesis `v0.2.0`,
which is cut after the captions and TLS branches land). The tmesis side's brief was
written at `ae4ae33`, two commits earlier, and describes descriptions only; the `const`
arrived at `9f483a9` ("CR Fixes"), and `f8de6ff` ("CICD Fixes") touched no artifact.

**The v0.2.0 tag repin (2026-09-21).** The first repin to a tag rather than a branch
commit: `v0.2.0` is an annotated tag (the tag object is `444073e`) whose commit is
`0ca70db`, the Commit row. One artifact moved, the openapi document; 27 rows
unchanged (the 24 schemas and the three vectors are byte-identical to the step 1
pin), nothing vanished. The openapi hunks close board gap G9: every route carries its
request body and its path and query parameters (six operations gain a `requestBody`:
`pushKeyRevocation`, `createStream`, `updateStream`, `createRelay`, `updateRelay` and
`whepJoin`, whose body is the SDP offer as `application/sdp`; 25 operations gain
`parameters`: the path ids and `state`, `include`, `since`/`from`, `until`/`to`,
`limit`, `order`, `seal`, `grace_seconds`, `retain_config`, `checks`, `benchmark`,
`asset`); nine request components arrive (`CreateStreamRequest`, `StreamSpec`,
`RecordingSpec`, `IngestRecordingSpec`, `PreviewSpec`, `RelayInput`, `RelayPatch`,
`RevocationRequest`, `HealthPublic`; 68 components, was 59; paths 26 and operations
35 unchanged); `Location` on the `createStream` and `whepJoin` `201`s and
`X-Tmesis-Stream-State` on `whepJoin` are `required: true`; `GET /api/v1/health`
`200` is `oneOf` `HealthFull` | `HealthPublic` with no `$ref` at the top; the
`adminToken` bearer security scheme is declared with a top-level `security`; and the
error responses are declared inline per operation (`default` on 33 operations, `401`
on 30, `400`/`413`/`415` on the five JSON-body routes). Every change is additive.
Something ARRIVED that this table does not cover: `docs/contract/examples/`, twenty
generated delivery bodies (one per registered event) that `bin/repin` does not know
about and that are not copied yet; copying them and teaching the script and
`ProvenanceTest` the directory is the `v0.3.1` change, not this repin. The package ships
this pin as `v0.2.1` under the lockstep rule.

**The v0.3.0 tag repin (2026-09-22), live captions.** `v0.3.0` is an annotated tag
(the tag object is `d4086d9`) whose commit is `4039733`, the Commit row. From this
release the package carries THE SAME version string as the tmesis release whose
contract it holds (tmesis final plan 11.47), so it ships as `v0.3.0`, and the `v0.2.1`
the paragraph above names was never cut: that repin is folded into this one. This
table is evidence of which bytes were copied, not a separate pin. Since the receiver
job left tmesis CI on 2026-09-19 (final plan 11.42), this package's own suite is the
last place the contract runs against a second implementation. Three artifacts moved
and two arrived; 27 rows unchanged (the three vectors are byte-identical), nothing
vanished. The openapi document: one path and operation (`getStreamTranscript`, `GET
/api/v1/streams/{id}/transcript`, whose `200` is declared in three media types:
`application/json` as `TranscriptResponse`, `text/vtt` and `application/x-subrip` as
plain strings); seven components (`CaptionsSpec`, the request half, and
`CaptionsRecord`, `CaptionSessionRecord`, `CaptionSnapshot`, `TranscriptResponse`,
`TranscriptCue`, `TranscriptWord`); `captions` REQUIRED on `StreamRecord` (the
effective block, with `session` only under `include=captions`) and on
`StreamStatsResponse` (`oneOf` `CaptionSnapshot` | null); `include` gains the
`captions` token. `stream.live` and `stream.sealed`: `playback.captions` and
`vod.captions`, one nullable map of `CaptionPlayback` keyed by RFC 5646 tag.
Arrived: `caption.session_started` and `caption.session_ended`. Counts: 27 paths
(was 26), 36 operations (was 35), 75 components (was 68), 26 schema files (was 24),
42 distinct `$defs` (was 39: `CaptionPlayback`, `CaptionSessionStarted`,
`CaptionSessionEnded`), 32 rows (was 30). Every change is additive. Two artifact
facts differ from the prose: the `include` parameter is schema'd as ONE enum token
while its description and `API.md` 9.5 accept a comma-separated set, so
`preview,captions` fails the declared schema; and `CaptionSnapshot` is not the
session record renamed (a `provider`, a `request_id` where the record has a nullable
`provider_request_id`, and a non-null `reason` that the node writes as `""` while the
session is neither degraded nor ended, where the record writes null). The two new
payload fixtures are the first taken from `docs/contract/examples/`; that directory is
still not copied here.

**The examples arrive (2026-09-23, package `v0.3.1`).** No repin: the pin is
still the `v0.3.0` tag, and the 32 rows above it reproduce unchanged. `bin/repin` now
copies `docs/contract/examples/*.json` whole, the generated delivery bodies (21 events
plus `publish.verify`, 22 files; tmesis `internal/contract` writes them from the same
Go types the emitter uses and validates each against its schema), so the table gains 22
rows (54, was 32) and `ProvenanceTest` hashes them on both roots like every other
copy. They REPLACE the hand-derived `tests/Fixtures/payloads/`, which is gone: every
test that read a payload reads the example in place, the contract legs through
`ContractDir::example()` so the checkout's own examples are what a
`TMESIS_CONTRACT_DIR` run exercises. The 22 `recapture owed` payload rows below are
retired with it; the response rows stay derived. The rows were written
from a checkout one commit past the tag (`36f3a44` on `main`, artifact paths
byte-identical to `v0.3.0`), so the Tag and Commit rows were set back to the pin by
hand after the run. What the generated bodies say that the derived ones did not:
the recording events show the INGEST artifact the node emits (`artifact: ingest`,
`mpegts`, a flat `-ingest-0.ts` path), which retires decision D3's master-artifact
fences; `recording.degraded`'s `part` is `0` (the fence said `1`); `relay.started`
and `relay.reconnected` carry `destination.state: connected`, where the derived rows
had taken `connecting` as the as-built value from the step 1 DC test (reported to tmesis;
tmesis answered that the examples are WRONG and were hand-typed, the node
always sends `connecting` on both, and the generator is fixed for its next MINOR, so
the behavioural test overrides the value in memory until that repin); SRT
ports are `:6001`, the rc7 default; and timestamps are Go's `RFC3339Nano`, trailing
fractional zeros trimmed (`...:11.74Z`, `19:12:08Z`), which the schemas' `date-time`
accepts.

**The v0.6.0 repin (2026-09-27, round B, NOT YET AT THE TAG).** Built against the
tmesis `v0.6.0` branch before the tag exists, as rc5's was: `bin/repin --allow-dirty
--no-test` from the checkout at `7645374` (two commits past `b267f0d`, the commit the
handover was measured on; `b267f0d..7645374` changes exactly one contract byte range,
the create route's corrected `501` description in `openapi.json`, which is the handover's
measured file, so the artifact paths were CLEAN at the copy and the Commit row names the
bytes). All 41 rows of the handover's section 2 table
(`handover-laravel-tmesis-v0.6.0.md`) were checked with
`md5 -q` against the copies and all 41 reproduce, `openapi.json`'s
`48fcd4a356aa45d8d42c4f14843e887c` among them. 21 artifacts moved and 20 arrived (86
copied files, 88 rows, was 68): the five `job.*` events, `marker.placed` and the hook
RESPONSE (`publish.verify.response`) as schema plus example; `test/vectors/hook.json`
(hand-written upstream like `fleet.json`, read by the `vectors-hook` leg); and, new in
kind, the four role templates `docs/contract/templates/config.<role>.yml` with their
`MD5SUMS`, YAML and a checksum file rather than JSON, which `ProvenanceTest` also
verifies against each other. The moves are `job_id` and nullable identity on the six
`artifact.*`/`transfer.*` schemas and examples, `aliases` on the five `relay.*`,
`delivery_mode` widening to `both` on `stream.live`/`stream.sealed`, and a description
on `recording.failed`. The Tag row reads `untagged` on purpose (no `--tag`): it records
what HEAD carries. RE-RUN THIS AT THE `v0.6.0` TAG and re-check the handover's md5s
there; a difference means the handover is stale and tmesis re-measures it.

**The v0.9.0 repin (2026-10-13, at the tag).** `bin/repin --tag v0.9.0 --no-test` from
the checkout at `91ccf85`, the commit the annotated `v0.9.0` tag object (`731e823`,
read from `.git` after the tag was fetched) points at, so the Commit row is the tag's.
21 artifacts moved or arrived (96 copied files, 98 rows, was 90): the four new
node-scoped events (`jobs.paused`, `jobs.resumed`, `node.capacity_changed`,
`node.disk_floor_near`) as schema plus example; `node.online`,
`stream.publish_rejected` and three `fleet.*` schemas moved; the `node.online`,
`stream.sealed` and `caption.session_ended` examples moved (the last two now name the
`v0.8.0` playlist layout); `openapi.json`, `test/vectors/fleet.json`,
`config.standalone.yml`, `config.edge.yml` and `MD5SUMS` moved. Against the handover's
section 2 table (`handover-laravel-tmesis-v0.9.0.md`,
measured on the candidate tree 2026-10-12) 18 of 20 md5s reproduce. The two that do
not are `openapi.json` (`97ad9bd5ed2b50484f55cf365e7457a1` at the tag, not
`ba6fc9c7…`) and `MD5SUMS`: after the handover the doctor `rerun` parameter's and the
jobs `503`'s descriptions changed (no shape: 54 paths, 71 operations, 137 components,
the same added and changed sets), and `config.edge.yml` changed a comment (10GB to 5GB
disk floor), which the handover lists as unchanged. Reported for the program board, not
absorbed silently.

**The v0.9.1 repin (2026-10-20, at the tag).** `bin/repin --tag v0.9.1` from the
checkout at `a0081e4`, the commit the annotated `v0.9.1` tag object (`e21b447`, read
from `.git` after the tag was fetched) points at, so the Commit row is the tag's. Four
artifacts moved and none arrived (98 rows, unchanged): `openapi.json` (md5
`7f756ac60c4f738d23f1f6708bf0360c`; 54 paths, 71 operations, 138 components: K5 the
`transcription_unavailable` health reason, K6 the REQUIRED `HealthFull.transcription`
and the new `HealthTranscription`, K4 `IngestSnapshot.dropped_bytes_total` now payload
bytes, K1 to K3 provider descriptions naming `self_hosted`, K8 the `CaptionSessionRecord`
state description), `caption.session_started.schema.json` (K7 the REQUIRED nullable
`resumed_after`), `caption.session_ended.schema.json` (K8: four reasons resume) and the
`caption.session_started` example (`resumed_after: null`). `test/vectors/` and the role
templates did not move. This matches the tmesis handover's contract statement
(`handover-v0.9.1.md`: K1 to K8, four files).

**The v0.9.2 repin (2026-10-22, at the tag).** `bin/repin` from the checkout at
`66bc00d`, the commit the annotated `v0.9.2` tag object (`317470f`) points at and the
checkout's HEAD, so the Tag row came from the checkout without `--tag`. Four artifacts
moved and none arrived (98 rows, unchanged): `openapi.json` (md5
`3500e01a7a7e587fd379929135cd8642`, the handover's; 54 paths, 71 operations, 138
components, unchanged counts: C1 the `fallback` `HealthTranscription.role`, C4
`CaptionSessionRecord.provider` and `fallback`, C6 `TranscriptCue.provider`, C5 and C7
descriptions), `caption.session_started.schema.json` (C2 the REQUIRED nullable
`switched_from` and the REQUIRED `fallback`), `caption.session_ended.schema.json` (C3 the
`provider_switch` reason) and the `caption.session_started` example. `test/vectors/` and
the role templates did not move. The handover
(`handover-laravel-tmesis-v0.9.2.md`) is written from a
`v0.9.0` pin; its v0.9.1 items were the `0.9.1` repin above.

**The v0.9.3 repin (2026-10-25, at the tag).** `bin/repin` from the checkout at
`235c821`, the commit the annotated `v0.9.3` tag object (`9ec4e68`) points at and the
checkout's HEAD, so the Tag row came from the checkout without `--tag`. Eight artifacts
moved and none arrived (98 rows, unchanged): `openapi.json` (md5
`f3b048ab06f80154ead5ae1da103b227`, the handover's; 54 paths, 71 operations, 138
components, unchanged counts: C3 the `CaptionSessionRecord.state` description, C4 the job
output `provider`, C5 the REQUIRED nullable `HealthLiveCapacity.caption_sessions_total` and
`caption_sessions_used`), `caption.session_started.schema.json` (C2 one start per caption
session, C3 `stalled` on `resumed_after`, C6 the `switched_from` description),
`caption.session_ended.schema.json` (C2b the REQUIRED `sockets` and `rotations`, C3 the
`stalled` reason), `job.completed.schema.json` and `job.progress.schema.json` (C4), the
`caption.session_ended` example (`sockets`, `rotations`), and C1 the origin role template
(`config.origin.yml`, md5 `3ce7049550f901832e678588c78c8ec5`, the batch and fallback
transcription keys) with its `MD5SUMS`. `test/vectors/` did not move. This matches the
tmesis handover (`handover-laravel-tmesis-v0.9.3.md`:
C1 to C6, eight files).

**The freeze, for the record.** Tmesis froze the v1 contract on 2026-09-11 (`docs/API.md`
and `docs/WEBHOOKS.md` headers); the rc2 pin was verified byte-identical to the freeze
at tmesis `main` `79b46cd` on 2026-09-13. Inside v1 every change is additive by policy,
so a repin never removes a row: `bin/repin` refuses a checkout that has lost an artifact
the table names, because that would be a break, not a repin.

The copy is `bin/repin --tmesis <checkout>` (`composer repin` with `TMESIS_CONTRACT_DIR`
set), which copies the set below, rewrites the table and the header rows, and runs the
suite against both roots. By hand, from the tmesis checkout root with `$PKG` set to this
package, it is:

    cp docs/contract/openapi.json          "$PKG/tests/Fixtures/contract/docs/contract/"
    cp docs/contract/schemas/*.schema.json "$PKG/tests/Fixtures/contract/docs/contract/schemas/"
    cp docs/contract/examples/*.json       "$PKG/tests/Fixtures/contract/docs/contract/examples/"
    cp docs/contract/templates/*.yml docs/contract/templates/MD5SUMS \
                                           "$PKG/tests/Fixtures/contract/docs/contract/templates/"
    cp test/vectors/keys.json test/vectors/webhooks.json test/vectors/fleet.json \
       test/vectors/hook.json              "$PKG/tests/Fixtures/contract/test/vectors/"

The block above is indented rather than fenced on purpose: the only bare fence in this
file is the hash table's, so `sed -n '/^```$/,/^```$/p'` extracts exactly that block,
and so do `bin/repin`, `ProvenanceTest` and `tmesis:doctor`.

## The rule

**A client never edits a vector, a schema or an example.** These files are generated
from the Go implementation (`go test ./internal/keys -update`, `go test
./internal/webhook -update`, and `internal/contract` for the schemas and the examples)
and they are the agreement, not a description of it. A row this package cannot reproduce
is a bug in this package until proven otherwise; adjusting an expected value to get green
turns a contract into a diary.

`test/vectors/fleet.json` is hand written upstream rather than generated, and nothing in
v0.1 reads it. It is copied so the fixture tree is complete for the v0.2 `schemas-fleet`
leg.

## Verifying a copy

`tests/Contract/ProvenanceTest.php` (Pest group `provenance`) parses the fenced block
below and hashes every row that is not marked `not copied`, against whichever root
`ContractDir::root()` resolves. With `TMESIS_CONTRACT_DIR` pointed at a tmesis checkout at
a different tag it fails naming the file, which is the signal to repin rather than a flake.

The table below was written by `bin/repin` on 2026-09-27 over the bytes on disk at the
Commit row (88 since the `v0.6.0` repin paragraph above; at 2026-09-23 it was 54 rows: the 22 `docs/contract/examples/` rows arrived at `v0.3.1`, and of
the other 32, the 28 of rc2 agreed with the rc2 tag note,
`plan-v0.1.0-rc2-tag-note.md` section 2, the four that moved
plus the two that arrived are the rc5 changes, the four that moved at step 1, the
one that moved at the `v0.2.0` tag, and the three that moved plus the two that
arrived at the `v0.3.0` tag are listed above). There is no
`tmesis-contract-<version>.tar.gz` `SHA256SUMS` to compare against for an rc:
`docs/DEPLOY.md` 1.4 describes that file for releases, and the tag note section 7 records
that a release run on an rc is expected to fail on the P2 secrets. When `v0.1.0` ships
one, this table is the first place it should be checked against.

```
47cd905481294591d21ba788800a10d3fe379c27e79a0038868f4f74021ddb4b  docs/contract/examples/artifact.deleted.json
8e01ef155190c293c3ff13147fe6730f81a91f0fc6b7f5cec36cff4c48ce54c9  docs/contract/examples/artifact.finalized.json
8e43b86458dedc5fd81539cb9b6bba9f14a494c787a98b2006751845dfc662d4  docs/contract/examples/artifact.released.json
f5490e1b8d2c05817a6a47aa81c75395a64ff44258f0a671db7df39116571ed4  docs/contract/examples/caption.session_ended.json
a47bfb01ff7c998314e339cf788f73ccdf52845b3276c828d66c81ed2839c4fc  docs/contract/examples/caption.session_started.json
6532b60da23e48975e30e19ee1a887c0ce9c65cd7a3020a058edeeba5f6a11d1  docs/contract/examples/job.completed.json
8ec4d5b750f0534c6a6146d814a0365f0cd8c8d5c7aceeefbec4b6e20bdbcb7c  docs/contract/examples/job.failed.json
e7480c99b7734e7c0698085ea243dade743337c68c76f253c2d8a2885179986c  docs/contract/examples/job.progress.json
3bb26561d957f66ffa51e1e2bfb23910eb5b8501298591a001c255c5a29dc642  docs/contract/examples/job.queued.json
d3766b2bb29e7a25bba295ec3e8733a2d5a51f9dfc2ab1f59549f1e822f38fea  docs/contract/examples/job.started.json
25b8a33e25f9be70e92c82ad8f0ef712fd4cf0dcd8cfc10b9b1ec7b31708e719  docs/contract/examples/jobs.paused.json
ec9f28bdc4a48a916f59c272c723a064da50afe049ca851d9219347926a32867  docs/contract/examples/jobs.resumed.json
52278e86fec0920667bef53e6539257f78019217c571be3235c620a68c2b3e34  docs/contract/examples/lease.fenced.json
5d80891bc14607418a1c7b95ef51c70488cb1842baf0b48c270e432f293e90c8  docs/contract/examples/marker.placed.json
3c800e48b83114987fae3f326686ccbee541f9fd8770f6fe6d214e3f002897f0  docs/contract/examples/node.capacity_changed.json
29c87af51c99d48f6aef167cbd3a1d1dd3325cf25894c3811ccd0758c956a53a  docs/contract/examples/node.disk_floor_near.json
e13dc734f94143eb34a0b02661b505810006fa951bab8f0ca69aff47608432ac  docs/contract/examples/node.draining.json
a741e8f4019dd35c49d5488271f1a960f112c5fe5fad8a486d70d62dc07796ad  docs/contract/examples/node.online.json
636c156b323ece96644c5dae5f6ee94a158e390b9fcace1662e8c14ebdfb9405  docs/contract/examples/publish.verify.json
6cc69ce0ae8a3f5fa413c87831b59492bcffb99af93f9aa8585442bf89b13c3f  docs/contract/examples/publish.verify.response.json
b596e88d7b8b6cf2c6ed7c858339217dfc7a333368b1121153040af9747b1775  docs/contract/examples/recording.completed.json
8843ed70297a8148f075f5301df5788c2b87dd94c2c17e5b14bd998e56a8847d  docs/contract/examples/recording.degraded.json
1c8c6f46b53c7a602d0498a08eee31e3355d8a180486718da79a85050ca8c27a  docs/contract/examples/recording.failed.json
e4e618f6c8fd9ad5eba74a927829018327cbc205381a3afa523748e31729f65b  docs/contract/examples/recording.registered.json
c6e824db5b80649085755d74a1c4c157898f28a2392aa9aa763be71f1b21dc61  docs/contract/examples/recording.started.json
264aa885c0539418d6d83c334fd76a639621e2170abe73240ec710b914ae57b6  docs/contract/examples/relay.degraded.json
1e99162620cd265c279ba923be6ae5acab78e1dc18a3174f2fd4b6369d2b9b2d  docs/contract/examples/relay.failed.json
578422a5ca9e6fb61c7e55decb670f6cfdc9d6f6126a472010135889166f8383  docs/contract/examples/relay.reconnected.json
10d7a3c6f6fbc83bdd75766d5bb0dffe4c70cb5afbc8f7f61556def6641ffbfc  docs/contract/examples/relay.started.json
8902867b9933828d2df66e5c6e809d7dec0498466ce5fade9e1f9b6994d22019  docs/contract/examples/relay.stopped.json
be387441bdf0cc3df91f687c150f7c87736980604024411e7209ef80df996703  docs/contract/examples/stream.input_lost.json
45270c08da7d88343cdbb60aa9c813b52fa1fc0f04f6dbaf5b7f1308ded076f6  docs/contract/examples/stream.input_returned.json
270fd087bbbc4d6fe3f9536f5b2ff7d8361ad4ebc37a0dea7e77a9ea90c8a31d  docs/contract/examples/stream.live.json
c6548a43a9fb0b729620e694ea00cf91de4d4732b3cabdf8380c7569e8783339  docs/contract/examples/stream.publish_rejected.json
f935da1087b48ae57ad64e0a068250ec859fc973a85b1401c631fe1ef7e70161  docs/contract/examples/stream.published.json
a16887e2a049da4294da89029cd586ee0bc50f9d7661a3a443f90a87badf244e  docs/contract/examples/stream.sealed.json
7c2838351e79d037804fc14fd5f707ebeb0d1080fc79ae9116338e609c489696  docs/contract/examples/stream.unpublished.json
628ab73de900be0f26212068256437a2d46f414b566fb0a464ed5fb6d6fbacea  docs/contract/examples/transfer.authorization_required.json
68975799158bab647951cae5714833a0092ffdde291bc3a222c23516201060f9  docs/contract/examples/transfer.completed.json
92ccde59d829a33ffd11b6a8e6578460ca1d89a1af68e7b17016045c85c87607  docs/contract/examples/transfer.failed.json
98c0a8943380ba7b37c93b3c07a9f0abb5f8eba01bab3d51bccb0dbfe2094af6  docs/contract/openapi.json
53b710b126626b91f97828ff64d3e2f21c703f5ce6db10eb46373765d8bf5a35  docs/contract/schemas/artifact.deleted.schema.json
e8054460eb686ee828da5f736db8f7930564950520fb3169cd589d6a8cd60299  docs/contract/schemas/artifact.finalized.schema.json
19a77152973c15080381679e970a6838b3171e1de2d1f06f97e668948c2a30b7  docs/contract/schemas/artifact.released.schema.json
5e01f87bdc32b992ae5eb0b47fdcfaa372aba27d3d2dbfee81e327ec87dc1a97  docs/contract/schemas/caption.session_ended.schema.json
1ad5a1df995540f8935819b23c85b16f2e278a1cfb2307de57aad5ca6f4397a3  docs/contract/schemas/caption.session_started.schema.json
70826800c1e16e1e2af6c7fb3fcc0549c975347810dc1345a265368c9a1a4cb5  docs/contract/schemas/fleet.enroll.request.schema.json
ff5e116e21684c3e2500fd729bc67a750f0893e812340ee56e1611b0dd1a4b84  docs/contract/schemas/fleet.enroll.response.schema.json
b15646100eb3b4730d7921e7b43333edfba96add07f8542f71a6247a2ee35548  docs/contract/schemas/fleet.heartbeat.request.schema.json
d4ac3f594a7dd98d23f1d3de4f5ef17df7f2c90c14c668b515abafcacb64f049  docs/contract/schemas/fleet.heartbeat.response.schema.json
9135cccac67d35414b222ea4f854e8034a70966523207b7a058bb8aa82c1cfb6  docs/contract/schemas/job.completed.schema.json
390ef09a9398c032662ffafde95138504d3be0d69bfef9610b62feea36f31dea  docs/contract/schemas/job.failed.schema.json
93878d9dcf36ba8741ff5aaa5ec1be759e3f045bce77485ed015876fb47473a3  docs/contract/schemas/job.progress.schema.json
424924c7d4517d4a0f0bf9b4ef42cc3c4424f77c0d2bff2b45c7b51c45e49ff9  docs/contract/schemas/job.queued.schema.json
0b2dcf424d4978cbfaa3739f2eebf011b6b3cc946b10614054163500c073dba2  docs/contract/schemas/job.started.schema.json
9d1293565d5e46f05ba119149ef743b6d3222fa733ded1672aca2ae4c567f64c  docs/contract/schemas/jobs.paused.schema.json
a7479c197139faaf7656bca7371336e618c18c3555938905275fc331504fdc65  docs/contract/schemas/jobs.resumed.schema.json
2d5a3848cc2e10617087ce99187c0057e8d76d8de381496e2ebd966e1bb8169f  docs/contract/schemas/keyterms.hook.response.schema.json
dcea52ff1a3dfbf2650776f729eb99ba1877ff569c812395e2051bf6fbe07c14  docs/contract/schemas/lease.fenced.schema.json
ca33094b30972a4439c63966269cb4b2fd48c2907001916776c8b99f68e8e799  docs/contract/schemas/marker.placed.schema.json
ec894d5810c59aabd8ab26220f8ed90afaacc036078b798f41c382a0b9ca775a  docs/contract/schemas/node.capacity_changed.schema.json
04e2d3db44172dafa88ea95478d3d4692eec878cea16d24ce913ecd87018803e  docs/contract/schemas/node.disk_floor_near.schema.json
6213c2b8a9beb064b2cf268b92dd3125217b056412fcde3fa244a55430084b8b  docs/contract/schemas/node.draining.schema.json
757ebcdb8eee5e86e11c93dbe85167df8439a6b3500a2bd2b8f595b04f34e365  docs/contract/schemas/node.online.schema.json
fa20c17da53279a7f3b9c91e5598b632f3230d4ce3246b25a41f6ddd97796418  docs/contract/schemas/publish.verify.response.schema.json
49ccb8420da479c1a180b7f59c197bb47d266cb74a352343e5f410899772df65  docs/contract/schemas/publish.verify.schema.json
f2d7815c40ec0aff75d8e38914703100ccda364f70653ed846b37c0ef02a3d8f  docs/contract/schemas/recording.completed.schema.json
221f34c9b464f44f519206eb3aeb1ddf1f507d8c1409677b9f01f264a34aa6e5  docs/contract/schemas/recording.degraded.schema.json
6f1736fdfa45199c486cd1b91615983a00be6bfc9dc41d0d311c24f9aa1f5157  docs/contract/schemas/recording.failed.schema.json
0a593fb356ac62e2eeb26c3669af32bddc907912a4416947db9dbad9a6533e07  docs/contract/schemas/recording.registered.schema.json
a4518d29e16112b256674c247fcafbaf7688512413f1c8ee611f4799c646f1c6  docs/contract/schemas/recording.started.schema.json
62524ea3ced64e68d4f7b8c2a71fa73ba032feb7c6156284ec1f9f222a19d961  docs/contract/schemas/relay.degraded.schema.json
c66dc24757e33794fdae4492705ced57992c724ede7807c07637a25909300dbf  docs/contract/schemas/relay.failed.schema.json
d851dd5377f391d33862b96e8db23fc11ebc774c8c311e55a6c449c20dc852b9  docs/contract/schemas/relay.reconnected.schema.json
e6985dd6fa21e0de56528b81cf5481aadec6d61bc48f069ae6868e6a9de6d7f5  docs/contract/schemas/relay.started.schema.json
d02f48617538361eb2ab121a2fd52ecdde7266a1a4e142518f19de8491c4ab31  docs/contract/schemas/relay.stopped.schema.json
dbe03b93fcd7662ba2ea24e7c79e96245286e295dfe41464aed16ecc18e9357c  docs/contract/schemas/stream.input_lost.schema.json
f0c554afb6f2fbc25c80a035b56af45b6af37e054c3a04675b0033cc6bc537c5  docs/contract/schemas/stream.input_returned.schema.json
6e0250dfa07bf84e6dee52fc45c2d700d243d97789b1b6e824957d5f4f59b569  docs/contract/schemas/stream.live.schema.json
2518f904cdccaca50abb3f0785f6292df9eea113ebf8a95626258b4cf316700c  docs/contract/schemas/stream.publish_rejected.schema.json
7aeae8b86041e6caa6546a771622270797fcd6b35f8d40570992e435768c0fc2  docs/contract/schemas/stream.published.schema.json
34e5ca3b12fb86f89e38bac155ca55af600b8976ecdcd34a52e29f68453cefa4  docs/contract/schemas/stream.sealed.schema.json
34356f6f67ae2c54fa3f9be2384b4cda9a3ec6278f81801bd6e8688d0ab3e00e  docs/contract/schemas/stream.unpublished.schema.json
4e0efd14eb423bdc7852f66674449209862ea935d27317474cd23f64690af88b  docs/contract/schemas/transfer.authorization_required.schema.json
203e563bbd40c33302893501fba11e0590fc73d8719385f0698350701aa33051  docs/contract/schemas/transfer.completed.schema.json
bd963a22200edab64af3e351613373264e7ce7fc85b6814e996b42594674fbf4  docs/contract/schemas/transfer.failed.schema.json
a4faafc2a3823d71d02c20142ccfe6be49d6337d147bd4369c172a9547826210  docs/contract/templates/MD5SUMS
03db3a73872f459cdbf747e2bd5560f6cb45618cd61b0a290d837448748ca107  docs/contract/templates/config.edge.yml
ebcf8da218cf9ec599da81421ec995e161fda7b955e76b2df38301da390212d7  docs/contract/templates/config.origin.yml
c1fd5fe5e1eecadce005b006d8c72e7c0579189bef69ecc86edde60586565a18  docs/contract/templates/config.standalone.yml
ca1d6fab4cd65b16d7e84153dbd32e4fc45aaf29f1bad93db51c10c601819fb3  docs/contract/templates/config.transcoder.yml
6908fff7fed6c6cb41680c0f8853b146e7b6e18d8fbdddf31782c3d07ef77081  test/vectors/fleet.json
b0892920fd535c6a1440366481f4cf37cff651880a92f130bafca9fb6a6da608  test/vectors/hook.json
2e0f2a959225229a3b19433b406dc08d64d5e5542ec46fc6c63187e2d44a1dd7  test/vectors/init-720p.mp4
4388874ecaa33afc71fac68db72bb74532c3be15bd34f0bfa1bff29063af82b1  test/vectors/init-audio.mp4
68e3598766ba458253d2449f2e946f61298ca52939361e689ea68b750588a957  test/vectors/keys.json
0a5051599c9b7814d17d5f71278d9921ec1e4f0eaf5b92c55b4de12640c19ea6  test/vectors/keyterms.json
b64ee8bd71068f39913c561cc6777084989d681a312a896189158aa3b49d47bd  test/vectors/webhooks.json
```

Not copied (packager golden inputs, not contract artifacts; their hashes are recorded
above so this table is the whole set):

- `test/vectors/init-720p.mp4` not copied
- `test/vectors/init-audio.mp4` not copied

## Derived fixtures

Only the response bodies are left. The webhook and hook payloads were derived
here until `v0.3.1`; they are the generated `docs/contract/examples/` above now, and
`tests/Fixtures/payloads/` no longer exists.

These are **not** copies of the contract. They are test inputs this package authored from
the sources named below, and every one of them validates against the generated artifact,
which always wins.

**What they are for.** Each is validated against its schema or component, decoded into its
typed class, round-tripped through `toArray()` into a body that validates again, and then
has every accessor called on it, nested blocks included, so a typo in a key name fails on a
payload that is otherwise correct. Catching drift in `docs/API.md` and `docs/WEBHOOKS.md`
is NOT their job: `internal/contract/docexamples_test.go` validates all 61 fenced examples
upstream on every tmesis run and fails an unmarked fence, so a human writing fixtures in
another repository is no longer the thing that finds a stale example.

**Capture replaces derivation, and a derived row says so.** A captured fixture records the
tmesis tag, the node config and the exact publish command that produced it, so the next
repin reproduces it rather than guesses at it. A row that is still derived carries
**`recapture owed`**, which is the phrase a repin round greps for. A derived fixture is not
a provisional DTO: the shape is pinned by an artifact, only the values are invented.

**Capture was attempted at v0.2 and was not possible on that host: no Docker, so
`make -f build/Makefile dev-up` could not run. Since rc7 the procedure no longer needs
Docker** (decided not this round, 2026-09-16; the rc7 plan's W12 is the procedure for
the round that does it): the tmesis branch's chaos smoke scripts need only ffmpeg, a
shell and a built node.

    # in the tmesis checkout, at the commit this package is pinned to
    KEEP=1 test/chaos/smoke-preview.sh     # GET /streams/{id}?include=preview, the stats preview block
    KEEP=1 test/chaos/smoke-ingest.sh      # the plain record and stats, with the _preview_* transport rows

`KEEP=1` keeps the work directory and prints its path; copy each response verbatim into
`tests/Fixtures/responses/`, re-run the contract legs (a captured body that fails a
schema is a tmesis defect to report, not a fixture to edit), and flip the row from
`recapture owed` to `captured <date> at <sha> by <script>`. A capture of an idle
stream is two empty arrays and proves nothing about the shapes that matter, so
`transport[]` and `packager[]` must be non-empty in the captured body. Payloads are no
longer captured here at all: the generated examples are the node's own bodies.

| Path | Derived from | Date | Note |
|---|---|---|---|
| `tests/Fixtures/responses/getHealth.json` | `docs/API.md` 9.1 (the full example) | 2026-09-07 | `v0.9.1` added the REQUIRED `transcription` as one derived entry (live, `deepgram`, `up`, `unavailable` false, `detail` null), 2026-10-20; `v0.9.3` added the REQUIRED nullable `live_capacity.caption_sessions_total` and `caption_sessions_used` as derived nulls (the fixture's live provider is `deepgram`, so no self-hosted captioned ceiling), 2026-10-25; recapture owed |
| `tests/Fixtures/responses/getDoctor.json` | `docs/API.md` 9.2 | 2026-09-08 | **verbatim** from the fence at `API.md:1006`, marked `schema: DoctorResponse`. The trims this row used to record are upstream now (handoff rows D1 to D4); recapture owed |
| `tests/Fixtures/responses/getStream.json` | `docs/API.md` 9.5 | 2026-09-22 | 9.5 is the flat StreamRecord at this tag; rc7 added the `recording` and `preview` blocks by hand, values consistent with `createStream.json` (`recording.ingest` enabled, 72 hours, `s3://tsp-archive/ingest/`; `preview` enabled, 50); `v0.3.0` added the required `captions` block as the 9.5 fence at `API.md:1893` shows it (enabled, deepgram, `["en"]`). The plain read, so no `preview_monitor` and no `captions.session`: `ClientTest` builds the `include` bodies inline; `v0.8.0` added the required `captions.keyterms` (four terms of a fight card, as the node stores them: trimmed, no duplicates); recapture owed |
| `tests/Fixtures/responses/listStreams.json` | `docs/API.md` 9.3 | 2026-09-22 | rows extended to full StreamRecords; rc7 added the `recording` and `preview` blocks to both rows by hand, values as `getStream.json`; `v0.3.0` added the required `captions` block to both rows as the 9.3 fence shows it (off, deepgram, `["en"]`); `v0.8.0` added the required `captions.keyterms` to both rows, `[]`; recapture owed |
| `tests/Fixtures/responses/listRelays.json` | `docs/API.md` 9.9c | 2026-09-07 | wrapped in the RelayListResponse envelope; recapture owed |
| `tests/Fixtures/responses/getRelay.json` | `docs/API.md` 9.9c | 2026-09-07 | unchanged; recapture owed |
| `tests/Fixtures/responses/getNodeStats.json` | component `NodeSnapshot` | 2026-09-08 | component-derived: every property of all 7 sub-components present, values consistent with `getHealth.json` and `getStream.json`. `docs/API.md` has no `GET /stats` subsection to derive from (finding N7). Capacity is `measured: false` with zeroed counts and `pending_milestone: M3`, which is what the node reports before the M3 benchmark; **recapture owed** |
| `tests/Fixtures/responses/getStreamStats.json` | component `StreamStatsResponse` | 2026-09-16 | component-derived: every property of all 15 sub-components present (rc5 added `source`, a probed 1080p59.94 h264 block consistent with `encode[].fps`, `session.held_since` null because the publisher is attached, and `encode[].resolved_fps`; rc7 added `preview` in the OBJECT form so the class is exercised, 1 monitor of 50 and frame counts in step with `uptime_seconds`, `recording[0].path` as a flat `-ingest-0.ts` file, `ingest.dropped_packets_total` and `dropped_bytes_total` both 0 (they were the same count until tmesis `v0.9.1`; since then the second is payload bytes, K4, which `IngestSnapshotTest` exercises with distinct values), `ingest.ts_cc_errors_total` 0, and `session.latency_ms` 120; `v0.3.0` added the required `captions` in the OBJECT form so `CaptionSnapshot` is exercised, values from the `API.md` 9.5 `include=captions` fragment (live, 412 finals, 1 reconnect) and `reason` the empty string the node writes while a session is neither degraded nor ended, `internal/stats/stream.go`). `transport[]` and `packager[]` carry two rungs each and `ring[]` carries a drop breakdown, so the live shapes are exercised rather than declared empty; no `_preview_*` transport rows (a derived tap row would be a guess at its counters; the capture adds them); `docs/API.md` has no `GET /streams/{id}/stats` subsection to derive from (finding N7); **recapture owed** |
| `tests/Fixtures/responses/getStreamTranscript.json` | `docs/API.md` 9.5b | 2026-09-22 | **verbatim** from the fence at `API.md:2133`, marked `schema: TranscriptResponse`: two cues, the second with a cue-level `speaker`, every word's `speaker` present (null on the first cue); re-checked verbatim at `v0.9.2` (fence at `API.md:2474`), which added the REQUIRED per-cue `provider` (C6). The `format=json` body only; `vtt` and `srt` are opaque strings the document declares with no schema; recapture owed |
| `tests/Fixtures/responses/getStreamEvents.json` | Go type `node.eventListResponse` | 2026-09-19 | derived before `EventListResponse` existed as a component; it validates against it unchanged at `v0.1.0-rc2`. The two `kind` values follow the node's dot-separated slugs since the v0.2 step 1 review (`publish.accepted`, `internal/journal/event.go`; `packager.started`, `internal/node/publish.go`; the earlier `publish_accepted` and `stream_live` were never kinds the node writes), with the Go's message shapes; `details` stay derived (row 2's null exercises the nullable path; the node's `packager.started` row carries an object); recapture owed |
| `tests/Fixtures/responses/createStream.json` | `docs/API.md` 9.4 | 2026-09-22 | from the fence marked `schema: StreamRecord`; rc7 added the `recording` and `preview` blocks, `v0.3.0` the required `captions` block as the fence at `API.md:1689` shows it (off, deepgram, `["en"]`); `v0.8.0` added the required `captions.keyterms`, `[]`; recapture owed |
| `tests/Fixtures/responses/updateStream.json` | `docs/API.md` 9.6 | 2026-09-08 | verbatim from the fence at `API.md:1869`, marked `schema: PatchResponse`. The second of the two examples on purpose: it is the one carrying a `pending` change, which is the distinction a consumer has to get right; recapture owed |
| `tests/Fixtures/responses/deleteStream.json` | `docs/API.md` 9.7 | 2026-09-08 | verbatim from the fence at `API.md:1987`, marked `schema: SealResponse`; recapture owed |
| `tests/Fixtures/responses/kickStream.json` | `docs/API.md` 9.9a | 2026-09-08 | verbatim from the fence at `API.md:2173`, marked `schema: SessionActionResponse`; recapture owed |
| `tests/Fixtures/responses/takeoverStream.json` | component `SessionActionResponse` | 2026-09-08 | component-derived: the SAME shape with a null `session`, which is the nobody-was-publishing success the doc example does not show; recapture owed |
| `tests/Fixtures/responses/pushKeyRevocation.json` | component `RevocationResponse` | 2026-09-08 | component-derived: the component is one integer and `docs/API.md` 9.9b has no marked example; recapture owed |
| `tests/Fixtures/responses/createRelay.json` | `docs/API.md` 9.9c | 2026-09-08 | the fence at `API.md:2335`, marked `schema: RelayRecord`, with `source` changed from `static` to `api`: that fence is one generic example for the whole relay section, and 9.9c's own table says `api` is the source of a destination "pushed at this route" while a `static` one is the config file's and answers `409 relay_immutable` to the DELETE this fixture's operation makes possible; recapture owed |
| `tests/Fixtures/responses/updateRelay.json` | component `RelayRecord` | 2026-09-08 | component-derived from the same example, parked and repointed, so the PATCH fixture is not a copy of the POST one; recapture owed |
| `tests/Fixtures/responses/deleteRelay.json` | component `RelayStopResponse` | 2026-09-08 | component-derived. DELETE answers `{stopped: 1}` and NOT the record removed: the openapi document and `internal/api/relays.go` agree, and the v0.2 build plan's table said RelayRecord. The artifact wins; recapture owed |
| `tests/Fixtures/responses/stopRelays.json` | component `RelayStopResponse` | 2026-09-08 | component-derived; recapture owed |
| `tests/Fixtures/responses/getArtifact.json` | `docs/API.md` 12a.2 | 2026-09-26 | the fence marked `schema: ArtifactStatus` (a finalized, registered `recording_ingest` generation on plan revision 1, held on `retention_not_elapsed` and `release_required`), with the placeholder hash replaced by 64 hex digits (the `transfer.authorization_required` example's); `v0.8.0` added the required `master` (null: not a `recording_master`) and `purge_requested_at` (null); recapture owed |
| `tests/Fixtures/responses/listArtifacts.json` | component `ArtifactPage` | 2026-09-26 | component-derived: `getArtifact.json` plus a HELD `hls_sealed` generation with every nullable field null (inventory, finalized_at, registered_at, retention_expires_at, plan_revision, recording_id), and a non-null opaque `next_cursor`; `v0.8.0` added the required `master` and `purge_requested_at` to both items, null; recapture owed |
| `tests/Fixtures/responses/getArtifactInventory.json` | component `InventoryPage` | 2026-09-26 | component-derived: the one `recording.ts` entry of that generation, `publish_order` 0, last page; recapture owed |
| `tests/Fixtures/responses/getTransferPlan.json` | `docs/API.md` 12a.3 | 2026-09-26 | the `schema: PlanStatus` fence with its empty `targets` filled: a configured `csn-vod` target (complete) and a signed `backup` target (waiting authorization), recording keys, counts summing both; recapture owed |
| `tests/Fixtures/responses/replaceTransferPlan.json` | `docs/API.md` 12a.3 | 2026-09-26 | the same body as `getTransferPlan.json`: the PUT answers the revision it created; the 201-versus-200 fact is the status, held by the headers leg and `ArtifactsTest`; recapture owed |
| `tests/Fixtures/responses/listTransfers.json` | `docs/API.md` 12a.4 | 2026-09-26 | the `schema: ObjectStatus` fence (waiting on `parts` and `abort`, `authorization_missing`) plus a complete object at the configured target with every provider receipt field set; recapture owed |
| `tests/Fixtures/responses/listTransferParts.json` | component `PartPage` | 2026-09-26 | component-derived: part 100 complete, part 101 waiting authorization with its safe error, offsets from the 16 MiB part size; recapture owed |
| `tests/Fixtures/responses/supplyTransferAuthorization.json` | component `ObjectStatus` | 2026-09-26 | the `listTransfers.json` waiting object after an accepted refresh: `uploading`, authorization revision 3, `authorization_available` true, nothing required, no error. No signed URL or header, which the node never echoes; recapture owed |
| `tests/Fixtures/responses/releaseArtifact.json` | `docs/API.md` 12a.5 | 2026-09-26 | verbatim from the fence marked `schema: DecisionStatus`, with the generation id of `getArtifact.json`; `v0.8.0` added the required `purge_requested_at` and `purge_reason`, null; recapture owed |
| `tests/Fixtures/responses/overrideArtifact.json` | component `DecisionStatus` | 2026-09-26 | the 12a.5 `OverrideRequest` fence's decision: `override`, the signed target waived, `release_state` `overridden`; `v0.8.0` added the required `purge_requested_at` and `purge_reason`, null; recapture owed |
| `tests/Fixtures/responses/createMaster.json` | component `ArtifactStatus` with `MasterInfo` | 2026-10-06 | component-derived from `docs/API.md` 12a.8 (tmesis `v0.8.0`), which has no marked fence: the `202` body, a HELD `recording_master` generation (every finalization field null, `not_finalized` and `release_required`) whose `master` is `queued` over two `recording_ingest` sources in join order (the first is `getArtifact.json`'s), `capture_container` `mpegts`, a configured `tsp-media` target resolved secret-free with its told `key`, and `error` null; recapture owed |
| `tests/Fixtures/responses/purgeArtifact.json` | component `DecisionStatus` | 2026-10-06 | component-derived from `docs/API.md` 12a.5b (tmesis `v0.8.0`): `releaseArtifact.json` as the purge records it, a release of the current revision with `purge_requested_at` and `purge_reason` set (the reason the request carried); recapture owed |
| `tests/Fixtures/responses/purgeJobArtifact.json` | component `DecisionStatus` | 2026-10-06 | component-derived: `releaseJobArtifact.json` as a purge of its revision 1 records it, `purge_requested_at` and `purge_reason` set; the four job artifact fixtures (`listJobArtifacts`, `getJobArtifact`, `releaseJobArtifact`, `overrideJobArtifact`) gained the same required `v0.8.0` fields as their stream twins; recapture owed |
