# Provisioning and lifecycle writes

`StreamSpec` serialises only the fields you set, because `PATCH` is a partial
document: an omitted field is not mentioned, and a field set to `null` is mentioned
and **cleared**.

```php
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Keys\KeyEpoch;

$streams = Tmesis::node()->streams();

// Idempotent on the id. Re-posting a config that repeats the same desired fields
// answers 200 and creates nothing; a different one on an unsealed stream is 409
// ConflictException (stream_exists): change it with updateStream() instead.
$spec = StreamSpec::make()
    ->ladder('broadcast-720p')
    ->segmentSeconds(6)
    ->minKeyEpoch(KeyEpoch::fromInt(7))
    ->labels(['show_id' => '2041']);

$record = $streams->createStream('show-2041-main', $spec);
[$record, $created] = $streams->createStreamWithOutcome('show-2041-main', $spec);   // $created is false

// The ingest recording and the preview (rc7): flat methods that serialise as the
// nested recording.ingest and preview blocks, and refuse what the node refuses with
// the node's own sentence. null clears the destination to the node default.
$streams->updateStream('show-2041-main', StreamSpec::make()
    ->ingestRecordingEnabled(true)
    ->ingestRetentionHours(72)
    ->ingestDestination('/mnt/archive/ingest')   // an absolute path; s3:// is refused since v0.4.0
    ->previewMaxMonitors(20));

// A NAMED storage destination instead of a directory (tmesis v0.6.0): the node holds
// the finalized recording for a told key there. The two are never both set; switch by
// sending the other as null in the same body. Whether the node configures the name is
// its own 422 on recording.ingest.destination_ref.
$streams->updateStream('show-2041-main', StreamSpec::make()
    ->ingestDestination(null)
    ->ingestDestinationRef('csn-vod'));

$streams->getStream('show-2041-main')->recording()->ingest()->destinationRef();   // 'csn-vod'

// How long a HELD session waits for its publisher (tmesis v0.6.0): 1 or more, null is
// the node's fallback.end_seconds. Live: a PATCH during a hold re-arms the deadline,
// so raising it extends that hold and lowering it seals at the new deadline.
$streams->updateStream('show-2041-main', StreamSpec::make()->fallbackEndSeconds(900));

$streams->getStream('show-2041-main')->fallback()->endSeconds();   // always the effective value

// Captions (tmesis v0.3.0), nested under captions the same way. Every captions.*
// field is a next_segment change: on a publishing stream the PATCH answers 200 with
// the field in pending(), and it takes effect at the next segment boundary. Enabling
// captions is the cost control: a captioning stream streams audio to the provider
// for the whole session, silence included.
$streams->updateStream('show-2041-main', StreamSpec::make()
    ->captionsEnabled(true)
    ->captionsAdvertise(true)       // the EXT-X-MEDIA subtitle group in both masters
    ->captionsProvider(null)        // null is the node's transcription.provider
    ->captionsLanguages(['en'])     // one tag in v1; [] is the node default
    // The show's vocabulary (tmesis v0.8.0): a list replaces, [] or null clears, not
    // calling it keeps. Replaceable while live; the node trims and dedupes it.
    ->captionsKeyterms(['Ilia Topuria', 'Cage Sport Network', 'State Farm Arena', 'Atlanta']));

$streams->getStream('show-2041-main')->captions()->keyterms();   // the stored, normalised list
```

A patch answers with what it DID, field by field. **Reading the 200 as "it is set" is
how a control plane comes to believe a ladder switched mid-show:**

```php
$patch = $streams->updateStream('show-2041-main', StreamSpec::make()->segmentSeconds(4));

// On a publishing stream segment_seconds is stored, not in force:
$patch->applied();      // []                              nothing took effect now
$patch->pending();      // ['segment_seconds' => PendingChange]
$patch->unchanged();    // []                              (a field already at that value is listed here)
$patch->auditId();

$patch->pending()['segment_seconds']->when();      // next_session
$patch->pending()['segment_seconds']->reason();    // why, in the node's words

// null CLEARS a field. Omitting it leaves it alone. Two different instructions.
$streams->updateStream('show-2041-main', StreamSpec::make()->minKeyEpoch(null));

// A nested field is reported by its DOTTED PATH, and one patch can split both ways:
// preview.max_monitors is live, preview.enabled waits for the next session.
$patch = $streams->updateStream('show-2041-main', StreamSpec::make()->previewEnabled(false)->previewMaxMonitors(10));
$patch->applied();      // ['preview.max_monitors' => 'live']
$patch->pending();      // ['preview.enabled' => PendingChange]
```

The rest of the lifecycle:

```php
// Seal and write the replay. The values in the answer are EFFECTIVE, never an echo.
$seal = $streams->deleteStream('show-2041-main', graceSeconds: 30, retainConfig: true);
$seal->started;          // false when the seal was already under way (a 200, not a 202)
$seal->graceSeconds();   // what the node will actually wait

// End the current session. Nobody publishing is a SUCCESS with a null session,
// which is what makes a control-plane retry safe.
$streams->kickStream('show-2041-main')->endedASession();
$streams->takeoverStream('show-2041-main');

// A FULL REPLACEMENT of the revocation list. [] clears it. It applies to the NEXT
// handshake, so kick the session too if one is running.
$streams->pushKeyRevocation(['show-2041-main', 'show-2042-main']);
```

`StreamSpec` offers exactly the eighteen fields this build implements: the six flat
ones, `recording.ingest.{enabled,retention_hours,destination,destination_ref}`,
`preview.{enabled,max_monitors}`,
`captions.{enabled,advertise,provider,languages,keyterms}` and
`fallback.end_seconds`. `dvr_window_seconds` answers `501` with a milestone on both
`POST` and `PATCH`, `recording.master` is `422 unknown field` on both since tmesis
`v0.8.0`, any value, null and `{}` included (it was `501` naming M4: the master is a
REQUEST now, [`createMaster()`](./artifacts.md#combining-a-shows-parts-at-show-end)),
`fallback.hold_seconds` (M4), `fallback.chain` and `fallback.slate_asset` (M5) are
`501` naming the path on both whatever their value, `recording.ingest.container` does not
exist and is `422 unknown field` (the container follows the publish protocol), and
`encode.*`, `encryption` and `delivery` are the same or are refused as unknown fields,
so a builder that offered them would be offering an error.

The caption refusals the builder mirrors are the ones the body alone decides: more
than one language, and a keyterms list that breaks the grammar (more than 100 terms,
a term empty or over 50 characters after trimming, a control character;
`Support\Keyterms`), each with the node's own sentence. The provider's token cap on
keyterms (Deepgram, about 500 tokens) is the node's `422`. Once a configured keyterms
hook has answered in a live session, its list replaces the stream's for that session. The rest are node facts and come
back as the node's `422` (`ValidationException`): a provider other than the node's
configured one, a node whose provider is `none`, a passthrough node, and a ladder with
no `audio_only` rung.

## Relays, and why a destination is a credential

A relay URL routinely carries an SRT passphrase or the destination's own stream key.
It goes out in full because the node has to dial it, and **it comes back redacted to
scheme, host and port**. Nothing in this package logs, records or persists an
unredacted one: `RelayRecord` redacts again on the way in, `FakeTmesis` records only
the redaction, and neither request shape has a `__toString`.

```php
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;

$relays = Tmesis::node()->relays();

$relay = $relays->createRelay('show-2041-main', RelayInput::make(
    'srt://backup-den-02.internal:8890?passphrase=...',
    id: 'backup',        // optional; supplying one makes a re-POST idempotent
));

$relay->url();           // srt://backup-den-02.internal:8890

$relays->updateRelay('show-2041-main', 'backup', RelayPatch::make(enabled: false));
$relays->deleteRelay('show-2041-main', 'backup')->stopped();   // {stopped: 1}
$relays->stopRelays('show-2041-main')->stopped();              // 0 is a success
```

**Aliases: one leg, two names (tmesis `v0.6.0`).** A destination provisioned with the
same URL and feed as a static relay in the node's config (the edge's `relay` leg to
the origin, say) is folded into that leg rather than run as a second publisher. The
`relay.*` events then carry the static id (`destination.id: static-0`) and list the
provisioned one in `destination.aliases` (`["origin"]`). Match either:
`RelayDestination::matches('origin')` is true for the id or any alias, and
`aliases()` is `[]` when the node sent none.

**What a destination is sent: the feed (tmesis `v0.4.0`).** By default (`auto`) the
node sends the copy when the source suits the destination and the top ladder rung when
it does not; `ingest` forces the publisher's bytes, and `rung:<name>` sends one video
rung of a ladder plus its audio with no second encode, which is how an encoding node
feeds a platform that wants a fixed shape. `RelayFeed` spells the three, and checks
the grammar only: whether the rung exists on the node's ladders is the node's `422`.

```php
use AlexHackney\Tmesis\Support\RelayFeed;

$relay = $relays->createRelay('show-2041-main', RelayInput::make(
    'rtmp://a.rtmp.youtube.com/live2/...',
    id: 'youtube',
    feed: RelayFeed::rung('720p'),
));

$relay->feed();           // rung:720p, as configured
$relay->feedResolved();   // rung:720p while live; '' while idle, parked, or refused

// A new feed on a running show REPLACES the destination: relay.stopped, then
// relay.started with the new feed on RelayDestination::feed().
$relays->updateRelay('show-2041-main', 'youtube', RelayPatch::make(feed: RelayFeed::ingest()));
```

# Preview grants

Under `preview.access: granted` (tmesis `v0.6.0`) a WHEP monitor opens only from an
address a live grant covers; anything else is `403 not_granted`, answered to the
PLAYER on the preview listener, never to this client. Grants are NODE-scoped: a grant
on one box covers monitors on that box only.

The panel flow is **grant on open, extend on reload, revoke on close**:

```php
use AlexHackney\Tmesis\Data\Preview\PreviewGrantInput;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch;
use AlexHackney\Tmesis\Facades\Tmesis;

$grants = Tmesis::node('edge-venue-07')->previewGrants();

// On open. Send EVERY address family the panel was seen on, in one grant: the WHEP
// offer may arrive over either. streams omitted covers every stream on the node.
$grant = $grants->createPreviewGrant(
    PreviewGrantInput::make([$panelIpv4, $panelIpv6], ttlSeconds: 3600)
        ->forStreams(['show-2041-main'])
        ->withLabels(['panel_session' => $panelSessionId]),
);

$grant->id();     // keep it: update and revoke take it
$grant->ips();    // the node's canonical form, which is not always what you sent

// On reload: the grant's new life from NOW, later or earlier. Never create again:
// every create is an INDEPENDENT grant, so a panel that creates on each reload piles
// them up instead of extending one.
$grants->updatePreviewGrant($grant->id(), PreviewGrantPatch::make(3600));

// On close. A connected monitor no other live grant covers is closed now.
$grants->revokePreviewGrant($grant->id());

// Find your own after a restart: the node echoes labels and never reads them.
$grants->listPreviewGrants()->withLabels(['panel_session' => $panelSessionId]);
```

The builder checks grammar only (1 to 16 IP literals with no CIDR and no zone, a TTL
of 60 to 86400, 1 to 64 stream ids, the label limits) and refuses an empty stream
list with the node's sentence, because the node never reads `[]` as every stream.
Unspecified and multicast addresses are the node's `422`. An update or revoke of an
expired or revoked grant is `404` (`NotFoundException`): an expired grant is never
revived, create a new one.

# Markers

HLS date-range markers (SCTE-35 splice out and in) on a live stream, tmesis `v0.6.0`.

```php
use AlexHackney\Tmesis\Data\Markers\MarkerRequest;
use AlexHackney\Tmesis\Events\MarkerPlaced;

$markers = Tmesis::node('origin-atl-01')->markers();

// The id is minted HERE as a ULID unless you pass one: keep it, it is how you
// correlate marker.placed and how a retry stays idempotent.
$out = MarkerRequest::out()->durationSeconds(30)->scte35($spliceInfoHex);
$response = $markers->placeMarker('show-2041-main', $out);

$response->created;              // true on the 202; false on the idempotent 200
$response->marker()->state();    // queued: this is NOT the landing

// The break ends: an `in` is its OWN marker with its own id.
$markers->placeMarker('show-2041-main', MarkerRequest::in()->durationSeconds(30));

Event::listen(MarkerPlaced::class, function (MarkerPlaced $event): void {
    $event->marker();        // the id you kept
    $event->startDate();     // the same on every rendition
    $event->complete();      // false when the session drained before every rendition cut
    $event->placements();    // one MarkerPlacement per rendition carrying the tag
});
```

Model a marker as **queued on the `202` and landed on `marker.placed`**, never on the
POST: the tag goes in at the next segment cut of every media rendition, up to one
segment later. `listMarkers()` reads every marker told on the stream, every session,
oldest first; one still queued when its session ended reads `expired`.

Idempotency is per publish SESSION `(stream, session, id)`: the same request under an
id is the `200`, a different one is `409 marker_exists`, and a new session may reuse
the id. `409 stream_not_live` means no live session with a packager (nobody
publishing, draining, or a passthrough node); both are `ConflictException`, told
apart by `code()`. The window on `at` (more than one target duration in the past, or
more than a day ahead) is the node's `422`.
