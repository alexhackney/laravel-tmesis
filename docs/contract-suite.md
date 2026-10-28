# The contract suite

`tests/Fixtures/contract/` holds verbatim byte copies of the node's generated
artifacts, mirroring that repository's paths, with every sha256 recorded in
`PROVENANCE.md`. **A client never edits a vector, a schema or an example.** A row this
package cannot reproduce is a bug here, not a reason to adjust an expected value.

The webhook and hook payloads the tests read are the node's own generated delivery
bodies (`docs/contract/examples/`, since `v0.3.1`), read in place from the contract
root rather than copied into a second fixture directory, so a `TMESIS_CONTRACT_DIR`
run exercises the checkout's examples. Only the response bodies under
`tests/Fixtures/responses/` are still derived (`recapture owed` in `PROVENANCE.md`).

The suite runs against two roots, and both should be green with the same skip list:

**Pest 4 needs one `--group=` flag per group.** A comma separated list matches
nothing and the run passes having tested nothing.

```sh
# the bundled copies
vendor/bin/pest --group=provenance --group=vectors-keys --group=vectors-webhooks \
                --group=vectors-hook --group=vectors-keyterms --group=schemas-events \
                --group=schemas-fleet --group=fleet-receiver --group=hook-endpoint \
                --group=schemas-responses --group=schemas-requests --group=routes

# a tmesis checkout
TMESIS_CONTRACT_DIR=/path/to/tmesis vendor/bin/pest --group=provenance --group=vectors-keys \
    --group=vectors-webhooks --group=vectors-hook --group=vectors-keyterms --group=schemas-events \
    --group=schemas-fleet --group=fleet-receiver --group=hook-endpoint --group=schemas-responses \
    --group=schemas-requests --group=routes
```

CI (`.github/workflows/tests.yml`) runs the bundled copies only, one `pest` invocation per
group. The tmesis-checkout run is for the maintainer's machine, after a repin or before
a release.

`TMESIS_CONTRACT_DIR` is read with `getenv()` and has no `phpunit.xml` entry on
purpose, so the process environment always wins. The name is coordinated with the
tmesis repository. Do not rename it.

| Group | What it proves |
|---|---|
| `provenance` | The resolved root's 96 copied files (`openapi.json`, 45 schemas, 40 generated examples, five vectors, the four role templates and `MD5SUMS`) hash to the `PROVENANCE.md` table, which has 98 rows (the two golden inputs are hashed, never copied), the root holds exactly one example per event schema plus the publish hook's request and response (no fleet schema and not `keyterms.hook.response`, which has none), every example in it has a row, and the templates verify against their `MD5SUMS`. |
| `vectors-keys` | Every key vector row mints byte-equal; every negative row is refused with the stated class. |
| `vectors-webhooks` | Every signature vector row signs, verifies, and is accepted by the real route; every negative row is rejected and never produced. |
| `vectors-hook` | Every worked hook response in `test/vectors/hook.json` validates against `publish.verify.response.schema.json`, and `HookDecision` reproduces each body byte for byte (key order aside): the allow with labels and a relay through `allow(patch: StreamSpec)`, the bare allow, and the deny with a reason. |
| `vectors-keyterms` | Every worked keyterms hook response in `test/vectors/keyterms.json` (tmesis v0.8.0: a fight card, `200 []`, `204`, `404`) validates against `keyterms.hook.response.schema.json` where it has a body, reads to its list in force the way the node reads it, and is reproduced through the package's own keyterms route with an answer that validates; a provider that throws or breaks a rule never produces an answer the node would apply. |
| `schemas-events` | All 38 generated event examples validate, round-trip, and map to the right class, with every accessor called. |
| `schemas-fleet` | The fourteen documents of the worked fleet exchange (tmesis v0.7.0) validate against the schema each names; the node-sent ones parse through `EnrollRequest` and `HeartbeatRequest`; every `revocations_digest` in them is reproduced by `RevocationsDigest`; `DesiredDocument` renders the vector's revision 43 byte for byte and refuses to build revision 44's `s3://` destination; `DesiredRecording` always renders both `destination` and `destination_ref`, and each form (neither, only the ref, only the directory) validates in both answers (D16); mutated command kinds, dropped required fields, an invented field and values under the v0.7.0 minimums are refused. |
| `fleet-receiver` | The same vector driven through the package's own fleet routes: the enrollment and its repeat (one node id, a fresh token, the first token dead), then 42 to 43, the digest drift resent at the same revision, a refused revision re-sent until the fix arrives as a higher one, and drain, kick and seal acked, every answer validated and compared with the vector's (revisions mapped, since the package numbers its own from 1). |
| `hook-endpoint` | The publish veto answers a signed body, refuses a wrong event name and a bad signature, and never breaks one of its five rules; every `HookDecision` shape (deny with and without a reason, allow bare, allow with a `StreamSpec` or an array patch) validates against `publish.verify.response.schema.json` (v0.6.0), as does `PendingHook::decision()`. |
| `schemas-responses` | All 56 wrapped operations validate and round-trip (`clearTranscodePause`, a bodiless `204`, has no body to validate) (the transcript's JSON branch against `TranscriptResponse`, its `text/vtt` and `application/x-subrip` branches asserted declared as strings), with every nested block typed (23 snapshot classes reached from real bodies), plus the inventory equality test: all 138 components at v0.9.3 are modelled (DTO-backed, or a request component handled by `REQUEST_COMPONENT_EMITTERS`) except the four only the local-only `leaveFleet` (three) and `armSealingDrain` (one) reach, derived from the document, and every schema `$def` is modelled (a DTO, a `Fleet*` deviation, or a fleet answer the receiver renders, `DEF_EMITTERS`) or on a dated pending list (`StreamLease` at v0.9.3, the fleet lease); every required response header in the document is accounted for (9 at v0.9.3, 5 on admin operations: the `Location` of `createStream`, `replaceTransferPlan`, `replaceJobTransferPlan`, `createTranscodeJob` and `createMaster`), the three shared node headers are held on every admin response and no WHEP row and `WWW-Authenticate` on every admin `401`, and both branches of the `getHealth` oneOf are decoded (`HealthFull` with the token, `HealthPublic` without). |
| `schemas-requests` | Every request the client sends, driven through the real client for all 56 wrapped operations: each JSON body validates against its request component (a representative `StreamSpec` calling every builder method, `captions.*` included, plus the clearing PATCH), a body goes out exactly where the document declares one, every query parameter is declared and its value allowed, and `Streams::STATES`, `ORDERS`, the `include` tokens and the transcript formats equal the document's enums both ways. No allow-listed disagreement since the v0.4.0 repin dropped F2 (`include` is a comma set in the document now), and the leg fails the day an entry is no longer needed. |
| `routes` | Four derived sets, 71 operations at tmesis `v0.9.5`: the 56 with no `x-tmesis-milestone`, no `x-tmesis-listener` and no `x-tmesis-local-only` are wrapped; the 4 with a listener (the WHEP routes, served on the preview listener with no bearer token) are asserted NOT wrapped on the admin client; the 2 local-only (`leaveFleet` and `armSealingDrain`, which only the box's own CLI calls) are not wrapped; the 9 with a milestone are not wrapped, and each surfaces its milestone through `NotImplementedException`. A route added upstream fails here rather than going unwrapped quietly. |

**The fleet-node acceptance** (group `fleet-node`, plan section 4) runs a REAL node
binary against the reference receiver with the fleet on:
1. join;
2. the first heartbeat;
3. a new revision applied;
4. a revocation list the admin API moved, converged back;
5. kick, seal and drain on a live publish;
6. leave refused while a generation is unreleased;
7. the generation released through `artifacts()`;
8. leave.

It needs a built binary and, for the publish, ffmpeg on PATH (without it the kick and
seal are asserted as moot and it says so). It skips cleanly without `TMESIS_BIN`:

```sh
TMESIS_BIN=/path/to/tmesis/bin/tmesis vendor/bin/pest --group=fleet-node
```

## Bumping the pin

The mechanical half is one command, from a tmesis checkout at the commit to pin:

```sh
bin/repin --tmesis /path/to/tmesis            # or: TMESIS_CONTRACT_DIR=... composer repin
bin/repin --tmesis /path/to/tmesis --tag v0.3.0   # name the tag the checkout sits at
```

It copies the artifacts (since `v0.6.0` that includes `test/vectors/hook.json` and the
role templates, `docs/contract/templates/*.yml` with their `MD5SUMS`: YAML and a
checksum file, not only JSON), rewrites the `PROVENANCE.md` table and header rows from the
checkout's git state (it refuses a checkout whose artifact paths are dirty, so the
Commit row always names the commit the bytes came from), reports what moved, and runs
the suite against both roots. Exit 0 is a repin that is a
commit away; exit 2 names the red groups, which is the half only a person can do:

1. **Nothing moved** (the script says so): the repin is a tag-string edit. The script
   writes `TMESIS_TAG` in `.github/workflows/tests.yml` itself (beside the Tag row, and
   the `provenance` leg fails when the two disagree); by hand: `INVENTORY_MEASURED_AT` in
   `tests/Contract/InventoryTest.php`, and every docblock and test marker that names
   the PREVIOUS pin string: the value the `PROVENANCE.md` Tag row held before the
   script ran, a tag, or the short sha when it read `untagged, <sha> on <branch>`.
   `bin/repin` prints that grep itself, built from the previous Tag row; for the
   `v0.9.4` to `v0.9.5` repin it is
   `grep -rn 'v0.9.4' src tests .github README.md docs`. Expect history hits (the
   CHANGELOG-style mentions of the earlier tag are meant to stay) and read each before
   editing. Stop there.
2. **Something moved**: diff the copies (`git diff tests/Fixtures/contract`). A changed
   schema or vector is the interesting part.
3. Fix whatever the red groups say: a new `$def` wants a DTO (or a dated entry in
   `TIER_TWO_PENDING`), a new event wants a class and an `EventMap` row, a new required
   field wants adding to the derived response fixture (a new event's payload arrives
   as its generated example, copied with the rest), and the counts in `InventoryTest`,
   `ProvenanceTest`, `EventMapTest` and `UnknownFieldTest` move with the artifacts. A
   test that asserted a value an example no longer carries changes its expectation;
   the example is never edited.
4. Run `bin/repin` again: both roots green.
5. Record it in the changelog, and **flag anything non-additive**: inside contract v1
   a removed or renamed field is not allowed, so finding one is a finding about the
   node, not about this package. The script already refuses a vanished artifact.
6. Commit and push. **No sha goes to tmesis**: the contract-receiver job left tmesis
   CI on 2026-09-19, so this suite, run against both roots,
   is the LAST place the second-implementation proof (a PHP client reads what the Go
   node writes) runs. The release takes the version of the tmesis tag the repin
   carries: rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, in the repin PR
   itself or in one of its own; merging it tags and publishes (see
   [Releasing](./development.md#releasing)).

# Running as the reference receiver

The package ships a Testbench Workbench application: a real Laravel app with this
package installed, the three inbound routes on (webhooks, the publish hook and the
keyterms hook), and runtime schema validation turned up. It supports local
integration checks against a real node and is the receiver the `fleet-node` run uses,
which is how "the receiver answered a real node" gets proved rather than asserted.

```sh
composer install
vendor/bin/testbench serve --host=0.0.0.0 --port=8093
```

It prints the resolved absolute URLs at startup, so whoever configures the node
copies them rather than composing them:

```
Tmesis reference receiver
  node name            edge-venue-07
  webhooks.url         http://127.0.0.1:8093/webhooks/tmesis
  ingest.verify_hook   http://127.0.0.1:8093/webhooks/hooks/publish
  keyterms_hook.url    http://127.0.0.1:8093/webhooks/hooks/keyterms
```

| Variable | Default | What it is |
|---|---|---|
| `TMESIS_RECEIVER_NODE` | `edge-venue-07` | must match the node's `node.name`, which is what it sends in `X-Tmesis-Node` |
| `TMESIS_RECEIVER_WEBHOOK_SECRET` | `dev-webhook-secret` | the node's `webhooks.secret` |
| `TMESIS_RECEIVER_HOOK_SECRET` | the webhook secret | the node's `ingest.verify_hook.secret` |
| `TMESIS_RECEIVER_KEYTERMS_SECRET` | the webhook secret | the node's `transcription.keyterms_hook.secret` (tmesis `v0.8.0`) |
| `TMESIS_RECEIVER_KEYTERMS` | none | the comma-separated list the keyterms hook answers for every stream (none REPLACES a stream's own list for the session) |
| `TMESIS_RECEIVER_KEY_SECRET` | `dev-key-secret` | the fleet-wide `ingest.keys` secret publish keys are minted and verified with (`tmesis.keys.secret`) |
| `TMESIS_RECEIVER_TOKEN` | `dev-admin-token` | the node's `api.admin_token` |
| `TMESIS_RECEIVER_URL` | `http://127.0.0.1:7000` | the node's admin API |
| `TMESIS_RECEIVER_FLEET` | off | `1` turns the fleet control plane on (tmesis `v0.7.0`): the three fleet routes, the database dedupe store (the fleet refuses a non-durable one) on the app's default connection, and the package migrations |
| `TMESIS_RECEIVER_FLEET_DESIRED` | none | the JSON file the desired state is read from, re-read on every heartbeat |
| `APP_URL` | `http://127.0.0.1:8093` | what the printed URLs are built from. Set it to an address the NODE can reach. |

The defaults are the tmesis repo's own dev defaults, so `make -f build/Makefile
dev-up` on one side and `vendor/bin/testbench serve` on the other need no arguments.

It logs one line per delivery (event, delivery id, `occurred_at`, whether it
validated) and one per hook decision (stream, key type, allow, elapsed ms). Its
delivery store is the file cache (the database with `TMESIS_RECEIVER_FLEET=1`), which
survives a restart, so a delivery the node
retries after one is not dispatched twice.

`vendor/bin/testbench tmesis:doctor` checks the receiver the same way it checks a
consumer's app.

## Running the legs

How the legs are meant to be run:

1. **`TMESIS_CONTRACT_DIR` points at a tmesis CHECKOUT ROOT.** The suite joins the
   fixed relative paths (`docs/contract/`, `test/vectors/`) itself.
2. **A second run, with the variable unset, is what proves the package's bundled
   copies are the ones it was built against.** A difference between the two runs is a
   provenance failure, not a flake.
3. **One `pest` invocation per group, with an explicit accumulator rather than
   `set -e`**, so a misspelled group is an empty suite and an empty suite is a failure
   wherever it sits in the list:

```sh
failed=0
for group in provenance vectors-keys vectors-webhooks vectors-hook vectors-keyterms \
             schemas-events schemas-fleet fleet-receiver hook-endpoint schemas-responses schemas-requests routes; do
  vendor/bin/pest --ci --fail-on-empty-test-suite --group="${group}" || failed=1
done
exit "${failed}"
```

A red `provenance` group against a tmesis checkout means this package needs a repin:
the checkout's artifacts no longer hash to `PROVENANCE.md`.
