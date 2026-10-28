<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredRelay;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;

/*
 * F4's builders: the canonical form the revision is keyed on, the wire shapes, and the
 * refusals that would otherwise reject the whole revision at the node.
 */

function sampleStream(string $id = 'show-2041-main', array $relays = []): DesiredStream
{
    return DesiredStream::make($id, DesiredRecording::make(true, 72, null, 'tsp-archive'), 6, ['site' => 'csn', 'show_id' => '2041'], $relays);
}

it('hashes the same content the same whatever order it was built in', function (): void {
    $a = DesiredDocument::make()->stream(sampleStream('b'))->stream(sampleStream('a'))->revoke('z', 'y', 'y');
    $b = DesiredDocument::make()->revoke('y')->stream(sampleStream('a'))->revoke('z')->stream(sampleStream('b'));

    expect($a->canonical())->toBe($b->canonical())
        ->and($a->revocations())->toBe(['y', 'z']);
});

it('keeps empty maps as objects and empty lists as lists on the wire', function (): void {
    $wire = json_encode(DesiredDocument::make()->stream(DesiredStream::make('s', DesiredRecording::make(false)))->toWire(3));

    expect($wire)->toBe('{"revision":3,"streams":[{"id":"s","min_key_epoch":0,"labels":{},"recording":{"enabled":false,"retention_hours":72,"destination":null,"destination_ref":null},"relays":[]}],"revocations":[],"open_posture":false,"jobs_pause":null}');
});

it('reproduces the empty-list digest and the vector digest', function (): void {
    expect(RevocationsDigest::of([]))->toBe(RevocationsDigest::EMPTY)
        ->and(DesiredDocument::make()->revocationsDigest())->toBe(RevocationsDigest::EMPTY)
        ->and(RevocationsDigest::of(['b', 'a', 'a']))->toBe(hash('sha256', "a\nb"));
});

it('refuses what the node would reject the whole revision for', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'both recording selectors' => [fn () => DesiredRecording::make(true, 72, '/srv/archive', 'tsp-archive'), 'recording.ingest'],
    'retention 0' => [fn () => DesiredRecording::make(true, 0), 'retention'],
    'a relative destination' => [fn () => DesiredRecording::make(true, 72, 'archive'), 'recording.ingest.destination'],
    'an http relay' => [fn () => DesiredRelay::make('r1', 'http://example.com/live'), 'srt://, rtmp:// or rtmps://'],
    'a duplicate relay id' => [fn () => sampleStream('s', [DesiredRelay::make('r', 'srt://a:1'), DesiredRelay::make('r', 'srt://b:1')]), 'appears twice'],
    'a duplicate stream' => [fn () => DesiredDocument::make()->stream(sampleStream())->stream(sampleStream()), 'appears twice'],
    'a bad stream id' => [fn () => DesiredStream::make('Bad Id', DesiredRecording::make(true)), 'not a stream id'],
    'a bad revocation' => [fn () => DesiredDocument::make()->revoke('NOPE'), 'not a stream id'],
    'a negative key epoch' => [fn () => DesiredStream::make('s', DesiredRecording::make(true), -1), 'min_key_epoch'],
    'revision 0' => [fn () => DesiredDocument::make()->toWire(0), 'revision'],
]);

it('never shows a relay URL in a dump or a refusal', function (): void {
    $relay = DesiredRelay::make('r1', 'srt://origin.example.com:8890?passphrase=CANARY-PASS');

    expect(print_r($relay, true))->not->toContain('CANARY-PASS')
        ->and(print_r(DesiredDocument::make()->stream(sampleStream('s', [$relay])), true))->not->toContain('CANARY-PASS')
        ->and($relay->redacted())->toBe('srt://origin.example.com:8890');

    try {
        DesiredRelay::make('r2', 'http://example.com/app/CANARY-KEY');
    } catch (InvalidConfigurationException $e) {
        expect($e->getMessage())->not->toContain('CANARY-KEY');
    }
});
