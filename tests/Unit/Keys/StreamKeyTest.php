<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Exceptions\InvalidPublishTemplateException;
use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;
use AlexHackney\Tmesis\Exceptions\StreamKeySignatureException;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\StreamId;
use AlexHackney\Tmesis\Keys\StreamKey;
use AlexHackney\Tmesis\Keys\StreamKeyMinter;
use AlexHackney\Tmesis\Tests\TestCase;

const DOCS_MAC = 'a5e453ff6bb7ec27302c611f2caa4bef986759b280388bcd3d8ee0e829e32ff2';

it('mints the docs/API.md 8.1 worked example', function (): void {
    $key = app(StreamKeyMinter::class)->mint('show-2041-main', KeyEpoch::fromInt(7));

    expect($key->toString())->toBe('show-2041-main.7.'.DOCS_MAC);
});

it('renders the redacted fragment, which is the only loggable form', function (): void {
    $key = StreamKey::parse('show-2041-main.7.'.DOCS_MAC);

    expect($key->fragment())->toBe('show-2041-main.7.a5e4...2ff2')
        ->and($key->fragment())->not->toContain(substr(DOCS_MAC, 8, 40));
});

it('substitutes into a publish template', function (): void {
    $key = StreamKey::parse('show-2041-main.7.'.DOCS_MAC);

    expect($key->substituteInto('srt://edge:9000?streamid={key}'))
        ->toBe('srt://edge:9000?streamid=show-2041-main.7.'.DOCS_MAC);
});

it('refuses a template with no placeholder', function (): void {
    $key = StreamKey::parse('show-2041-main.7.'.DOCS_MAC);

    expect(fn () => $key->substituteInto('srt://edge:9000'))
        ->toThrow(InvalidPublishTemplateException::class);
});

it('splits on the last two dots', function (): void {
    $key = StreamKey::parse('tenant_42_cam_a.12.'.DOCS_MAC);

    expect($key->stream)->toBe('tenant_42_cam_a')
        ->and($key->epoch->value)->toBe(12)
        ->and($key->signedString())->toBe('tenant_42_cam_a.12');
});

it('validates a stream id over bytes, not characters', function (): void {
    // No `u` modifier: a NUL byte and non-ASCII bytes must both be refused, and with
    // the modifier an invalid UTF-8 sequence would make preg_match return false.
    expect(fn () => StreamId::assert("show\x002041"))->toThrow(MalformedStreamKeyException::class)
        ->and(fn () => StreamId::assert("sh\xc3\xb4w-2041"))->toThrow(MalformedStreamKeyException::class)
        ->and(fn () => StreamId::assert("sh\xff\xfew"))->toThrow(MalformedStreamKeyException::class)
        ->and(StreamId::isValid('show-2041-main'))->toBeTrue()
        ->and(StreamId::isValid(str_repeat('a', 128)))->toBeTrue()
        ->and(StreamId::isValid(str_repeat('a', 129)))->toBeFalse();
});

it('verifies against the rotation window', function (): void {
    $minter = app(StreamKeyMinter::class);

    $old = $minter->mint('show-2041-main', KeyEpoch::fromInt(7), 'sk_old');

    config()->set('tmesis.keys.previous_secrets', ['sk_old']);

    $minter->verifyAgainstAny($old);

    config()->set('tmesis.keys.previous_secrets', []);

    expect(fn () => $minter->verifyAgainstAny($old))
        ->toThrow(StreamKeySignatureException::class);
});

it('verifies a key minted with the configured secret', function (): void {
    $minter = app(StreamKeyMinter::class);

    $key = $minter->mint('show-2041-main', KeyEpoch::fromInt(7));

    $minter->verify($key);
    $minter->verify($key->toString());

    expect(app(NodeRegistry::class)->keySecret())->toBe(TestCase::KEY_SECRET);
});
