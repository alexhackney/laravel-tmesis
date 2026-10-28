<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\RelayStarted;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/**
 * The generated relay.started example, decoded.
 *
 * @return array<string, mixed>
 */
function relayStartedExample(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(ContractDir::example('relay.started')), true, 512, JSON_THROW_ON_ERROR);
}

it('reads no aliases when the node omits them, and matches on the id alone', function (): void {
    $event = EventMap::make(relayStartedExample());

    expect($event)->toBeInstanceOf(RelayStarted::class);

    /** @var RelayStarted $event */
    $relay = $event->destination();

    expect($relay->aliases())->toBe([])
        ->and($relay->matches('static-0'))->toBeTrue()
        ->and($relay->matches('origin'))->toBeFalse()
        ->and($relay->matches(''))->toBeFalse();
});

it('matches a provisioned destination folded into a static leg by its alias', function (): void {
    // tmesis v0.6.0, handover 3.2: a provisioned `origin` with the same URL and feed
    // as the static relay rides static-0, and names itself in aliases.
    $payload = relayStartedExample();
    $payload['destination']['aliases'] = ['origin'];

    SchemaValidator::forSchema('relay.started')->assertValid(
        json_decode((string) json_encode($payload), false),
        context: 'The aliases variant',
    );

    /** @var RelayStarted $event */
    $event = EventMap::make($payload);
    $relay = $event->destination();

    expect($relay->id())->toBe('static-0')
        ->and($relay->aliases())->toBe(['origin'])
        ->and($relay->matches('origin'))->toBeTrue()
        ->and($relay->matches('static-0'))->toBeTrue()
        ->and($relay->matches('static-1'))->toBeFalse()
        ->and($relay->toArray()['aliases'])->toBe(['origin']);
});

it('tolerates a malformed aliases value rather than throwing', function (): void {
    $payload = relayStartedExample();
    $payload['destination']['aliases'] = 'origin';

    /** @var RelayStarted $event */
    $event = EventMap::make($payload);

    expect($event->destination()->aliases())->toBe([]);

    $payload['destination']['aliases'] = ['origin', 7, null];

    /** @var RelayStarted $event */
    $event = EventMap::make($payload);

    expect($event->destination()->aliases())->toBe(['origin']);
});
