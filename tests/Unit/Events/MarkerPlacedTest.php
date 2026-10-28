<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Markers\MarkerPlacement;
use AlexHackney\Tmesis\Events\MarkerPlaced;
use AlexHackney\Tmesis\Keys\SessionEpoch;
use AlexHackney\Tmesis\Webhooks\EventMap;

it('maps marker.placed to its class and reads every field of the example', function (): void {
    /** @var MarkerPlaced $event */
    $event = EventMap::make($this->fixtureJson('contract/docs/contract/examples/marker.placed.json'));

    expect($event)->toBeInstanceOf(MarkerPlaced::class)
        ->and($event->event())->toBe(MarkerPlaced::EVENT)
        ->and($event->stream())->toBe('show-2041-main')
        ->and($event->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($event->epoch())->toBe(3)
        ->and($event->sessionEpoch())->toEqual(SessionEpoch::fromInt(3))
        ->and($event->marker())->toBe('break-2041-3')
        ->and($event->kind())->toBe(MarkerPlaced::KIND_OUT)
        ->and($event->class())->toBe('com.hackney.tmesis.scte35')
        ->and($event->durationSeconds())->toBe(30.0)
        ->and($event->scte35())->toBeNull()
        ->and($event->startDateRaw())->toBe('2026-08-28T19:26:54.006Z')
        ->and($event->startDate()->format('v'))->toBe('006')
        ->and($event->complete())->toBeTrue()
        ->and($event->labels())->toBe(['show_id' => '2041', 'site' => 'csn']);

    $placements = $event->placements();

    expect($placements)->toHaveCount(3)
        ->and($placements[0])->toBeInstanceOf(MarkerPlacement::class)
        ->and(array_map(fn (MarkerPlacement $p): string => $p->rendition(), $placements))->toBe(['480p', '720p', 'audio']);
});

it('reads an in with scte35, no duration, and an incomplete landing', function (): void {
    $payload = $this->fixtureJson('contract/docs/contract/examples/marker.placed.json');
    $payload['kind'] = 'in';
    $payload['duration_seconds'] = null;
    $payload['scte35'] = 'FC302000';
    $payload['complete'] = false;
    $payload['placements'] = [$payload['placements'][1]];

    /** @var MarkerPlaced $event */
    $event = EventMap::make($payload);

    expect($event->kind())->toBe(MarkerPlaced::KIND_IN)
        ->and($event->durationSeconds())->toBeNull()
        ->and($event->scte35())->toBe('FC302000')
        ->and($event->complete())->toBeFalse()
        ->and($event->placements())->toHaveCount(1)
        ->and($event->placements()[0]->rendition())->toBe('720p');
});
