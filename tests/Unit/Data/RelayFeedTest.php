<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\RelayFeed;

it('spells the three feeds the node accepts', function (): void {
    expect(RelayFeed::auto())->toBe('auto')
        ->and(RelayFeed::ingest())->toBe('ingest')
        ->and(RelayFeed::rung('720p'))->toBe('rung:720p')
        ->and(RelayFeed::rung('broadcast_720-high'))->toBe('rung:broadcast_720-high');
});

it('reads the rung name out of a feed, and nothing out of the other forms', function (): void {
    expect(RelayFeed::rungName('rung:720p'))->toBe('720p')
        ->and(RelayFeed::rungName('ingest'))->toBeNull()
        ->and(RelayFeed::rungName('auto'))->toBeNull()
        // What a record or a relay.* event carries while nothing is resolved.
        ->and(RelayFeed::rungName(''))->toBeNull()
        ->and(RelayFeed::rungName('rung:'))->toBeNull();
});

it('refuses a feed the node would refuse on its grammar, before it is sent', function (string $feed, string $sentence): void {
    expect(fn () => RelayFeed::guard($feed))->toThrow(InvalidConfigurationException::class, $sentence)
        ->and(fn () => RelayInput::make('srt://relay.example.test:8890', feed: $feed))->toThrow(InvalidConfigurationException::class, 'feed')
        ->and(fn () => RelayPatch::make(feed: $feed))->toThrow(InvalidConfigurationException::class, 'feed');
})->with([
    'an unknown word' => ['copy', 'must be auto, ingest, or rung:<name>'],
    'an upper-case prefix' => ['Rung:720p', 'must be auto, ingest, or rung:<name>'],
    'an upper-case auto' => ['AUTO', 'must be auto, ingest, or rung:<name>'],
    'an empty rung name' => ['rung:', 'rung:<name> needs a rung name'],
    'a rung name starting with -' => ['rung:-720p', 'not starting with - or _'],
    'a rung name starting with _' => ['rung:_720p', 'not starting with - or _'],
    'a rung name with a dot' => ['rung:720.p', 'letters, digits, - and _'],
    'a rung name with a space' => ['rung:720 p', 'letters, digits, - and _'],
    // PCRE's $ matches before a final newline; the node's parser does not.
    'a rung name with a trailing newline' => ["rung:720p\n", 'letters, digits, - and _'],
    'ingest with a trailing newline' => ["ingest\n", 'must be auto, ingest, or rung:<name>'],
]);

it('never mirrors whether the rung exists: that is the node\'s 422', function (): void {
    // The grammar is the whole check. A rung name no ladder has still passes here.
    expect(RelayFeed::rung('no-such-rung'))->toBe('rung:no-such-rung');
});

it('sends the feed on a relay input only when one was given', function (): void {
    expect(RelayInput::make('srt://relay.example.test:8890')->toArray())->toBe(['url' => 'srt://relay.example.test:8890'])
        ->and(RelayInput::make('srt://relay.example.test:8890')->feed())->toBeNull()
        ->and(RelayInput::make('srt://relay.example.test:8890', 'r1', false, RelayFeed::rung('720p'))->toArray())
        ->toBe(['url' => 'srt://relay.example.test:8890', 'id' => 'r1', 'enabled' => false, 'feed' => 'rung:720p']);
});

it('mentions the feed on a relay patch only when one was given', function (): void {
    expect(RelayPatch::make(enabled: false)->feed())->toBeNull()
        ->and(RelayPatch::make(enabled: false)->toArray())->toBe(['enabled' => false])
        ->and(RelayPatch::make(feed: RelayFeed::ingest())->toArray())->toBe(['feed' => 'ingest'])
        ->and(RelayPatch::make(feed: RelayFeed::ingest())->mentionsNothing())->toBeFalse();
});
