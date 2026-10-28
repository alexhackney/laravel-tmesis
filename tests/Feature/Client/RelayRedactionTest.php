<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one package rule with no tolerance clause: a relay destination URL is a
 * credential, and it appears in exactly one place, the outbound request body.
 *
 * These are the four places it must never reach: a recorded call on the fake, a log
 * line, an exception message, and anything the package hands back.
 */
const SECRET_RELAY_URL = 'srt://backup-den-02.internal:8890?passphrase=hunter2&streamid=publish/show-2041';

const SECRET_PARTS = ['hunter2', 'publish/show-2041', 'passphrase'];

/**
 * @param  mixed  $haystack  anything that can be JSON encoded
 */
function carriesTheSecret(mixed $haystack): bool
{
    $rendered = is_string($haystack) ? $haystack : (string) json_encode($haystack);

    foreach (SECRET_PARTS as $part) {
        if (str_contains($rendered, $part)) {
            return true;
        }
    }

    return false;
}

it('sends the destination in full and gets back only the redaction', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'r1',
        // What the node actually answers: it redacts before sending.
        'url' => 'srt://backup-den-02.internal:8890',
        'enabled' => true,
        'source' => 'api',
        'state' => 'connecting',
        'bytes_out' => 0,
        'dropped_bytes' => 0,
        'reconnects' => 0,
    ], 201)]);

    $record = Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make(SECRET_RELAY_URL));

    expect(carriesTheSecret($record->toArray()))->toBeFalse()
        ->and($record->url())->toBe('srt://backup-den-02.internal:8890');
});

it('redacts a URL a node sent back unredacted anyway', function (): void {
    // Belt and braces. The node redacts, and RelayRecord redacts again on the way in,
    // so a node that regressed could not put a credential into a consumer's database.
    Http::fake(['*' => Http::response([
        'id' => 'r1',
        'url' => SECRET_RELAY_URL,
        'enabled' => true,
        'source' => 'api',
        'state' => 'connected',
        'bytes_out' => 0,
        'dropped_bytes' => 0,
        'reconnects' => 0,
    ])]);

    $record = Tmesis::node()->relays()->getRelay('show-2041-main', 'r1');

    expect(carriesTheSecret($record->toArray()))->toBeFalse();
});

it('records no URL on the fake, only its redaction', function (): void {
    $fake = Tmesis::fake();

    $fake->node()->relays()->createRelay('show-2041-main', RelayInput::make(SECRET_RELAY_URL));
    $fake->node()->relays()->updateRelay('show-2041-main', 'relay-1', RelayPatch::make(url: SECRET_RELAY_URL));

    foreach ($fake->calls() as $call) {
        expect(carriesTheSecret($call))->toBeFalse("The recorded [{$call['operation']}] call carries a credential.");
    }

    $fake->assertCalled('createRelay', fn (array $args): bool => $args['url'] === 'srt://backup-den-02.internal:8890');

    // And the destination it holds is redacted too, so replaying the fake cannot
    // leak one either.
    expect(carriesTheSecret($fake->node()->relays()->listRelays('show-2041-main')->toArray()))->toBeFalse();
});

it('logs nothing that carries a destination, on the way out or on failure', function (): void {
    Log::spy();

    Http::fake(['*' => Http::response(errorBody('validation_failed', 'the request has field problems'), 422)]);

    try {
        Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make(SECRET_RELAY_URL));

        // Without this the two assertions below never run, and the test passes by
        // never having reached them.
        $this->fail('A 422 from the node did not raise a TmesisException.');
    } catch (TmesisException $e) {
        // The exception is built from the node's error envelope and never from the
        // request, so there is nothing in it to leak.
        expect(carriesTheSecret($e->getMessage()))->toBeFalse()
            ->and(carriesTheSecret((string) json_encode($e->error()?->toArray())))->toBeFalse();
    }

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('keeps a provisioning spec loggable through toRedactedArray', function (): void {
    $spec = StreamSpec::make()->relays([RelayInput::make(SECRET_RELAY_URL)]);

    expect(carriesTheSecret($spec->toRedactedArray()))->toBeFalse()
        ->and(carriesTheSecret($spec->toArray()))->toBeTrue();
});

it('never renders a destination through string interpolation', function (): void {
    // No __toString on either request shape, and a __debugInfo that redacts (a
    // var_dump shows private properties without one), so an interpolation or a dump
    // cannot leak one by accident.
    expect(method_exists(RelayInput::class, '__toString'))->toBeFalse()
        ->and(method_exists(RelayPatch::class, '__toString'))->toBeFalse()
        ->and(print_r(RelayInput::make(SECRET_RELAY_URL), true))->not->toContain(SECRET_RELAY_URL)
        ->and(print_r(RelayPatch::make(url: SECRET_RELAY_URL), true))->not->toContain(SECRET_RELAY_URL)
        ->and(RelayInput::make(SECRET_RELAY_URL)->redacted())->toBe('srt://backup-den-02.internal:8890')
        ->and(RelayPatch::make(url: SECRET_RELAY_URL)->redacted())->toBe('srt://backup-den-02.internal:8890');
});
