<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Relays\RelayLastSession;
use AlexHackney\Tmesis\Data\Relays\RelayRecord;

/**
 * @return array<string, mixed>
 */
function relayWire(): array
{
    return [
        'id' => 'origin', 'url' => 'srt://origin-atl-01.internal:8890?passphrase=hunter2', 'enabled' => true, 'source' => 'static',
        'state' => 'connected', 'bytes_out' => 10, 'dropped_bytes' => 0, 'reconnects' => 0, 'feed' => 'auto', 'feed_resolved' => 'ingest',
    ];
}

it('reads the three v0.10.0 relay fields', function (): void {
    $record = RelayRecord::fromArray(relayWire() + [
        'aliases' => ['yt-old', 'yt-older'],
        'last_session' => ['ended_at' => '2026-11-08T19:12:44.120Z', 'bytes_out' => 9245056000, 'dropped_bytes' => 4096, 'reconnects' => 2],
        'parked' => true,
    ]);

    expect($record->aliases())->toBe(['yt-old', 'yt-older'])
        ->and($record->parked())->toBeTrue()
        ->and($record->lastSession())->toBeInstanceOf(RelayLastSession::class)
        ->and($record->lastSession()?->endedAt()->format('Y-m-d H:i'))->toBe('2026-11-08 19:12')
        ->and($record->lastSession()?->bytesOut())->toBe(9245056000)
        ->and($record->lastSession()?->droppedBytes())->toBe(4096)
        ->and($record->lastSession()?->reconnects())->toBe(2)
        ->and($record->lastSession()?->toArray()['bytes_out'])->toBe(9245056000);
});

it('reads a record without them as no aliases, no last session and not parked', function (): void {
    $record = RelayRecord::fromArray(relayWire());

    expect($record->aliases())->toBe([])
        ->and($record->lastSession())->toBeNull()
        ->and($record->parked())->toBeFalse()
        ->and(RelayRecord::fromArray(relayWire() + ['aliases' => [], 'last_session' => null, 'parked' => false])->lastSession())->toBeNull();
});

it('ignores a non-string alias rather than failing', function (): void {
    expect(RelayRecord::fromArray(relayWire() + ['aliases' => ['yt-old', 7, null]])->aliases())->toBe(['yt-old']);
});

it('tells a parked leg from an idle one by enabled and parked, state being idle for both', function (): void {
    $idle = RelayRecord::fromArray(['state' => 'idle', 'enabled' => true, 'parked' => false] + relayWire());
    $parked = RelayRecord::fromArray(['state' => 'idle', 'enabled' => false, 'parked' => true] + relayWire());
    $configOff = RelayRecord::fromArray(['state' => 'idle', 'enabled' => false, 'parked' => false] + relayWire());

    expect($idle->state())->toBe(RelayRecord::STATE_IDLE)
        ->and($parked->state())->toBe(RelayRecord::STATE_IDLE)
        ->and($idle->enabled())->toBeTrue()
        ->and($parked->enabled())->toBeFalse()
        ->and($parked->parked())->toBeTrue()
        ->and($configOff->parked())->toBeFalse()
        ->and($configOff->enabled())->toBeFalse();
});

it('still redacts the url, and the new fields do not carry it', function (): void {
    $record = RelayRecord::fromArray(relayWire() + ['aliases' => ['yt-old'], 'last_session' => null, 'parked' => false]);

    expect($record->url())->toBe('srt://origin-atl-01.internal:8890')
        ->and(json_encode($record->toArray()))->not->toContain('hunter2');
});
