<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\StreamLive;
use AlexHackney\Tmesis\Events\StreamSealed;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/*
 * `delivery_mode` WIDENS to `both` on stream.live and stream.sealed (tmesis v0.6.0,
 * final plan 11.57). It rides the envelope, not the playback object, and the
 * playback URLs are paths: the roots are the receiver's to map.
 */

it('reads the example delivery_mode on both events', function (string $name, string $class): void {
    /** @var StreamLive|StreamSealed $event */
    $event = EventMap::make($this->fixtureJson("contract/docs/contract/examples/{$name}.json"));

    expect($event)->toBeInstanceOf($class)
        ->and($event->deliveryMode())->toBe('bucket')
        ->and($event->servesFromBucket())->toBeTrue()
        ->and($event->servesFromOrigin())->toBeFalse();
})->with([
    'stream.live' => ['stream.live', StreamLive::class],
    'stream.sealed' => ['stream.sealed', StreamSealed::class],
]);

it('takes both, which is valid against the schema and serves from both roots', function (string $name, string $mode, bool $bucket, bool $origin): void {
    $payload = $this->fixtureJson("contract/docs/contract/examples/{$name}.json");
    $payload['delivery_mode'] = $mode;

    SchemaValidator::forSchema($name)->assertValid(json_decode((string) json_encode($payload), false), context: "{$name} with {$mode}");

    /** @var StreamLive|StreamSealed $event */
    $event = EventMap::make($payload);

    expect($event->deliveryMode())->toBe($mode)
        ->and($event->servesFromBucket())->toBe($bucket)
        ->and($event->servesFromOrigin())->toBe($origin);
})->with([
    'stream.live both' => ['stream.live', StreamLive::DELIVERY_MODE_BOTH, true, true],
    'stream.live origin' => ['stream.live', StreamLive::DELIVERY_MODE_ORIGIN, false, true],
    'stream.sealed both' => ['stream.sealed', StreamSealed::DELIVERY_MODE_BOTH, true, true],
    'stream.sealed origin' => ['stream.sealed', StreamSealed::DELIVERY_MODE_ORIGIN, false, true],
]);
