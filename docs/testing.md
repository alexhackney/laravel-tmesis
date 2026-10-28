# Testing your app

```php
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Testing\FakeTmesis;
use Illuminate\Support\Facades\Event;

// Canned reads, no HTTP at all.
$fake = Tmesis::fake()->withStreams([
    ['id' => 'show-2041-main', 'state' => 'live', /* ... */],
]);

Tmesis::node()->listStreams('live');

$fake->assertCalled('listStreams');

// A signed synthetic delivery through your real receiver.
Event::fake();

FakeTmesis::webhook('stream.published', $payload)->deliverTo($this->app);

Event::assertDispatched(StreamPublished::class);
```

`deliverTo()` runs the HTTP kernel's `terminate()`, which is where the dispatch lives.
Your test needs `webhooks.enabled` true and a dedupe store; the `cache` driver on the
`array` store is the documented choice.

`FakeTmesis::webhook()` does not validate the payload you give it. For schema-valid
bodies, read the node's generated examples, which ship with this package at
`vendor/hei/laravel-tmesis/tests/Fixtures/contract/docs/contract/examples/*.json`
(one per event, plus `publish.verify` and `publish.verify.response`). Since the `v0.4.0` repin the `relay.started`
and `relay.reconnected` examples carry `destination.state` `connecting`, the value the
node always sends on both (the `v0.3.0` examples said `connected` and had to be
overridden in tests).

The fake covers the write surface too, and a write CHANGES what it holds, so a test
can provision a stream and then read it back:

```php
$fake = Tmesis::fake();

Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->ladder('broadcast-720p'));

Tmesis::node()->getStream('show-2041-main')->ladder();          // broadcast-720p
Tmesis::node()->streams()->kickStream('show-2041-main');
Tmesis::node()->getStream('show-2041-main')->session();         // null

$fake->assertCalled('createStream', fn (array $args): bool => $args['id'] === 'show-2041-main');
$fake->revocations();                                           // the list now in force
```

Transcripts are seeded rather than derived. An unseeded read throws
`NotFoundException`, as the node's `404` would:

```php
$fake = Tmesis::fake()
    // The body's lang governs: another lang is a 404, and since filters cues by seq.
    // Every read answers provider as the first returned cue's, else the primary:
    // primary: here, else the held stream's captions provider, else the body's own.
    ->withStreamTranscript('show-2041-main', $transcriptBody, primary: 'deepgram')
    // A null lang (the default) answers any language.
    ->withStreamTranscriptText('show-2041-main', 'vtt', "WEBVTT\n\n...", lang: 'en');

Tmesis::node()->streams()->getStreamTranscript('show-2041-main', since: 1);

// Both methods record as getStreamTranscript, with args {id, format, lang, since}.
$fake->assertCalled('getStreamTranscript', fn (array $args): bool => $args['since'] === 1);
```

A recorded relay call carries the destination's REDACTION and never the URL, so a
test asserting on one is asserting on scheme, host and port.

And a signed synthetic publish hook, through your real endpoint and your real decider:

```php
$decision = FakeTmesis::hook(['stream' => 'show-2041-main', 'key_type' => 'bare_unproven'])
    ->decision($this->app);

expect($decision)->toBe(['allow' => false, 'reason' => 'unproven key']);
```

That needs `hooks.enabled` true and nothing else: a hook is answered, never stored, so
there is no delivery store in the way.

Preview grants and markers (tmesis `v0.6.0`) have ledgers of their own. Every create
is a new grant, an update moves `expires_at`, and addresses are held in the node's
canonical form. A marker needs a stream the fake holds as `publishing` or `live`
(anything else is `409 stream_not_live`), stays `queued` until you land it, and
`landMarker()` hands back the signed `marker.placed` delivery for your receiver:

```php
$fake = Tmesis::fake()->withStream(['id' => 'show-2041-main', 'state' => 'live', 'session' => $session, 'epoch' => 3]);

// ...the code under test grants a panel and places a break...

$fake->grants();                                            // the LIVE grants
$fake->assertGranted('203.0.113.7', 'show-2041-main')       // ::ffff:203.0.113.7 matches too
    ->assertRevoked($grantId)
    ->assertMarkerPlaced('show-2041-main', $markerId);

$fake->landMarker('show-2041-main', $markerId, ['480p', '720p'])->deliverTo($this->app);
$fake->markers('show-2041-main')[0]->isPlaced();            // true
```

## The fleet, in a test

`Tmesis::fake()->fleet()` returns a `FakeFleet`, and `Tmesis::fleet()` answers it from
then on. It is not a second implementation: enrolment, the heartbeat and every command
run through the package's REAL store and handler (minus HTTP and tokens), so it needs
the package migrations on your test database, and it calls your `DesiredStateProvider`
exactly as a real heartbeat does. Each `enrol()` gets a scripted node that applies what
it is sent and acks what it received, like a healthy one:

```php
$fleet = Tmesis::fake()->fleet();
$node = $fleet->enrol('ny.in.tsp24.com');

$kick = Tmesis::fleet()->kick($node->id, 'show-2041-main');
$fleet->heartbeat($node->id);   // delivered
$fleet->heartbeat($node->id);   // acked

$fleet->assertCommandAcked($kick)->assertConverged($node->id);
$fleet->event($node->id, 'stream.published', ['stream' => 'show-2041-main']); // rides the next beat
```

`rejectNext()`, `withoutApplying()` and `withoutAcking()` script the unhealthy cases.
`withJobsPause($nodeId, JobsPauseState)` makes the node's heartbeats report a jobs
pause (tmesis `v0.9.0`), so `FleetNode::jobsPaused()` reads it.

On the admin side, `Tmesis::fake()` models the standalone jobs pause:
`setTranscodePause()` / `getTranscodePause()` / `clearTranscodePause()` keep one pause
on Laravel's clock and show it in health `jobs.paused`; `fleetOwnsJobsPause()` makes
the writes answer `409 fleet_owned`, `pauseJobsByFleet()` sets a fleet pause, and
`jobsEncoderUnavailable()` makes `createTranscodeJob()` throw
`EncoderUnavailableException`, and `withoutJobEngine()` makes every transcode route
answer `501` (`NotImplementedException`, milestone M3) like a build with no job runner.
The fake echoes `until` in UTC to the second, as the node does. `assertJobsPaused()` and `assertJobsNotPaused()` check it.

## Artifacts and the release obligation

`Tmesis::fake()` keeps an in-memory artifact ledger, so the whole `artifacts()`
resource answers without a node, with the node's rules: `expected_revision` must be
current (`409 transfer_plan_stale`), a reused `request_id` returns the stored answer
or `409 request_conflict`, a release needs every target complete (`409
transfer_incomplete`), and an override must waive every incomplete target. Since
tmesis `v0.5.0` the node deletes nothing it was not told to release, so prove your app
releases what it copies:

```php
use AlexHackney\Tmesis\Testing\FakeTransferSigner;

$fake = Tmesis::fake()
    ->artifactFinalized('show-2041-main', $generation)        // or kind: ArtifactStatus::KIND_RECORDING_INGEST
    ->artifactAbandoned('show-2041-main', $crashed);          // an hls_sealed that can never finalize

// ... your app plans a copy; move it along:
$fake->completeTransfer('show-2041-main', $generation, $targetId);   // or failTransfer(...)

// A matching delivery body for your receiver:
FakeTmesis::webhook('artifact.finalized', [...$fake->artifactIdentity('show-2041-main', $generation), /* ... */]);

$fake->assertTransferPlanPut('show-2041-main', $generation, fn (array $plan): bool => $plan['expected_revision'] === 0);
$fake->assertReleased('show-2041-main', $generation);          // release, override or discard
$fake->assertEveryFinalizedGenerationReleased();              // at the end of a flow
$fake->assertEveryGenerationDecided();                        // the same, and every abandoned one discarded

// The signer seam: binds a fake signer and registers the listener.
$signer = FakeTransferSigner::fake()->signEverything();
$signer->assertSignedHead($objectId);
$signer->assertNothingSigned();
```

The told master and the purge (tmesis `v0.8.0`) run on the same ledger:
`createMaster()` queues a `recording_master` generation over FINALIZED
`recording_ingest` parts of the stream (the node's `invalid_source`, `request_conflict`
and retry rules; the parts hold `master_pending`), and the test moves it on:

```php
$fake->artifactFinalized('show-2041-main', $part0, ArtifactStatus::KIND_RECORDING_INGEST)
    ->artifactFinalized('show-2041-main', $part1, ArtifactStatus::KIND_RECORDING_INGEST);

// ... your app requests the master at show end; then:
$fake->assertMasterRequested('show-2041-main', fn (array $master): bool => $master['target']['key'] === 'shows/2041/master.mp4');
$fake->finishMaster('show-2041-main', $masterGeneration);   // finalized, its target filed as revision 1
// or ->startMaster(...) / ->failMaster(..., 'why')

$fake->assertPurged('show-2041-main', $part0);              // a purge recorded on its decision
```

A purge of a part a pending master holds is `409 artifact_held`, a second purge under
another request `409 release_conflict`. The fake's stream records store
`captions.keyterms` as the node does (trimmed, deduplicated).

The fake models no multipart, no retention clock and no retirement (a purged generation
just drops its retention hold), and never stores or records a signed URL or header.

## A decider that does no I/O

The node gives the publish hook 500 ms IN ALL, network both ways included, and your
decider gets what is left. Make it a cache read and nothing else: fill the cache AHEAD
of the publish (when a show is scheduled, a key issued, an entitlement changed), and
treat a miss as an answer, never as a reason to go and look.

```php
class EntitlementDecider implements PublishHookDecider
{
    public function decide(HookRequest $request): HookDecision
    {
        $entitled = Cache::get("tmesis-entitled:{$request->stream()}");   // written ahead, never here

        return match ($entitled) {
            true => HookDecision::allow(),
            false => HookDecision::deny('not entitled'),
            null => HookDecision::allow(),      // unknown: let the node's own proof stand
        };
    }
}
```

Measure it rather than trust it. `hooks.slow_ms` (default 100, `TMESIS_HOOKS_SLOW_MS`)
is the budget: a decider over it logs `Tmesis publish hook decider is slow.` naming the
class, and a test can pin that it never does, with the database and HTTP both out of
reach:

```php
it('decides from the cache alone', function (): void {
    Log::spy();
    Http::preventStrayRequests();
    DB::listen(fn () => throw new RuntimeException('the decider queried the database'));
    config()->set('tmesis.hooks.slow_ms', 50);
    Cache::put('tmesis-entitled:show-2041-main', false);

    $decision = FakeTmesis::hook(['stream' => 'show-2041-main'])->decision($this->app);

    expect($decision)->toBe(['allow' => false, 'reason' => 'not entitled']);
    Log::shouldNotHaveReceived('warning');
});
```

Why it matters more than it looks: on a node whose `on_timeout` posture is `allow`, a
decider that runs past the cap is a SILENT ALLOW for every key the node proved (since
tmesis `v0.6.0` a `bare_unproven` key on an OPEN node takes `on_timeout` too; see "The
outage posture, plainly"). The node stops waiting and admits the publisher; your
decider finishes later, returns its `deny` to a node that is no longer listening, and
`PublishHookDecided` still reports that deny. The node raises no WARN for a proven
key's timeout allow, only a count on
`tmesis_publish_hook_decisions_total{hook,outcome="timeout"}` (an OPEN node's
bare-unproven allow is also a WARN and counts `tmesis_hook_outage_allowed_total{stream}`). The slow warning is the package's trace,
so treat it as an incident.

The state the decider reads is written by listeners, and delivery is unordered, so a
listener must not flip state on a stale event. A `stream.unpublished` for an older
session can arrive after the `stream.live` of a newer one. Compare before writing:
`session()` names the session, and `sessionEpoch()` (a `SessionEpoch`) is raised by
the node every time a publisher attaches, so a lower one is older:

```php
Event::listen(StreamUnpublished::class, function (StreamUnpublished $event): void {
    $show = Show::firstWhere('stream_id', $event->stream());

    if ($show === null
        || $show->tmesis_session !== $event->session()
        || $event->sessionEpoch()->value < $show->tmesis_session_epoch) {
        return;     // an older session ending; the current one is still on air
    }

    $show->markOffAir($event->occurredAt());
});
```

## A keyterms provider, in a test

`FakeTmesis::keytermsHook($stream)` sends the GET the node sends, signed with the
node's keyterms hook secret over an empty body, through the REAL route (it needs
`tmesis.hooks.keyterms.enabled` and a configured or resolved node; nothing is stored,
so no migration). `assertKeyterms()` checks the list and, with `opis/json-schema`
installed, that the body validates against `keyterms.hook.response`;
`assertKeptInForce()` checks a `5xx`, which is how this route reports a provider
failure or a refused list; the node also keeps its list on a timeout, on any non-2xx
other than `404`, and on a body it cannot decode (tmesis WEBHOOKS.md 13.4).

The route is always registered and both the switch and the provider are read per
request (since 0.8.3), so the test sets them itself. With the switch off the GET is
`503 keyterms_disabled`, never a `404`:

```php
it('serves the fight card for the stream', function (): void {
    config()->set('tmesis.hooks.keyterms.enabled', true);
    config()->set('tmesis.hooks.keyterms.provider', FightCardKeyterms::class);
    Show::factory()->forStream('show-2041')->withCard(['Hector Rodriguez', 'UFC'])->create();

    FakeTmesis::keytermsHook('show-2041')
        ->fromNode('atl.origin.tsp24.com')
        ->inLanguage('en')
        ->assertKeyterms(['Hector Rodriguez', 'UFC']);
});
```

## Accept-then-kick on an OPEN edge

An OPEN edge admits a publish it cannot prove locally (`HookRequest::openPosture()` is
true on the hook it sends). Where the hook is off, or the decider must allow on a
cache miss, the check moves after the fact: accept, look it up properly (a queued
listener may do I/O, the handshake is long gone), and kick what should not be there.
This is app code by design; the package supplies `kickStream()` and
the events.

```php
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Contracts\Queue\ShouldQueue;

class KickUnentitledPublishes implements ShouldQueue
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(StreamPublished $event): void
    {
        if ($this->entitlements->allows($event->stream(), $event->auth())) {
            return;
        }

        $node = Tmesis::node($event->node());

        // A kick reads no body and ends whatever session is CURRENT. If this one has
        // already gone and a new publisher attached, leave the new one to its own
        // stream.published.
        if ($node->getStream($event->stream())->session() !== $event->session()) {
            return;
        }

        $node->streams()->kickStream($event->stream());
    }
}
```

The stream stays provisioned after a kick, so a publisher that retries publishes
again and fires a NEW `stream.published`, which runs the check again. What does not
fire it is a rejoin inside the hold: `stream.input_returned` carries the SAME
`session()` and `sessionEpoch()` as the `stream.published` that opened the session,
and no `stream.published` follows. It is the same show coming back, already checked;
do not treat it as a new publish and do not kick on it. (A rival publisher on a LIVE
stream is refused by the node as `stream.publish_rejected` `already_publishing`, so
there is nothing for this listener to do there either.)

## Proving the release obligation

Since tmesis `v0.5.0` the node deletes nothing it was not told to release, so the
policy that releases (or discards) is worth a test of its own. Shape the app's code as
one class the listeners call, and drive it through the fake's ledger:

```php
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Events\TransferCompleted;
use AlexHackney\Tmesis\Facades\Tmesis;

// app/Tmesis/ReleasePolicy.php, called from the TransferCompleted listener and from
// the job that sweeps abandoned generations.
class ReleasePolicy
{
    public function transferCompleted(TransferCompleted $event): void
    {
        $identity = $event->identity();

        // Record the copy first (your VideoFile row), then release the revision
        // THIS event proves, never one read just now.
        $release = ReleaseRequest::make($event->planRevision(), $identity->inventorySha256(), 'copied to vod-bucket');
        $node = Tmesis::node($event->node());

        // A transcode job's output has no stream (v0.6.0): release it on the job.
        $identity->isJobGeneration()
            ? $node->jobArtifacts()->releaseJobArtifact($identity->jobId(), $identity->generation(), $release)
            : $node->artifacts()->releaseArtifact($identity->stream(), $identity->generation(), $release);
    }

    public function abandoned(string $node, string $stream, string $generation): void
    {
        Tmesis::node($node)->artifacts()->overrideArtifact(
            $stream, $generation, OverrideRequest::discardAbandoned('never finalized'),
        );
    }
}
```

```php
it('releases every generation it copies and discards every abandoned one', function (): void {
    $fake = Tmesis::fake()
        ->artifactFinalized('show-2041-main', $generation)
        ->artifactAbandoned('show-2041-main', $crashed);

    // Your planner answers artifact.finalized with a plan (assert it went in) ...
    FakeTmesis::webhook('artifact.finalized', [...$fake->artifactIdentity('show-2041-main', $generation), /* ... */])
        ->deliverTo($this->app);
    $fake->assertTransferPlanPut('show-2041-main', $generation);

    // ... the node copies it, and the policy releases on transfer.completed.
    $fake->completeTransfer('show-2041-main', $generation, 'vod-bucket');
    FakeTmesis::webhook('transfer.completed', [/* identity, plan_revision, target_id ... */])
        ->deliverTo($this->app);

    app(ReleasePolicy::class)->abandoned(TestCase::NODE, 'show-2041-main', $crashed);

    $fake->assertEveryFinalizedGenerationReleased();   // nothing copied and left behind
    $fake->assertEveryGenerationDecided();             // and the abandoned one discarded too
});
```

`assertEveryFinalizedGenerationReleased()` fails naming each finalized generation
with no decision on its CURRENT plan revision; `assertEveryGenerationDecided()` adds
every abandoned generation that was never discarded. Both take an optional stream id,
and both cover a transcode job's generations too.

Jobs run in the fake as well: `createTranscodeJob()` answers `202` and a repeat of the
key `200`; drive the job with `startJob($id)`, `progressJob($id, 'encode', 41.7)`,
`completeJob($id)` (which finalizes one generation per output, files your targets as
revision 1 and lands every object, returning the generation ids by output),
`failJob($id, 'source_unreadable')` or `cancelJob($id)`; read what landed with
`jobLanded($id)`; assert with `assertJobSubmitted($key, fn (array $body) => ...)`
(the body is `Spec::toRedactedArray()`), `assertJobCancelled($id)` and
`assertJobArtifactReleased($job, $generation)`. `artifactFinalizedForJob()` and
`jobArtifactIdentity()` seed and describe a job generation directly. The fake models no
ladders (an `mp4` output is one entry), no scheduling and no `destination_busy`.
Release the revision the event proves, as the policy above does: a revision read just
before the release would, if the plan was replaced in between, release a plan nobody
saw copied (see "Routing and releasing artifacts").
