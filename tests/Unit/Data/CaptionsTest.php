<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\CaptionSessionRecord;
use AlexHackney\Tmesis\Data\Streams\CaptionsRecord;
use AlexHackney\Tmesis\Data\Streams\Stats\CaptionSnapshot;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Data\Streams\StreamStats;
use AlexHackney\Tmesis\Data\Webhooks\CaptionPlayback;
use AlexHackney\Tmesis\Data\Webhooks\StreamPlayback;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

/**
 * @return array<string, mixed>
 */
function openApiComponent(string $name): array
{
    /** @var array{components: array{schemas: array<string, array<string, mixed>>}} $document */
    $document = json_decode(
        (string) file_get_contents(ContractDir::root().'/docs/contract/openapi.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $document['components']['schemas'][$name];
}

function assertComponent(string $component, mixed $body): void
{
    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode($body), false),
        '/components/schemas/'.$component,
        "The unit test {$component}",
    );
}

/**
 * @return array<string, mixed>
 */
function captionSession(): array
{
    return [
        'state' => 'live',
        'provider' => 'deepgram',
        'fallback' => false,
        'provider_request_id' => '6f1c2a8e-4b3d-4e5f-9a7b-8c9d0e1f2a3b',
        'started_at' => '2026-08-28T19:12:16.226Z',
        'finals' => 412,
        'dropped_pcm_ms' => 20,
        'reconnects' => 1,
        'reason' => null,
    ];
}

it('reads the effective captions record, with no session without include=captions', function (): void {
    $body = ['enabled' => true, 'advertise' => false, 'provider' => 'deepgram', 'languages' => ['en-US'], 'keyterms' => ['Ilia Topuria', 'Atlanta']];
    assertComponent('CaptionsRecord', $body);

    $record = CaptionsRecord::fromArray($body);

    expect($record->enabled())->toBeTrue()
        ->and($record->advertise())->toBeFalse()
        ->and($record->provider())->toBe('deepgram')
        ->and($record->languages())->toBe(['en-US'])
        // Required since v0.8.0: the stored, normalised list.
        ->and($record->keyterms())->toBe(['Ilia Topuria', 'Atlanta'])
        ->and(CaptionsRecord::fromArray([])->keyterms())->toBe([])
        // The key is absent, never null, without the token.
        ->and($record->session())->toBeNull()
        ->and($record->toArray())->toBe($body);
});

it('reads the nested session when the read sent include=captions', function (): void {
    $body = ['enabled' => true, 'advertise' => true, 'provider' => 'deepgram', 'languages' => ['en'], 'keyterms' => [], 'session' => captionSession()];
    assertComponent('CaptionsRecord', $body);

    $session = CaptionsRecord::fromArray($body)->session();

    expect($session)->toBeInstanceOf(CaptionSessionRecord::class)
        ->and($session?->state())->toBe(CaptionSessionRecord::STATE_LIVE)
        ->and($session?->provider())->toBe('deepgram')
        ->and($session?->fallback())->toBeFalse()
        ->and($session?->providerRequestId())->toBe('6f1c2a8e-4b3d-4e5f-9a7b-8c9d0e1f2a3b')
        ->and($session?->startedAtRaw())->toBe('2026-08-28T19:12:16.226Z')
        ->and($session?->startedAt()?->format('H:i:s.v'))->toBe('19:12:16.226')
        ->and($session?->finals())->toBe(412)
        ->and($session?->droppedPcmMs())->toBe(20)
        ->and($session?->reconnects())->toBe(1)
        ->and($session?->reason())->toBeNull();
});

it('reads an idle session, where the id, the start and the reason are null', function (): void {
    $body = ['state' => 'idle', 'provider' => null, 'fallback' => null, 'provider_request_id' => null, 'started_at' => null, 'finals' => 0, 'dropped_pcm_ms' => 0, 'reconnects' => 0, 'reason' => null];
    assertComponent('CaptionSessionRecord', $body);

    $session = CaptionSessionRecord::fromArray($body);

    expect($session->state())->toBe(CaptionSessionRecord::STATE_IDLE)
        ->and($session->provider())->toBeNull()
        ->and($session->fallback())->toBeNull()
        ->and($session->providerRequestId())->toBeNull()
        ->and($session->startedAtRaw())->toBeNull()
        ->and($session->startedAt())->toBeNull()
        ->and($session->reason())->toBeNull();
});

it('reads session provider and fallback only when their raw types match', function (): void {
    $session = CaptionSessionRecord::fromArray(['provider' => 42, 'fallback' => 'false']);

    expect($session->provider())->toBeNull()
        ->and($session->fallback())->toBeNull();
});

it('reads a degraded session\'s reason', function (): void {
    $body = ['state' => 'degraded', 'reason' => 'rate_limited'] + captionSession();
    assertComponent('CaptionSessionRecord', $body);

    expect(CaptionSessionRecord::fromArray($body)->reason())->toBe('rate_limited');
});

it('holds the session record\'s closed state set', function (): void {
    expect(CaptionSessionRecord::STATES)->toBe(openApiComponent('CaptionSessionRecord')['properties']['state']['enum']);
});

it('reads the stats snapshot, a different shape from the session record', function (): void {
    $body = [
        'state' => 'live',
        'provider' => 'deepgram',
        'request_id' => '8f1c2a7e-5b3d-4e9a-9c1f-2d7b6a4e0c31',
        'finals' => 412,
        'dropped_pcm_ms' => 0,
        'reconnects' => 1,
        'reason' => '',
    ];
    assertComponent('CaptionSnapshot', $body);

    $snapshot = CaptionSnapshot::fromArray($body);

    expect($snapshot->state())->toBe(CaptionSnapshot::STATE_LIVE)
        ->and($snapshot->provider())->toBe('deepgram')
        ->and($snapshot->requestId())->toBe('8f1c2a7e-5b3d-4e9a-9c1f-2d7b6a4e0c31')
        ->and($snapshot->finals())->toBe(412)
        ->and($snapshot->droppedPcmMs())->toBe(0)
        ->and($snapshot->reconnects())->toBe(1)
        ->and($snapshot->reason())->toBe('')
        ->and($snapshot->toArray())->toBe($body);
});

it('keeps the two caption shapes apart, as the artifact does (F1)', function (): void {
    // The session record's body is NOT a valid snapshot: request_id spelled
    // provider_request_id, nullable fields where the snapshot has none.
    expect(SchemaValidator::forOpenApi()->isValid(
        json_decode((string) json_encode(captionSession()), false),
        '/components/schemas/CaptionSnapshot',
    ))->toBeFalse()
        // And the snapshot never reads idle.
        ->and(CaptionSnapshot::STATES)->toBe(openApiComponent('CaptionSnapshot')['properties']['state']['enum'])
        ->and(CaptionSnapshot::STATES)->not->toContain(CaptionSessionRecord::STATE_IDLE);
});

it('decodes captions on the record and the stats body', function (): void {
    $record = StreamRecord::fromArray(['captions' => ['enabled' => false, 'advertise' => true, 'provider' => 'none', 'languages' => ['en']]]);

    expect($record->captions())->toBeInstanceOf(CaptionsRecord::class)
        ->and($record->captions()->provider())->toBe('none')
        ->and($record->captions()->enabled())->toBeFalse()
        // Required on the record; a body without it still reads, as an empty record.
        ->and(StreamRecord::fromArray([])->captions()->languages())->toBe([]);

    // Required and nullable on the stats body: null until a captioner has reported.
    expect(StreamStats::fromArray(['captions' => null])->captions())->toBeNull()
        ->and(StreamStats::fromArray(['captions' => ['state' => 'ended', 'reason' => 'stream_ended']])->captions()?->reason())
        ->toBe('stream_ended');
});

it('reads one language\'s caption playback on the webhook playback shape', function (): void {
    $playback = StreamPlayback::fromArray([
        'master' => 'https://cdn.example.com/hls/s/playlist.m3u8',
        'renditions' => [],
        'preview' => null,
        'captions' => [
            'en' => ['playlist' => 'https://cdn.example.com/hls/s/captions_en.m3u8', 'vtt' => 'https://cdn.example.com/hls/s/captions/en/transcript.vtt', 'srt' => null, 'json' => 'https://cdn.example.com/hls/s/captions/en/transcript.json'],
            'es' => ['playlist' => 'https://cdn.example.com/hls/s/captions_es.m3u8', 'vtt' => null, 'srt' => null, 'json' => null],
        ],
    ]);

    $captions = $playback->captions();

    expect($captions)->toHaveKeys(['en', 'es'])
        ->and($captions['en'] ?? null)->toBeInstanceOf(CaptionPlayback::class)
        ->and($captions['en']->playlist())->toEndWith('captions_en.m3u8')
        ->and($captions['en']->vtt())->toEndWith('transcript.vtt')
        // A sidecar the sink refused stays null rather than a URL that 404s.
        ->and($captions['en']->srt())->toBeNull()
        ->and($captions['en']->json())->toEndWith('transcript.json')
        ->and($captions['es']->toArray()['vtt'])->toBeNull()
        ->and(StreamPlayback::fromArray(['captions' => null])->captions())->toBeNull()
        // Tolerated, not required: a pre-v0.3.0 body has no key at all.
        ->and(StreamPlayback::fromArray([])->captions())->toBeNull()
        ->and(StreamPlayback::fromArray(['captions' => []])->captions())->toBe([]);
});
