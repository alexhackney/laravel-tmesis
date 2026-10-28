# Transcode jobs

Since tmesis `v0.6.0` a transcoder (or an origin by day) runs VOD transcode jobs: the
**push model**. Submit a source and where each output goes, then hear about it in
webhooks; `getTranscodeJob()` is the read for a missed webhook, not a polling loop.

```php
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Data\Jobs\ThumbnailOpts;
use AlexHackney\Tmesis\Facades\Tmesis;

$job = Spec::make(JobSource::destinationRef('media', 'uploads/abc.mov'), idempotencyKey: $request->id)
    ->ladder('vod-1080p')
    ->target(
        JobTarget::hlsLadder('media', 'videos/941'),        // playlist.m3u8, rungs, captions under it
        JobTarget::audio('media', 'videos/941/audio.m4a'),  // one file: a key
        JobTarget::thumbnails('assets', 'thumbs/941'),      // its own bucket, its own target
    )
    ->thumbnails(ThumbnailOpts::make(intervalSeconds: 10, width: 640))
    ->callbackUrl(route('tmesis.webhooks.receive'))
    ->labels(['video_id' => '941']);

$accepted = Tmesis::node('transcode-atl-04')->transcodeJobs()->createTranscodeJob($job);
$accepted->created;   // true on 202 (queued now), false on 200 (a known key: the existing job)
```

- **`idempotency_key` is the retry handle, and it is required here.** Mint it from your
  own record (the transcode request's id), keep it, and resend the SAME `Spec` after a
  timeout: a known key answers `200` with the existing job, a completed one included,
  and queues nothing. The package never mints one for you.
- **Outputs come from targets.** `JobTarget::hlsLadder()`, `mp4()`, `thumbnails()` and
  `transcript()` take a `prefix`; `audio()` and `probe()` a `key`; each takes a
  `destination_ref` configured on the node or a `JobSignedTarget` your signer answers
  for. Every target names its output, so an output without a target (or the reverse)
  cannot be built. `hls_ladder` and `mp4` need `ladder()`. A presigned
  `JobSource::url()` is a credential: log `Spec::toRedactedArray()`, never `toArray()`.
- **You are the single scheduler per output prefix** (API.md 9.10). The node refuses
  `409 destination_busy` for a prefix a job or live stream on THAT node writes; it
  cannot see another node. Submit a prefix to one node at a time.
- **`callback_url` routes the job's `job.*` events AND its generations' `artifact.*`
  and `transfer.*` events**; without it they go to the node's `webhooks.url`.
- **Live work always wins.** A live publish evicts a running job back to `queued`
  (`JobQueued` `reason` `live_preempted`); evictions never fail a job, only `attempts`
  counts toward `jobs.max_attempts`. `getHealth()->jobs()->slots()` is how many jobs a
  box runs at once, and `hasFreeSlot()` is `running < slots`.
- **`503 encoder_unavailable` is the one `503` not to retry on that node** (tmesis
  `v0.9.0`): the box's pinned jobs encoder failed at boot, and it does not clear by
  waiting. It throws `EncoderUnavailableException`, a subclass of
  `UnavailableException`, so catch it FIRST and send the job to another box; queued
  jobs there stay queued and are never claimed.
- `501` on the POST (`NotImplementedException`) means the build has no job runner;
  `409 role_unsupported` an edge or `jobs.enabled` off; `429 queue_full`; the `422`
  codes `source_not_supported`, `output_not_supported`, `destination_not_supported`.

The five events are `JobQueued` (`reason()`, `attempts()`, `evictions()`), `JobStarted`
(`attempt()`, `backend()`), `JobProgress` (`outputs()` holds only what already
LANDED, so an early audio file arrives before the ladder), `JobCompleted`
(`encodeSeconds()`, `probe()`, `outputs()`) and `JobFailed`. They carry `job()`, not a
stream. **A cancel is `JobFailed` with `isCancelled()`** (the v1 set has no
`job.cancelled`): do not alert on it. `JobFailed::landed()` lists what had already
transferred; the node never deletes remotely, so removing those is yours.

`JobCompleted` is the payload for your completion service: every required output
landed, each `outputs()` entry naming its `generation()` and `url()`; an optional one
(`audio`, `thumbnails`, `transcript`) may carry an `error()` while the job completes.

**Nothing auto-releases.** Each output is a generation held on the transcoder until
you release it, exactly as a stream's are ([Routing and releasing
artifacts](./artifacts.md#routing-and-releasing-artifacts)). Release on each generation's
`transfer.completed`, which carries what the release names (`job.completed` does not:
its `outputs()` name each generation but no plan revision or inventory sha):

```php
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Events\TransferCompleted;
use AlexHackney\Tmesis\Facades\Tmesis;

Event::listen(TransferCompleted::class, function (TransferCompleted $event): void {
    $identity = $event->identity();

    if (! $identity->isJobGeneration()) {
        return;   // a stream generation: release it through artifacts()
    }

    Tmesis::node($event->node())->jobArtifacts()->releaseJobArtifact(
        $identity->jobId(), $identity->generation(),
        ReleaseRequest::make($event->planRevision(), $identity->inventorySha256(), 'copied'),
    );
});
```

Do it for every job generation you copied, or the transcoder's disk fills. The node filed
your targets as each generation's revision 1, so a release names revision 1 unless
you re-planned with `replaceJobTransferPlan()`. `cancelTranscodeJob()` supersedes the
job's plans and discards its held generations, but a FINALIZED one still waits for a
release like any other.

## Pausing jobs on a standalone box

On a box that is not in a fleet (tmesis `v0.9.0`, API.md 9.12a):

```php
use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;

$jobs = Tmesis::node('transcode-atl-04')->transcodeJobs();
$jobs->setTranscodePause(JobPauseRequest::make('show: fight night 12', now()->addHours(4)));
$jobs->getTranscodePause()->paused();   // exactly {paused: false} when none is active
$jobs->clearTranscodePause();           // 204, idempotent; queued jobs are claimed at once
```

A `PUT` replaces any pause in place (no second `jobs.paused`); a past `until` is stored
and reads `paused: false`. An ENROLLED box answers both writes `409 fleet_owned`
(`FleetOwnedException`, `ownedPaths()` names `jobs_pause`): its pause is the fleet's
desired state ([What each node should run](./fleet.md#what-each-node-should-run)). The same
exception now covers the stream and relay routes' `fleet_owned` (tmesis `v0.7.0`), which
earlier builds threw as a plain `ConflictException` (still its parent).
