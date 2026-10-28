<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Support\RelayUrl;

it('keeps only scheme, host and port', function (string $url, string $expected): void {
    // A relay URL is a credential: an SRT passphrase or an RTMP stream key rides in
    // the query string or the path.
    expect(RelayUrl::redact($url))->toBe($expected);
})->with([
    'srt with a passphrase' => ['srt://origin-atl-01.internal:8890?passphrase=hunter2', 'srt://origin-atl-01.internal:8890'],
    'rtmp with a stream key in the path' => ['rtmp://a.rtmp.example.com/live/sk_live_secret', 'rtmp://a.rtmp.example.com'],
    'userinfo' => ['srt://user:pass@host.example:9000/path', 'srt://host.example:9000'],
    'already redacted' => ['srt://origin-atl-01.internal:8890', 'srt://origin-atl-01.internal:8890'],
    'no port' => ['rtmps://push.example.com/app?key=abc', 'rtmps://push.example.com'],
]);

it('gives up safely on something it cannot parse', function (): void {
    // Anything more than the scheme risks passing a credential through.
    expect(RelayUrl::redact('srt://'))->toBe('srt://')
        ->and(RelayUrl::redact('not a url at all'))->toBe('');
});
