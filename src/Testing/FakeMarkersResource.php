<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Markers\MarkerListResponse;
use AlexHackney\Tmesis\Data\Markers\MarkerRequest;
use AlexHackney\Tmesis\Data\Markers\MarkerResponse;
use AlexHackney\Tmesis\Resources\Markers;

/**
 * Markers, answered from FakeTmesis's marker ledger: a stream the fake does not hold
 * as `publishing` or `live` is `409 stream_not_live`, the same request under an id
 * already on the session is the idempotent `200` (`created: false`), a different one
 * is `409 marker_exists`. A marker stays `queued` until FakeTmesis::landMarker().
 */
class FakeMarkersResource extends Markers
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function placeMarker(string $streamId, MarkerRequest $marker): MarkerResponse
    {
        $this->fake->record('placeMarker', $this->node, ['streamId' => $streamId] + $marker->toArray());

        return $this->fake->placedMarker($streamId, $marker);
    }

    public function listMarkers(string $streamId): MarkerListResponse
    {
        $this->fake->record('listMarkers', $this->node, ['streamId' => $streamId]);

        return $this->fake->markerList($streamId);
    }
}
