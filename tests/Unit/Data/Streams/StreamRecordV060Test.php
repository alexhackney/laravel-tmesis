<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\FallbackRecord;
use AlexHackney\Tmesis\Data\Streams\Stats\DeliverySnapshot;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Data\Streams\StreamStats;

/*
 * The stream DTO fields tmesis v0.6.0 made REQUIRED: fallback, destination_ref, the
 * three delivery facts, and the `both` widening of delivery_mode.
 */

it('reads the effective fallback off the record', function (): void {
    $record = StreamRecord::fromArray($this->fixtureJson('responses/getStream.json'));

    expect($record->fallback())->toBeInstanceOf(FallbackRecord::class)
        ->and($record->fallback()->endSeconds())->toBe(3600)
        ->and($record->fallback()->toArray())->toBe(['end_seconds' => 3600]);
});

it('tolerates a record from before v0.6.0 with no fallback', function (): void {
    $body = $this->fixtureJson('responses/getStream.json');
    unset($body['fallback']);

    expect(StreamRecord::fromArray($body)->fallback()->endSeconds())->toBe(0);
});

it('reads destination_ref beside destination on the ingest record', function (): void {
    $body = $this->fixtureJson('responses/getStream.json');
    $ingest = StreamRecord::fromArray($body)->recording()->ingest();

    expect($ingest->destination())->toBe('/mnt/archive/ingest')
        ->and($ingest->destinationRef())->toBeNull();

    $body['recording']['ingest']['destination'] = null;
    $body['recording']['ingest']['destination_ref'] = 'csn-vod';
    $ingest = StreamRecord::fromArray($body)->recording()->ingest();

    expect($ingest->destination())->toBeNull()
        ->and($ingest->destinationRef())->toBe('csn-vod');
});

it('maps each delivery_mode to its playback roots, both included', function (string $mode, bool $bucket, bool $origin): void {
    $body = $this->fixtureJson('responses/getStream.json');
    $body['delivery_mode'] = $mode;
    $record = StreamRecord::fromArray($body);

    expect($record->deliveryMode())->toBe($mode)
        ->and($record->servesFromBucket())->toBe($bucket)
        ->and($record->servesFromOrigin())->toBe($origin);
})->with([
    'bucket' => [StreamRecord::DELIVERY_MODE_BUCKET, true, false],
    'origin' => [StreamRecord::DELIVERY_MODE_ORIGIN, false, true],
    'both' => [StreamRecord::DELIVERY_MODE_BOTH, true, true],
]);

it('lists the three delivery modes', function (): void {
    expect(StreamRecord::DELIVERY_MODES)->toBe(['bucket', 'origin', 'both']);
});

it('reads the three delivery facts on the stats body', function (): void {
    $delivery = StreamStats::fromArray($this->fixtureJson('responses/getStreamStats.json'))->delivery();

    expect($delivery->mode())->toBe(DeliverySnapshot::MODE_BUCKET)
        ->and($delivery->originServing())->toBeFalse()
        ->and($delivery->liveDestination())->toBe('live');
});

it('reads a both node and an origin node on the stats body', function (): void {
    $body = $this->fixtureJson('responses/getStreamStats.json');

    $body['delivery']['mode'] = 'both';
    $body['delivery']['origin_serving'] = true;
    $both = StreamStats::fromArray($body)->delivery();

    $body['delivery']['mode'] = 'origin';
    $body['delivery']['live_destination'] = null;
    $origin = StreamStats::fromArray($body)->delivery();

    expect($both->mode())->toBe(DeliverySnapshot::MODE_BOTH)
        ->and($both->originServing())->toBeTrue()
        ->and($both->liveDestination())->toBe('live')
        ->and($origin->mode())->toBe(DeliverySnapshot::MODE_ORIGIN)
        ->and($origin->liveDestination())->toBeNull();
});
