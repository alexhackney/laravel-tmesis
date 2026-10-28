# Reading from a node

```php
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Facades\Tmesis;

$health = Tmesis::node()->getHealth();
$health->status();                 // ok, degraded, unavailable
$health->journal()?->outboxDepth();

// Live capacity (tmesis v0.9.0). admission 'on' refuses a publish past
// encodeSlotsTotal() as over_capacity; 'off' (unmeasured, stale, insufficient) admits
// every publish and is NOT a fault: a box updated in place reads 'off' until
// `tmesis bench --profile live --store` runs once. encodeSlotsTotal() is NULL whenever
// admission is not on; the stats body's capacity says 0 for the same state.
$live = $health->liveCapacity();
$live->admissionOn(); $live->source(); $live->encodeSlotsUsed(); $live->deviceSessionsTotal();
$health->capacity();               // still the JOB slots, unchanged

// The encoder pins (tmesis v0.9.0): requested() is the config value, usable() false
// ONLY for a pin that failed at boot (that workload is refused until it is repaired).
// video_engine is null on a transcoder; its encoder is jobsVideoEngine().
$health->videoEngine()?->usable();
$health->jobsVideoEngine()?->requested();
$health->degradedBecause(HealthFull::DEGRADED_ENCODER_UNAVAILABLE);
$health->jobs()?->paused()?->until();   // the jobs pause in force, null when none

// Transcription (tmesis v0.9.1): one entry per configured role, live then batch
// (then fallback, tmesis v0.9.2, only when transcription.fallback_provider is set), []
// when no provider is configured. A fallback that is down is degraded, never not ready. A provider down for 30 s puts
// transcription_unavailable on degraded_reasons; captions fail soft, so ready() stays
// true and no show is touched. `tmesis:health` warns on it and does not fail.
$health->degradedBecause(HealthFull::DEGRADED_TRANSCRIPTION_UNAVAILABLE);
foreach ($health->transcription() as $role) {
    $role->role();          // live, batch or fallback
    $role->provider();      // deepgram or self_hosted
    $role->state();         // up, down, unknown (unknown is no evidence, not a fault)
    $role->unavailable();   // this entry is why transcription_unavailable is set
    $role->detail();        // the newest failure in the node's words, never a key
}

// No token sent: the four liveness fields only (status, node, version,
// contract_version). Works against a node configured with no token.
Tmesis::node()->getPublicHealth()->status();

$stream = Tmesis::node()->getStream('show-2041-main');
$stream->state();                  // provisioned, publishing, live, input_lost, draining, sealed
$stream->minKeyEpoch()?->value;
$stream->recording()->ingest()->destination();   // the EFFECTIVE value, null for local only
$stream->preview()->maxMonitors();

// include takes two tokens, preview and captions, on the single read only (the list
// takes none). include=preview adds the WHEP monitor block, which a control plane
// hands to a browser player. whep_url is
// null until the node's preview.public_url is set. Every WHEP 201 carries
// X-Tmesis-Stream-State (live or held; a held session binds with no frames until
// the rejoin, not a 409): the player reads the header to tell "waiting for the
// publisher" from "live", so a panel across a hiccup binds and waits rather than
// polling.
Tmesis::node()->getStream('show-2041-main', includePreview: true)->previewMonitor()?->whepUrl();

// Captions (tmesis v0.3.0). The EFFECTIVE settings are always on the record;
// include=captions adds the provider session, present only while captions are on.
// The two tokens compose: both sends include=preview,captions.
$stream->captions()->enabled();
$stream->captions()->languages();   // ['en']; one tag in v1
$session = Tmesis::node()->getStream('show-2041-main', includeCaptions: true)->captions()->session();
$session?->state();                 // idle, connecting, live, degraded, ended
$session?->reason();                // null unless degraded or ended
// Since tmesis v0.9.1 degraded returns to live by itself when the provider answers
// again, unless the reason is auth_error or quota_exceeded (those want a person).

// The stats body carries its own caption counters: null until a captioner has
// reported, and a DIFFERENT shape from the session above (request_id, every field
// non-null, never idle).
Tmesis::node()->streams()->getStreamStats('show-2041-main')->captions()?->finals();

Tmesis::node()->listStreams('live');
Tmesis::node()->getDoctor();
Tmesis::node()->relays()->listRelays('show-2041-main');

// The window is always the most recent `limit` rows; `order` is only the reading
// direction, and the default is oldest first (asc) since rc7.
Tmesis::node()->streams()->getStreamEvents('show-2041-main', since: now()->subHour());
Tmesis::node()->streams()->getStreamEvents('show-2041-main', limit: 50, order: 'desc');
```

## The transcript

`GET /streams/{id}/transcript` is a tail read and a FEED: one row per final cue, with
the cue's wall-clock `program_date` (so a hit in a search index maps back to a second
of the DVR) and word-level timings with confidence. Pass the last `seq` you saw as
`since` to poll for new cues while the show runs; once the stream is sealed the same
route answers the whole session.

```php
$streams = Tmesis::node()->streams();

$transcript = $streams->getStreamTranscript('show-2041-main');            // lang defaults to the stream's
foreach ($transcript->cues() as $cue) {
    $cue->seq();
    $cue->start();                  // seconds from the session's media origin
    $cue->programDate();            // DateTimeImmutable, UTC
    $cue->text();
    $cue->words()[0]->confidence();
}

// Tail: only the cues after the last one seen. lastSeq() is null on an empty page,
// so keep the since you sent.
$since = $transcript->lastSeq() ?? 0;
$next = $streams->getStreamTranscript('show-2041-main', lang: 'en', since: $since);

// The WebVTT and SRT renderings, as strings, sent with the Accept header from
// Streams::TRANSCRIPT_TEXT_FORMATS (vtt => text/vtt, srt => application/x-subrip).
// Any other format (json included: that is getStreamTranscript()'s job) and a
// negative since on either method throw InvalidConfigurationException before any
// request.
$vtt = $streams->getStreamTranscriptText('show-2041-main', 'vtt');
$srt = $streams->getStreamTranscriptText('show-2041-main', 'srt', since: 120);
```

A `404` (`NotFoundException`) means there is nothing to render: no such stream, OR
no captions in that language. The node does not tell the two apart. Once a show is sealed,
`caption.session_ended` and `stream.sealed` name the transcript sidecars on the
bucket (`CaptionPlayback::json()` is the same feed), so a consumer that indexes after
the show need not poll the route at all.
