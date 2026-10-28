<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\StreamPublishRejected;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

it('reads the v0.9.0 reasons over the generated example', function (string $reason): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(ContractDir::example('stream.publish_rejected')), true, 512, JSON_THROW_ON_ERROR);
    $payload['reason'] = $reason;

    SchemaValidator::forSchema('stream.publish_rejected')->assertValid(
        json_decode((string) json_encode($payload), false),
        context: "stream.publish_rejected reason {$reason}"
    );

    $event = EventMap::make($payload);

    expect($event)->toBeInstanceOf(StreamPublishRejected::class)
        ->and($event->reason())->toBe($reason);
})->with([StreamPublishRejected::REASON_OVER_CAPACITY, StreamPublishRejected::REASON_ENCODER_UNAVAILABLE]);

it('refuses a reason outside the closed set', function (): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(ContractDir::example('stream.publish_rejected')), true, 512, JSON_THROW_ON_ERROR);
    $payload['reason'] = 'internal';

    expect(SchemaValidator::forSchema('stream.publish_rejected')->isValid(json_decode((string) json_encode($payload), false)))->toBeFalse();
});
