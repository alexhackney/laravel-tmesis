<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Webhooks\CaptionPlayback;
use AlexHackney\Tmesis\Data\Webhooks\RelayDestination;
use AlexHackney\Tmesis\Events\CaptionSessionEnded;
use AlexHackney\Tmesis\Events\CaptionSessionStarted;
use AlexHackney\Tmesis\Events\LeaseFenced;
use AlexHackney\Tmesis\Events\NodeOnline;
use AlexHackney\Tmesis\Events\RecordingCompleted;
use AlexHackney\Tmesis\Events\RelayEvent;
use AlexHackney\Tmesis\Events\RelayFailed;
use AlexHackney\Tmesis\Events\StreamLive;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Events\StreamSealed;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\SessionEpoch;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Webhooks\EventMap;

it('reads the envelope', function (): void {
    $payload = $this->fixtureJson('contract/docs/contract/examples/stream.published.json');
    $event = EventMap::make($payload);

    expect($event->event())->toBe('stream.published')
        ->and($event->deliveryId())->toBe('01J8ZC9GQ2M4YE7XW1RN0P6VTB')
        ->and($event->occurredAtRaw())->toBe('2026-08-28T19:12:01.482Z')
        ->and($event->occurredAt()->format('u'))->toBe('482000')
        ->and($event->node())->toBe('origin-atl-01')
        ->and($event->nodeRole())->toBe(TmesisWebhookReceived::ROLE_ORIGIN)
        ->and($event->contractVersion())->toBe(1)
        ->and($event->stream())->toBe('show-2041-main')
        ->and($event->labels())->toBe(['show_id' => '2041', 'site' => 'csn'])
        ->and($event->delivery())->toBeNull()
        ->and($event->isKnownEvent())->toBeTrue();
});

it('has no stream and no labels on a node event', function (): void {
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/node.online.json'));

    expect($event)->toBeInstanceOf(NodeOnline::class)
        ->and($event->stream())->toBeNull()
        ->and($event->labels())->toBe([]);

    /** @var NodeOnline $event */
    expect($event->version())->toBe('1.4.0')
        ->and($event->configSource())->toBe('search_path')
        ->and($event->orphansReaped())->toBe(0)
        ->and($event->capacity()->encodeSlotsTotal())->toBe(6)
        ->and($event->listeners()->srt())->toBe(':6001')
        ->and($event->startedAt()->format('Y'))->toBe('2026');
});

it('carries a null stream when the key was unparseable', function (): void {
    $payload = $this->fixtureJson('contract/docs/contract/examples/stream.publish_rejected.json');
    $payload['stream'] = null;

    $event = EventMap::make($payload);

    expect($event->stream())->toBeNull()
        // stream.publish_rejected never carries labels: there may be no stream to
        // read them from.
        ->and($event->labels())->toBe([]);
});

it('types the two epochs on stream.published differently', function (): void {
    /** @var StreamPublished $event */
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/stream.published.json'));

    expect($event->epoch())->toBe(3)
        ->and($event->sessionEpoch())->toBeInstanceOf(SessionEpoch::class)
        ->and($event->sessionEpoch()->value)->toBe(3)
        // The KEY epoch is a different number and a different type.
        ->and($event->auth()->keyEpoch())->toBeInstanceOf(KeyEpoch::class)
        ->and($event->auth()->keyEpoch()->value)->toBe(7)
        ->and($event->auth()->dualAccept())->toBeFalse()
        ->and($event->auth()->keyType())->toBe(StreamPublished::PROTOCOL_SRT === 'srt' ? 'signed' : '')
        ->and($event->takeover())->toBeFalse()
        ->and($event->role())->toBe(StreamPublished::ROLE_PRIMARY);
});

it('always returns null for retention_expires_at on recording.completed', function (): void {
    /** @var RecordingCompleted $event */
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/recording.completed.json'));

    // The schema pins it with const null: retention is decided at registration.
    expect($event->retentionExpiresAt())->toBeNull()
        ->and($event->retentionExpiresAtRaw())->toBeNull()
        // The generated example is the ingest artifact the node emits; the WEBHOOKS.md
        // 8.13 fence this test read until v0.3.1 showed a master one (M4).
        ->and($event->artifact())->toBe(RecordingCompleted::ARTIFACT_INGEST)
        // An absolute path since v0.4.0: the examples moved off s3:// with Destination
        // WP1, and the node refuses the URL form on the API now.
        ->and($event->destination())->toBe('/mnt/archive/ingest')
        // parts is always 1: the schema pins it with const: 1 since tmesis v0.2.0
        // step 1, and a rejoin never raises it (final plan 11.38; a rejoin after a
        // hold opens a new row rather than a longer file).
        ->and($event->parts())->toBe(1)
        ->and($event->durationSeconds())->toBe(9138.6);
});

it('shares one payload shape across the five relay events', function (): void {
    /** @var RelayFailed $event */
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/relay.failed.json'));

    expect($event->destination()->id())->toBe('static-0')
        ->and($event->destination()->state())->toBe('failed')
        ->and($event->destination()->reconnects())->toBe(2)
        // Already redacted upstream, and redacted again on the way in.
        ->and($event->destination()->url())->toBe('srt://origin-atl-01.internal:6001')
        ->and($event->reason())->toBe('the destination closed the connection');

    foreach (['relay.started', 'relay.degraded', 'relay.reconnected', 'relay.stopped'] as $name) {
        $payload = $this->fixtureJson("contract/docs/contract/examples/{$name}.json");

        /** @var RelayEvent $relay */
        $relay = EventMap::make($payload);

        expect($relay)->toBeInstanceOf(RelayEvent::class)
            // The resolved feed rides on every relay.* destination since v0.4.0.
            ->and($relay->destination()->feed())->toBe('ingest');

        // The node ALWAYS sends connecting on relay.started and relay.reconnected:
        // connected is promoted on the first successful write and no event reports
        // it (internal/relay/relay.go leg.run). The v0.3.0 generated examples said
        // connected (hand-typed; tmesis confirmed it), and v0.3.1
        // overrode the value here in memory. The v0.4.0 examples carry connecting
        // themselves, so the example is read as it is and the override is gone.
        if (in_array($name, ['relay.started', 'relay.reconnected'], true)) {
            expect($relay->destination()->state())->toBe(RelayDestination::STATE_CONNECTING);
        }
    }
});

it('reads lease.fenced with the fencing epochs typed and the incident causes named', function (): void {
    /** @var LeaseFenced $event */
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/lease.fenced.json'));

    expect($event)->toBeInstanceOf(LeaseFenced::class)
        ->and($event->stream())->toBe('show-2041-main')
        ->and($event->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($event->prefix())->toBe('live/show-2041-main')
        ->and($event->cause())->toBe(LeaseFenced::CAUSE_SUPERSEDED)
        ->and($event->isSuperseded())->toBeTrue()
        // The FENCING epoch, the third thing called epoch: typed as such and as
        // nothing else. superseded means observed is above held.
        ->and($event->heldEpoch())->toBe(11)
        ->and($event->heldEpochValue()->toString())->toBe('11')
        ->and($event->observedEpoch())->toBe(12)
        ->and($event->observedEpochValue()->toString())->toBe('12')
        // On one box the new holder is usually this node: a takeover fences its
        // own predecessor.
        ->and($event->newHolder())->toBe('origin-atl-01')
        ->and($event->writesStoppedAtRaw())->toBe('2026-08-28T19:11:58.61Z')
        // The node stops first and tells afterwards.
        ->and($event->writesStoppedAt() <= $event->occurredAt())->toBeTrue()
        ->and($event->abandonedUploads())->toBe(4)
        ->and($event->labels())->toBe(['show_id' => '2041', 'site' => 'csn']);

    // The three incident causes, and a released prefix with no holder.
    $incident = EventMap::make(array_replace(
        $this->fixtureJson('contract/docs/contract/examples/lease.fenced.json'),
        ['cause' => LeaseFenced::CAUSE_MISSING, 'observed_epoch' => 11, 'new_holder' => null, 'session' => ''],
    ));

    expect($incident)->toBeInstanceOf(LeaseFenced::class)
        ->and($incident->isSuperseded())->toBeFalse()
        ->and($incident->newHolder())->toBeNull()
        // Empty for a crash-recovery seal, which belongs to no running session.
        ->and($incident->session())->toBe('');
});

it('reads the preview monitor on stream.live', function (): void {
    /** @var StreamLive $event */
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/stream.live.json'));

    $preview = $event->playback()->preview();

    expect($preview?->whepPath())->toBe('/whep/v1/streams/show-2041-main')
        ->and($preview?->whepUrl())->toBe('https://box.example.com:8889/whep/v1/streams/show-2041-main');

    // Null when the session runs no tap; whep_url null until preview.public_url is set.
    $payload = $this->fixtureJson('contract/docs/contract/examples/stream.live.json');
    $payload['playback']['preview'] = null;

    /** @var StreamLive $noTap */
    $noTap = EventMap::make($payload);

    expect($noTap->playback()->preview())->toBeNull();

    $payload['playback']['preview'] = ['whep_path' => '/whep/v1/streams/show-2041-main', 'whep_url' => null];

    /** @var StreamLive $noPublicUrl */
    $noPublicUrl = EventMap::make($payload);

    expect($noPublicUrl->playback()->preview()?->whepUrl())->toBeNull();
});

/**
 * The `vod` of `docs/WEBHOOKS.md` 8.9's fence at tmesis `v0.8.0`: the replay at the live
 * URLs. The generated `stream.sealed` and `caption.session_ended` examples are STALE at
 * that tag (handover section 4: they still name `vod.m3u8`, `<rung>_vod.m3u8` and
 * `captions_en_vod.m3u8`, and validate only because the schema types the URLs as plain
 * strings), so no assertion reads a URL off them; the URL checks read this.
 *
 * @return array<string, mixed>
 */
function sealedVodFence(): array
{
    return [
        'master' => 'https://cdn.example.com/hls/show-2041-main/playlist.m3u8',
        'renditions' => [
            '720p' => 'https://cdn.example.com/hls/show-2041-main/720p.m3u8',
            '480p' => 'https://cdn.example.com/hls/show-2041-main/480p.m3u8',
            'audio' => 'https://cdn.example.com/hls/show-2041-main/audio.m3u8',
        ],
        'preview' => null,
        'captions' => [
            'en' => [
                'playlist' => 'https://cdn.example.com/hls/show-2041-main/captions_en.m3u8',
                'vtt' => 'https://cdn.example.com/hls/show-2041-main/captions/en/transcript.vtt',
                'srt' => 'https://cdn.example.com/hls/show-2041-main/captions/en/transcript.srt',
                'json' => 'https://cdn.example.com/hls/show-2041-main/captions/en/transcript.json',
            ],
        ],
    ];
}

it('reads the sealed replay', function (): void {
    $payload = $this->fixtureJson('contract/docs/contract/examples/stream.sealed.json');
    $payload['vod'] = sealedVodFence();

    /** @var StreamSealed $event */
    $event = EventMap::make($payload);

    // Since v0.8.0 the final seal ends the live playlists in place: the replay IS the
    // URL stream.live advertised, and the renditions are their own <rung>.m3u8.
    expect($event->vod()->master())->toEndWith('/show-2041-main/playlist.m3u8')
        ->and($event->vod()->renditions())->toHaveKey('720p')
        ->and($event->vod()->renditions()['720p'] ?? null)->toEndWith('/720p.m3u8')
        // ALWAYS null on stream.sealed: vod carries the key only because the two
        // events share the shape.
        ->and($event->vod()->preview())->toBeNull()
        // 1 is the only value this build emits (WEBHOOKS.md 8.9); the fixture is the fence.
        ->and($event->sessions())->toBe(1)
        ->and($event->firstProgramDateTime()?->format('H:i:s'))->toBe('19:12:08')
        // A string, not an enum: fallback_end joined at rc5, crash_recovered at v0.8.0.
        ->and($event->sealedBy())->toBe(StreamSealed::SEALED_BY_API_DELETE);

    $payload['sealed_by'] = StreamSealed::SEALED_BY_CRASH_RECOVERED;

    /** @var StreamSealed $recovered */
    $recovered = EventMap::make($payload);

    expect($recovered->sealedBy())->toBe('crash_recovered');
});

it('reads the DVR caption playlist on stream.live, with the sidecars null', function (): void {
    /** @var StreamLive $event */
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/stream.live.json'));

    $captions = $event->playback()->captions();

    expect($captions)->toHaveKeys(['en'])
        ->and($captions['en'] ?? null)->toBeInstanceOf(CaptionPlayback::class)
        ->and($captions['en']->playlist())->toEndWith('captions_en_dvr.m3u8')
        // Not sealed yet, so no sidecars.
        ->and($captions['en']->vtt())->toBeNull()
        ->and($captions['en']->srt())->toBeNull()
        ->and($captions['en']->json())->toBeNull();

    $payload = $this->fixtureJson('contract/docs/contract/examples/stream.live.json');
    $payload['playback']['captions'] = null;

    /** @var StreamLive $none */
    $none = EventMap::make($payload);

    // Null, not [], when the session has no caption rendition at all.
    expect($none->playback()->captions())->toBeNull();
});

it('reads the VOD caption playlist and the transcript sidecars on stream.sealed', function (): void {
    $payload = $this->fixtureJson('contract/docs/contract/examples/stream.sealed.json');
    $payload['vod'] = sealedVodFence();

    /** @var StreamSealed $event */
    $event = EventMap::make($payload);

    $en = $event->vod()->captions()['en'] ?? null;

    expect($en?->playlist())->toEndWith('/captions_en.m3u8')
        ->and($en?->vtt())->toEndWith('captions/en/transcript.vtt')
        ->and($en?->srt())->toEndWith('captions/en/transcript.srt')
        ->and($en?->json())->toEndWith('captions/en/transcript.json');
});

it('reads caption.session_started, with a null provider request id before the provider names the socket', function (): void {
    $payload = json_decode(file_get_contents(ContractDir::example('caption.session_started')), true, 512, JSON_THROW_ON_ERROR);

    /** @var CaptionSessionStarted $event */
    $event = EventMap::make($payload);

    expect($event)->toBeInstanceOf(CaptionSessionStarted::class)
        ->and($event->stream())->toBe('show-2041-main')
        ->and($event->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($event->epoch())->toBe(3)
        ->and($event->sessionEpoch())->toEqual(SessionEpoch::fromInt(3))
        ->and($event->lang())->toBe('en')
        ->and($event->provider())->toBe('deepgram')
        ->and($event->providerRequestId())->toBe('6f1c2a8e-4b3d-4e5f-9a7b-8c9d0e1f2a3b')
        ->and($event->resumedAfter())->toBeNull()
        ->and($event->isResume())->toBeFalse()
        ->and($event->labels())->toBe(['show_id' => '2041', 'site' => 'csn']);

    $payload['resumed_after'] = 'gave_up';
    /** @var CaptionSessionStarted $resumed */
    $resumed = EventMap::make($payload);
    expect($resumed->resumedAfter())->toBe('gave_up')->and($resumed->isResume())->toBeTrue();

    $payload['provider_request_id'] = null;

    /** @var CaptionSessionStarted $early */
    $early = EventMap::make($payload);

    expect($early->providerRequestId())->toBeNull();
});

it('reads caption.session_ended and its one artifacts entry', function (): void {
    $payload = $this->fixtureJson('contract/docs/contract/examples/caption.session_ended.json');

    /** @var CaptionSessionEnded $event */
    $event = EventMap::make($payload);

    expect($event)->toBeInstanceOf(CaptionSessionEnded::class)
        ->and($event->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($event->sessionEpoch())->toEqual(SessionEpoch::fromInt(3))
        ->and($event->lang())->toBe('en')
        ->and($event->provider())->toBe('deepgram')
        ->and($event->providerRequestId())->toBe('6f1c2a8e-4b3d-4e5f-9a7b-8c9d0e1f2a3b')
        ->and($event->reason())->toBe(CaptionSessionEnded::REASON_STREAM_ENDED)
        ->and($event->mayResume())->toBeFalse()
        ->and($event->detail())->toBeNull()
        ->and($event->finals())->toBe(8412)
        ->and($event->droppedPcmMs())->toBe(0)
        ->and($event->reconnects())->toBe(0)
        // The example's playlist is stale at v0.8.0 (handover section 4): no URL is
        // read off it, only that it is the one CaptionPlayback.
        ->and($event->artifacts()?->playlist())->toBeString()->not->toBe('')
        ->and($event->artifacts()?->json())->toEndWith('captions/en/transcript.json');

    $payload = array_replace($payload, [
        'reason' => 'auth_error',
        'detail' => 'INVALID_AUTH',
        'provider_request_id' => null,
        'artifacts' => null,
    ]);

    /** @var CaptionSessionEnded $refused */
    $refused = EventMap::make($payload);

    expect($refused->reason())->toBe(CaptionSessionEnded::REASON_AUTH_ERROR)
        ->and($refused->detail())->toBe('INVALID_AUTH')
        ->and($refused->providerRequestId())->toBeNull()
        ->and($refused->artifacts())->toBeNull();

    foreach (CaptionSessionEnded::ENDED_REASONS as $reason) {
        $payload['reason'] = $reason;
        /** @var CaptionSessionEnded $resumable */
        $resumable = EventMap::make($payload);
        expect($resumable->mayResume())->toBe(in_array($reason, CaptionSessionEnded::RESUMABLE_REASONS, true));
    }
});

it('throws naming the key when a required envelope field is missing', function (): void {
    $event = new TmesisWebhookReceived(['event' => 'stream.published']);

    expect(fn () => $event->occurredAt())->toThrow(RuntimeException::class, 'occurred_at');
});

it('reads caption failover accessors and preserves same-provider resume semantics', function (): void {
    $startBody = json_decode((string) file_get_contents(ContractDir::example('caption.session_started')), true, 512, JSON_THROW_ON_ERROR);
    $startBody['switched_from'] = 'deepgram';
    $startBody['fallback'] = true;
    /** @var CaptionSessionStarted $start */
    $start = EventMap::make($startBody);
    expect($start->switchedFrom())->toBe('deepgram')->and($start->isFallback())->toBeTrue()->and($start->isSwitch())->toBeTrue();

    $old = $startBody;
    unset($old['switched_from'], $old['fallback']);
    /** @var CaptionSessionStarted $oldStart */
    $oldStart = EventMap::make($old);
    expect($oldStart->switchedFrom())->toBeNull()->and($oldStart->isFallback())->toBeFalse()->and($oldStart->isSwitch())->toBeFalse();
    $oldStartPayload = $old;
    $oldStartPayload['fallback'] = 'true';
    /** @var CaptionSessionStarted $wrongType */
    $wrongType = EventMap::make($oldStartPayload);
    expect($wrongType->isFallback())->toBeFalse();
    $oldStartPayload['switched_from'] = 7;
    /** @var CaptionSessionStarted $wrongSwitch */
    $wrongSwitch = EventMap::make($oldStartPayload);
    expect($wrongSwitch->switchedFrom())->toBeNull()->and($wrongSwitch->isSwitch())->toBeFalse();

    $endBody = json_decode((string) file_get_contents(ContractDir::example('caption.session_ended')), true, 512, JSON_THROW_ON_ERROR);
    foreach (CaptionSessionEnded::ENDED_REASONS as $reason) {
        $endBody['reason'] = $reason;
        /** @var CaptionSessionEnded $ended */
        $ended = EventMap::make($endBody);
        expect($ended->captionsContinue())->toBe(in_array($reason, [...CaptionSessionEnded::RESUMABLE_REASONS, CaptionSessionEnded::REASON_PROVIDER_SWITCH], true));
    }
    $endBody['reason'] = CaptionSessionEnded::REASON_PROVIDER_SWITCH;
    /** @var CaptionSessionEnded $switched */
    $switched = EventMap::make($endBody);
    expect($switched->isProviderSwitch())->toBeTrue()->and($switched->mayResume())->toBeFalse();
});

it('reads the socket counts on caption.session_ended, and zero from a pre-0.9.3 node', function (): void {
    $payload = $this->fixtureJson('contract/docs/contract/examples/caption.session_ended.json');

    /** @var CaptionSessionEnded $event */
    $event = EventMap::make($payload);

    expect($event->sockets())->toBe(1)
        ->and($event->rotations())->toBe(0)
        ->and($event->reconnects())->toBe(0);

    // All three are cumulative over the stream's captioner, and none of the sockets
    // they count fires a caption.session_started (tmesis v0.9.3, C2 and C2b).
    $busy = array_replace($payload, ['reconnects' => 3, 'sockets' => 5, 'rotations' => 2]);

    /** @var CaptionSessionEnded $counted */
    $counted = EventMap::make($busy);

    expect($counted->reconnects())->toBe(3)
        ->and($counted->sockets())->toBe(5)
        ->and($counted->rotations())->toBe(2);

    // A pre-0.9.3 node sends neither key. int() reads an absent key as 0 rather than
    // throwing, which is what reconnects() has always done.
    unset($busy['sockets'], $busy['rotations']);

    /** @var CaptionSessionEnded $older */
    $older = EventMap::make($busy);

    expect($older->sockets())->toBe(0)->and($older->rotations())->toBe(0);
});

it('spells stalled, a close the node retries by itself, on both caption events', function (): void {
    expect(CaptionSessionEnded::REASON_STALLED)->toBe('stalled')
        ->and(CaptionSessionEnded::ENDED_REASONS)->toContain(CaptionSessionEnded::REASON_STALLED)
        ->and(CaptionSessionEnded::RESUMABLE_REASONS)->toContain(CaptionSessionEnded::REASON_STALLED);

    $payload = $this->fixtureJson('contract/docs/contract/examples/caption.session_ended.json');
    $payload = array_replace($payload, [
        'reason' => CaptionSessionEnded::REASON_STALLED, 'detail' => 'no answers for 60s', 'artifacts' => null,
    ]);

    /** @var CaptionSessionEnded $stalled */
    $stalled = EventMap::make($payload);

    expect($stalled->reason())->toBe(CaptionSessionEnded::REASON_STALLED)
        ->and($stalled->mayResume())->toBeTrue()
        ->and($stalled->captionsContinue())->toBeTrue()
        ->and($stalled->isProviderSwitch())->toBeFalse()
        ->and($stalled->detail())->toBe('no answers for 60s');

    // The resume it is followed by names the stall in resumed_after (C3).
    $start = $this->fixtureJson('contract/docs/contract/examples/caption.session_started.json');
    $start['resumed_after'] = CaptionSessionEnded::REASON_STALLED;

    /** @var CaptionSessionStarted $resumed */
    $resumed = EventMap::make($start);

    expect($resumed->resumedAfter())->toBe('stalled')->and($resumed->isResume())->toBeTrue();
});
