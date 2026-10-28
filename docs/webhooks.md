# Receiving webhooks

Turn the route on with `TMESIS_WEBHOOKS_ENABLED=true`. It is registered at
`POST {prefix}/tmesis` and named `tmesis.webhooks.receive`.

Listen for what you care about:

```php
use AlexHackney\Tmesis\Events\StreamLive;
use Illuminate\Support\Facades\Event;

Event::listen(StreamLive::class, function (StreamLive $event): void {
    $event->stream();               // show-2041-main
    $event->playback()->master();
    $event->occurredAt();
});
```

`AlexHackney\Tmesis\Events\TmesisWebhookReceived` is the base class, and the class dispatched
for an event name this build has no typed class for. **To see every delivery, listen
on `AlexHackney\Tmesis\Events\WebhookEvent`**, the interface the base class implements (so all
38 typed events and every unknown name do). A listener on `TmesisWebhookReceived`
fires ONLY for unknown names: the receiver dispatches one object, the typed one, and
Laravel matches listeners by that exact class plus `class_implements()`, never by
parent class.

```php
use AlexHackney\Tmesis\Events\WebhookEvent;

Event::listen(WebhookEvent::class, function (WebhookEvent $event): void {
    Log::info('tmesis delivery', [
        'event' => $event->event(),
        'node' => $event->node(),
        'delivery' => $event->deliveryId(),
    ]);
});
```

Captions (tmesis `v0.3.0`) arrive two ways. `stream.live`'s `playback()` and
`stream.sealed`'s `vod()` carry a `captions()` map keyed by RFC 5646 tag (null when
the session had no caption rendition): the DVR caption playlist with null sidecars
while live, the VOD playlist and the `vtt`, `srt` and `json` transcript sidecars once
sealed. A sidecar the sink refused stays null rather than a URL that 404s.
**Since tmesis `v0.8.0` the replay is at the live URLs:** the final seal ends the live
playlists in place, so `vod()->master()` is the stream's `playlist.m3u8`, the live master the
seal ends in place (`stream.live` advertises the full-window `playlist_dvr.m3u8` beside
it), each rendition its `<rung>.m3u8`, each caption playlist
`captions_<lang>.m3u8`; `vod.m3u8` and the `_vod` names are no longer written. (The
generated `stream.sealed` and `caption.session_ended` examples show the new names since
the tmesis `v0.9.0` tag; at `v0.8.x` they were stale.)
`caption.session_started` fires once per caption SESSION since tmesis `v0.9.3` (the first
open, a resume, a switch and a rejoin), never per provider socket: reconnects, rotations and
re-anchors no longer fire it, so do not count starts as sockets. `caption.session_ended`
carries the socket counts instead:

```php
use AlexHackney\Tmesis\Events\CaptionSessionEnded;
use AlexHackney\Tmesis\Events\CaptionSessionStarted;
use AlexHackney\Tmesis\Events\StreamSealed;

Event::listen(StreamSealed::class, function (StreamSealed $event): void {
    ($event->vod()->captions()['en'] ?? null)?->json();     // the transcript feed on the bucket
});

Event::listen(CaptionSessionEnded::class, function (CaptionSessionEnded $event): void {
    $event->reason();       // one of CaptionSessionEnded::ENDED_REASONS
    $event->detail();       // the provider's own message, verbatim; null on the node's closes except provider_switch
    $event->artifacts();    // this language's sidecars, only on a stream_ended close after the seal
    $event->mayResume();    // tmesis v0.9.1: a provider degrade the node resumes by itself
    $event->isProviderSwitch();   // tmesis v0.9.2: the node moving to its other provider
    $event->captionsContinue();   // mayResume() or a provider switch: a start follows
    $event->sockets();      // tmesis v0.9.3: provider sockets opened, cumulative like reconnects()
    $event->rotations();    // tmesis v0.9.3: socket rotations, cumulative
    // false is NOT the end: a hold's stream_ended close comes at the rejoin with
    // artifacts() null and captions carry on; the end is stream.sealed
});

Event::listen(CaptionSessionStarted::class, function (CaptionSessionStarted $event): void {
    $event->provider();     // deepgram or self_hosted (tmesis v0.9.1)
    $event->resumedAfter(); // the degrade reason this start resumes from; null on any other start
    $event->switchedFrom(); // tmesis v0.9.2: the provider a failover or failback left, else null
    $event->isFallback();   // true while the session runs on transcription.fallback_provider
});
```

**`delivery_mode` widened to `both` in tmesis `v0.6.0`** on `stream.live`,
`stream.sealed` and the stream record: the box serves AND the bucket receives the same
output. A receiver that switches on `bucket` and `origin` must take the third value.
The playback URLs are PATHS (the roots are yours to prefix), so map `both` to both
roots, bucket first, with the box as the fallback. `servesFromBucket()` and
`servesFromOrigin()` answer that without a switch:

```php
Event::listen(StreamLive::class, function (StreamLive $event) use ($bucketCdnRoot, $boxOriginRoot): void {
    $roots = array_filter([
        $event->servesFromBucket() ? $bucketCdnRoot : null,   // first
        $event->servesFromOrigin() ? $boxOriginRoot : null,   // the fallback on `both`
    ]);
    $path = $event->playback()->master();
});
```

**A restart holds the show (tmesis `v0.8.0`).** A node restart, deploy or fleet
`update` no longer seals: a session live at the drain gets `stream.input_lost` with
`reason()` `node_draining` (`StreamInputLost::REASON_NODE_DRAINING`), NOT
`stream.unpublished`, and the publisher's return is `stream.input_returned`, NOT
`stream.published`. A listener that treated `stream.input_lost` as the end of a
broadcast must take that reason. Three more widenings to take in a switch:
`protocol` `rtmps` (`PROTOCOL_RTMPS`, an RTMP publish over TLS), `stream.sealed`'s
`sealed_by` `crash_recovered` (a window a crashed process left, sealed now with an
event where it used to seal silently), and `recording.completed`'s `master_finished`.

`auth_error` and `quota_exceeded` are never retried by the node and want a person;
`rate_limited`, `provider_closed` and `unreachable` are retried on a capped backoff,
`gave_up` is that cap reached, and `stalled` (tmesis `v0.9.3`) is a provider that stopped
answering, or answered too late, while audio flowed. Since tmesis `v0.9.4` `stalled` is only
a provider that was CONNECTED and not keeping up: an outage (unreachable, refusing or
dropping connections) rides through on the same session when short and ends `gave_up`
after about 60 s when long, so read `gave_up`, not `stalled`, as "the provider is down".
Also since `v0.9.4`, every `caption.session_ended` has a `caption.session_started` for the
same caption session before it: one that ends before its start went out (refused at its
first open, for one) gets its start just before the end, with `providerRequestId()` null,
on either provider. **Since tmesis `v0.9.1` none of
those ends captions for the show:** they resume by themselves when the provider answers again,
and the resume is a `caption.session_started` with the SAME `session` and
`resumedAfter()` naming the reason it came back from
(`CaptionSessionEnded::RESUMABLE_REASONS`, `mayResume()`). A control plane that marked
a show's captions finished on `gave_up` must wait for the stream's own end instead:
`stream.sealed`, or a `stream_ended` close that carries `artifacts()`. A `stream_ended`
close with `artifacts()` null is a hold's phase, stamped at the rejoin,
and the captions continue in the same session.

**Caption failover (tmesis `v0.9.2`).** A node with `transcription.fallback_provider`
set moves a degraded stream to its other provider and back. A switch is a
`caption.session_ended` with `REASON_PROVIDER_SWITCH` (a node close whose `detail()`
says why), then a `caption.session_started` in the same publish session with
`switchedFrom()` naming the provider it left and `isFallback()` saying which side it is
on; a start can carry both `resumedAfter()` and `switchedFrom()`. `mayResume()` stays the
same-provider resume and is false for a switch; `captionsContinue()` is true for both.
With a fallback, `auth_error` and `quota_exceeded` still want a person (the refused
primary is not retried until the node restarts), but the captions may carry on on the
fallback, which the event cannot tell you. The provider is per session now: the stream
record's `captions()->provider()` is the node's PRIMARY, while
`captions()->session()?->provider()`, each start and each transcript cue's `provider()`
name the one actually used. Since tmesis `v0.9.3` a start on the fallback can also mean the
box's captioned capacity was full (`switchedFrom()` names the primary): health
`liveCapacity()->captionSessionsTotal()` and `captionSessionsUsed()` are the self-hosted
captioned slots, null when there is no self-hosted live captioning or it was never benched.
Since tmesis `v0.9.4` the first open after a rejoin while the stream is on the fallback
also carries `switchedFrom()`, naming the primary.

## Capacity, encoders and the jobs pause (tmesis `v0.9.0`)

- **`stream.publish_rejected` `over_capacity` means the box is FULL, not a bad key**
  (`StreamPublishRejected::REASON_OVER_CAPACITY`): the key verified, and rotating it
  does nothing. Send the producer to another box. `encoder_unavailable` means the box's
  live encoder pin failed at boot. Neither is rate limited for a proven key, so a
  reconnecting encoder sends one per attempt: dedupe on the delivery, never alert per
  event.
- **`NodeCapacityChanged`** is node-scoped and LATCHED (no flapping): `reason()`
  `benchmark` and `encoder` fire once per boot after `node.online`; `admission` and
  `device` fire with `full()` true when a ledger fills and false only after it stayed
  below its limit for 30 s. Alert on `encoder` with either `usable()` false, on
  `full()` true, and on `benchmark` with `liveCapacity()->source()` `stale` or
  `insufficient`. `previous()` holds only the fields that changed.
- **`NodeOnline`** carries `capacity()->admission()` (`encodeSlotsTotal()` 0 with
  admission not on means UNMEASURED, never "no capacity": keep routing to the box),
  `videoEngine()` (null on a transcoder), `jobsVideoEngine()` and `jobsPause()`. A pause
  restored at boot sends NO `jobs.paused`: this, and the fleet heartbeat, are how you
  learn of it. A transcoder reports empty `rtmp` and `srt` listeners.
- **`JobsPaused` / `JobsResumed`** pair by `JobsResumed::pausedAt()` ==
  `JobsPaused::setAt()`; `cause()` is `expired`, `cleared` or `released`. They are
  node-scoped, not `JobEvent`s. The heartbeat (`FleetNode::jobsPaused()`) or health is
  the stop-offering rule; the events may drive it too.
- **`NodeDiskFloorNear`** fires once on entering the margin above
  `limits.disk_floor_gb` (now 5 GB by default) and has no all-clear: read health.
  `headroomBytes()` goes NEGATIVE once the floor is breached; `totalBytes()` and
  `marginBytes()` 0 mean unknown.

## What your app has to do itself

- **Run the `tmesis-migrations` migration before enabling the route** when you leave
  the dedupe driver at its default. A claim that cannot insert answers `500` (and the
  node retries). On the `table` driver there is nothing to migrate; run
  `tmesis:doctor` to check the table and its columns instead.
- **Exempt the path from CSRF** if you put the route inside the `web` group.
- **Add the prefix to any tenant-resolution bypass list.** The node is not a tenant.
- **Queue your own work.** The node's per-attempt timeout is 10 seconds.

## Ordering, duplicates, and unknown fields

- **Order by `occurredAt()`, never by arrival.** Delivery is unordered: a
  `stream.sealed` can reach you before the `stream.live` that preceded it.
- **Delivery is at-least-once.** A duplicate is normal traffic, not an error. The
  dedupe key is the signed body's `delivery`, with the `X-Tmesis-Delivery` header as
  a fallback; the header is not covered by the signature and the body is, so a replay
  with a rewritten header still dedupes.
- **Unknown fields survive.** `toArray()` returns the payload exactly as it arrived, so
  a field from a newer node reaches your listener even though this build has no
  accessor for it.

## Middleware, and why the default is empty

`webhooks.middleware` defaults to `[]`. The signature middleware is the route's own
authentication; it is not a route you want inside a consumer's `api` group, which
typically carries `throttle:api` at 60 requests a minute per IP. A node delivering
with concurrency 4 crosses that, every `429` is retried for 24 hours, and each retry
comes back as a duplicate.

If you want a limiter, key it on the node:

```php
// config/tmesis.php
'webhooks' => ['middleware' => ['throttle:tmesis']],

// AppServiceProvider::boot()
RateLimiter::for('tmesis', fn (Request $request) => Limit::perMinute(600)
    ->by($request->header('X-Tmesis-Node', $request->ip())));
```

## When the 204 leaves

The receiver stores the delivery, answers `204`, and dispatches afterwards, from the
route middleware's `terminate()`.

Under PHP-FPM (`fastcgi_finish_request`) and under Octane the node already has its
`204` before your listeners run. Under mod_php or `php artisan serve` the node waits
for them to finish, which is one more reason to queue your own work.

If a listener throws, the response is still `204` and the exception is reported. The
node has its `204` and will never retry that delivery, so the claim is KEPT: on the
database driver the row stays unprocessed with its signed body, and the failure is
recorded on it (`failed_attempts`, `last_error` as the exception class and a message
truncated to 1000 characters, `failed_at`). On the `table` driver the row stays with a
null `processed_at` and the failure is in your log and exception reporter only. Dispatch it again with
`tmesis:webhooks:retry`, from the scheduler or by hand:

```php
// routes/console.php
Schedule::command('tmesis:webhooks:retry')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tmesis:webhooks:prune')->daily();
```

```sh
php artisan tmesis:webhooks:retry                          # everything unprocessed for 60s or more
php artisan tmesis:webhooks:retry --id=01J8ZC9GQ2M4YE7XW1RN0P6VTB --older-than=0
php artisan tmesis:webhooks:retry --node=edge-venue-07 --limit=20
```

The retry rebuilds the typed event from the stored body and headers through the same
event map the receiver uses, so a listener sees the same class, the same payload and
the same `delivery()` (node, delivery id, attempt, signature, raw body) as it would
have the first time. Every filter applies together. A row that dispatches is marked
processed; one that throws again has the failure recorded again, and the command
exits non-zero.

Each dispatch runs under a lease on the row (`leased_until`, ten minutes), taken by
the claim for the original request and by one conditional `UPDATE` for a retry, so
two retry runs, or a retry racing the original request, never dispatch a row twice.
A request that died before dispatching leaves a row that is due once its lease
lapses. A synchronous listener that runs past the lease can be overtaken by a retry,
which is one more reason to queue your own work.

On the `table` driver the lease is a cache lock (`tmesis:delivery-lease:{id}`, the
same ten minutes) on the dedupe cache store, since the table has no column for it,
and a retried delivery reaches the listener with no stored headers: `delivery()` has
the node, delivery id, event and raw body, a null `attempt`, an empty `signature`,
and `timestamp` taken from the body's `occurred_at` (from `created_at` when that is
missing). The signature was checked at receipt; nothing in this package reads those
three on the way to a listener.

`forget()` on the deduper is still there for replaying by hand; the receiver never
calls it.

## Rejections

Authentication failures are the signature middleware's `401`s, always with
`{"error":{"code":"..."}}`. The code is one of `bad_timestamp`, `missing_signature`,
`bad_signature`, or `stale_timestamp`. An unknown node answers `bad_signature`, so an
unauthenticated caller cannot enumerate the node names you have configured; the log
line says `unknown_node`. A node the resolver no longer knows is unknown the same way.

The one exception to `401` is a node that cannot be LOOKED UP (the node resolver
threw, `nodes_resolver` is misconfigured, or the node has no webhook secret): `503`
with `node_lookup_failed`, so the node retries once the store is back. See Many nodes.
A delivery-store failure before the `204` (the claim cannot insert) is a `500`, which
the node retries as well.

## Durability of the delivery store

`webhooks.dedupe.driver` defaults to `database`, and that is a correctness choice
rather than a preference. The node is at-least-once and retries: a dedupe store that
forgets on restart re-dispatches every delivery the node retries after the restart,
so every listener runs twice for those. `recording.registered` is where a double
dispatch costs most (an asset attached twice). The node itself never reads your
response to that event, and since `v0.5.0` registration never deletes anything: the
local copy goes only after an explicit release (see "Routing and releasing
artifacts"). The store protects your listeners, not the node's file.

Use `cache` when you accept that, which in practice means tests:

```php
config()->set('tmesis.webhooks.dedupe.driver', 'cache');
config()->set('tmesis.webhooks.dedupe.cache_store', 'array');
```

The `cache` driver keeps only the id, so it cannot retry: a failed dispatch there keeps
its claim (a late duplicate is still not dispatched twice), is reported and logged,
and is otherwise gone. `tmesis:webhooks:retry` says so and exits non-zero rather than
pretend. The database row is the only copy of an acknowledged delivery, and a cache
is no place for that.

`tmesis:doctor` warns whenever the store is not durable, and names this as the reason.
It also counts rows claimed over 24 hours ago and never processed, and points at
`tmesis:webhooks:retry`. `prune()` never removes an unprocessed row, at any age.

**Schedule `tmesis:webhooks:prune` daily on the database driver.** Nothing else calls
`prune()`, so without it every processed row (signed body included) is kept forever.
It deletes processed rows stored more than `retention_hours` ago (at least
`fleet.tombstone_days` while the fleet is on, so an id the other channel may still
deliver stays known), never an unprocessed one, and prints the count. The `cache`
driver expires its own ids and the `table` driver never deletes from your table, so
both report 0. `tmesis:doctor` warns when processed rows past retention are waiting.

### The `table` driver: your own webhooks table as the replay log

An app that already logs every inbound webhook in one shared table can keep Tmesis
deliveries there instead of in `tmesis_webhook_deliveries`. The two jobs split:

- **Duplicates are caught in the cache.** The claim is an atomic `add()` of
  `tmesis:delivery:{id}` on `webhooks.dedupe.cache_store`, for the retention window.
  A shared table with no unique key on the delivery cannot be the dedupe, so without
  the optional delivery column below **the cache is the only dedupe**: a key lost
  inside the window (`cache:clear` on a deploy, an eviction under memory pressure)
  turns the next sight of that delivery (a node retry after a timeout, or the
  fleet's second channel) into a second dispatch of every listener.
- **The table is the durable replay log.** The claim inserts one row: `source`, the
  raw signed body as `payload`, `created_at` and `updated_at`, `processed_at` null.
  The new row's primary key goes into the cache under the same key, so marking it
  processed is an update by primary key, never a scan of a large table. If the key
  has expired or was lost, the fallback is a query on `source` and
  `payload->delivery`, which is slow without an index and only there so no row is
  orphaned. If the insert fails, the cache key is removed and the error propagates:
  the claim runs inside the request, before the `204`, so the node gets a `500` and
  its retry is treated as new.

For a table shaped like this, the defaults already match and the whole
setup is two variables:

```sql
CREATE TABLE `webhooks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(255) NOT NULL,
  `payload` json DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `reprocessed_at` timestamp NULL DEFAULT NULL,
  `reprocessing_queued` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
);
```

```dotenv
TMESIS_DEDUPE_DRIVER=table
TMESIS_DEDUPE_CACHE_STORE=redis
# TMESIS_DEDUPE_TABLE=webhooks             (the default)
# TMESIS_DEDUPE_TABLE_SOURCE=tmesis        (the default)
# TMESIS_DEDUPE_TABLE_CONNECTION=          (the default connection)
```

Column names that differ go in `webhooks.dedupe.table.columns` in the published
config; set `reprocessed_at` to `null` when the table has no such column. A blank
required column is refused when the store is built. What the driver does and does not
do to that table:

- Every read, count and update is scoped to `source = tmesis`; other sources' rows
  are never touched. It writes only the mapped columns: never `reprocessing_queued`
  or `deleted_at`.
- **Your own reprocessor must skip `source = tmesis` rows**, or it will pick up
  payloads it does not understand. Replaying them is `tmesis:webhooks:retry`'s job:
  it reads this source's rows with a null `processed_at`, oldest first, filters
  `--id` and `--node` through the payload's `delivery` and `node`, and on success
  sets `processed_at` and `reprocessed_at` together.
- A failed dispatch has no column to land in. It is reported and logged by the
  dispatcher; the row keeps a null `processed_at` and gets a fresh `updated_at`.
- `prune()` deletes nothing: the table's retention is yours.
- MySQL may normalise the stored JSON (whitespace, key order). That is fine for a
  replay: the signature was verified when the delivery arrived, and objects stay
  objects.
- The doctor checks the table exists, every configured column is on it (the
  delivery column included, when set, along with its own unique index), and the
  cache store supports `add` and locks, without writing a row. Its count of stale
  unprocessed rows filters on `source` and `processed_at`; if that is slow, add an
  index on `(source, processed_at)` in your app.
- Durable only when the cache store's driver is `redis`, `database`, `memcached` or
  `dynamodb`; on `array` or `file` the doctor warns, as it does for the `cache`
  driver.

#### The delivery column: a backstop under the cache (package `0.8.3`)

Set `webhooks.dedupe.table.columns.delivery` to a column on your table and the claim
writes the delivery id into it. Give that column a unique index of its own, and a
second sight whose cache key was lost is a unique violation: the claim answers
"duplicate", dispatches nothing, writes no second row, and puts the existing row's id
back under the cache key so the next sight stops at the cache again. A claim that is
in the cache never touches the table, and a first sight (which always misses the
cache) costs no lookup at all: the unique index does the work inside the insert.

```php
// database/migrations/xxxx_add_tmesis_delivery_to_webhooks_table.php
Schema::table('webhooks', function (Blueprint $table) {
    // Null on every other source's row; unique indexes allow many nulls.
    $table->string('tmesis_delivery', 64)->nullable()->unique();
});
```

```php
// config/tmesis.php (published)
'columns' => [
    // ... the defaults ...
    'delivery' => 'tmesis_delivery',
],
```

It is a plain column the package fills, not a generated column over
`payload->delivery`, on purpose. A generated column would extract `delivery` from
EVERY source's payload, so another provider's body that happens to carry a
`delivery` key could collide with a Tmesis id and fail that provider's insert; the
expression syntax also differs between MySQL, PostgreSQL and SQLite. A plain column is
written only on this source's rows, and is the same migration everywhere. Rows stored
before the column existed hold `NULL` there and keep the cache as their only dedupe
until they age out of the window.

A hand replay through the deduper's `forget()` clears the id from that column on this
source's row (the row stays), so the replay's claim is new again and writes a second
row; the forgotten row is then reachable by its payload only.

The fleet REQUIRES this column on the `table` driver: it delivers every event over two
channels, the heartbeat and the webhook, so a second sight is normal traffic there
and enabling the fleet without it is refused at boot, naming
`tmesis.webhooks.dedupe.table.columns.delivery`.

**Listeners must be idempotent either way.** The column closes the lost-key gap; it
does not make delivery exactly-once. A dispatch that failed is replayed by
`tmesis:webhooks:retry`, and a listener that ran halfway before throwing runs again.

Against a table like this one, three things to know before switching it on. `payload`
need not be a `json` column: the driver writes the raw signed body as a string and
`json_decode`s it on replay, so a `text` column the app's model casts to `array` works
the same (the `--id` and `--node` filters use MySQL's JSON path functions, which parse
JSON held in `text`). `reprocessing_queued` and `deleted_at` are never read or written,
so the app's "reprocess" button and its soft deletes mean nothing to a Tmesis row.
And the app's own reprocessor must filter out `source = tmesis` before it dispatches
anything, because those payloads are Tmesis deliveries it cannot handle; replaying
them is `tmesis:webhooks:retry`'s job.
