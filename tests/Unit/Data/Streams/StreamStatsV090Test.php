<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\Stats\IngestSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\RingSnapshot;
use AlexHackney\Tmesis\Data\Streams\StreamStats;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

/*
 * The stream stats fields tmesis v0.9.0 added (handover 3.8): the ring entry's unit,
 * which says the `ingest` entry's `*_fragments` counts are BYTES, and the ingest
 * block's largest arrival gap.
 */

it('reads the ingest ring in bytes and every rung ring in fragments', function (): void {
    $rings = StreamStats::fromArray($this->fixtureJson('responses/getStreamStats.json'))->ring();

    expect(array_map(static fn (RingSnapshot $ring): string => $ring->rung(), $rings))->toBe(['ingest', '720p', '480p'])
        ->and(array_map(static fn (RingSnapshot $ring): string => $ring->unit(), $rings))->toBe([
            RingSnapshot::UNIT_BYTES,
            RingSnapshot::UNIT_FRAGMENTS,
            RingSnapshot::UNIT_FRAGMENTS,
        ])
        ->and(array_map(static fn (RingSnapshot $ring): bool => $ring->isBytes(), $rings))->toBe([true, false, false]);
});

it('reads the same counts through the unit-honest accessors as through the wire names', function (): void {
    [$ingest, , $rung] = StreamStats::fromArray($this->fixtureJson('responses/getStreamStats.json'))->ring();

    // The ingest entry: 188000 BYTES deep in a 32 MiB ring, under names that say fragments.
    expect($ingest->depth())->toBe(188000)
        ->and($ingest->depthFragments())->toBe($ingest->depth())
        ->and($ingest->capacity())->toBe(33554432)
        ->and($ingest->capacityFragments())->toBe($ingest->capacity())
        ->and($ingest->droppedTotal())->toBe(0)
        ->and($ingest->droppedFragmentsTotal())->toBe($ingest->droppedTotal());

    expect($rung->depth())->toBe(2)
        ->and($rung->capacity())->toBe(240)
        ->and($rung->droppedTotal())->toBe(12)
        ->and($rung->droppedFragmentsTotal())->toBe(12);
});

it('reads fragments for a ring entry from a node before v0.9.0', function (): void {
    $ring = RingSnapshot::fromArray(['rung' => '720p', 'depth_fragments' => 3]);

    expect($ring->unit())->toBe(RingSnapshot::UNIT_FRAGMENTS)
        ->and($ring->isBytes())->toBeFalse()
        ->and($ring->depth())->toBe(3);
});

it('reads bytes for the ingest entry from a node before v0.9.0, whose counts were always bytes', function (): void {
    $ring = RingSnapshot::fromArray(['rung' => 'ingest', 'depth_fragments' => 65536]);

    expect($ring->unit())->toBe(RingSnapshot::UNIT_BYTES)
        ->and($ring->isBytes())->toBeTrue()
        ->and($ring->depth())->toBe(65536);
});

it('holds the ring unit set equal to the component enum', function (): void {
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    expect(RingSnapshot::UNITS)->toEqualCanonicalizing($components['RingSnapshot']['properties']['unit']['enum']);
});

it('reads the largest arrival gap', function (): void {
    $ingest = StreamStats::fromArray($this->fixtureJson('responses/getStreamStats.json'))->ingest();

    expect($ingest->arrivalGapMaxSeconds())->toBe(0.041);
});

it('reads a null arrival gap before two reads, and from a node before v0.9.0', function (): void {
    $body = $this->fixtureJson('responses/getStreamStats.json');
    $body['ingest']['arrival_gap_max_seconds'] = null;

    SchemaValidator::forOpenApi()->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/StreamStatsResponse');

    expect(StreamStats::fromArray($body)->ingest()->arrivalGapMaxSeconds())->toBeNull();

    unset($body['ingest']['arrival_gap_max_seconds']);

    expect(StreamStats::fromArray($body)->ingest()->arrivalGapMaxSeconds())->toBeNull()
        ->and(IngestSnapshot::fromArray(['arrival_gap_max_seconds' => 2])->arrivalGapMaxSeconds())->toBe(2.0);
});
