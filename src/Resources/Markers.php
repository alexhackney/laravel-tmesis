<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Markers\MarkerListResponse;
use AlexHackney\Tmesis\Data\Markers\MarkerRequest;
use AlexHackney\Tmesis\Data\Markers\MarkerResponse;

/**
 * HLS date-range markers (SCTE-35 splice out and in) on a live stream (tmesis
 * v0.6.0, `docs/API.md` 9.8a, final plan 11.56). Method names are the openapi
 * operationIds.
 *
 * A marker is QUEUED by the POST and LANDED by `marker.placed`: the tag goes in at
 * the next segment cut of every media rendition, in the live, DVR and replay
 * playlists. Idempotency is per publish SESSION `(stream, session, id)`.
 */
class Markers
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * POST /api/v1/streams/:id/markers.
     *
     * `202` queued by this request (`MarkerResponse::$created`), `200` for the same
     * request under an id already on the stream (the stored marker as it is now).
     * `409 stream_not_live` when no live session with a packager exists (nobody
     * publishing, draining, or a passthrough node); `409 marker_exists` when a
     * DIFFERENT marker already has the id on this session. `422` lists every
     * problem at once.
     */
    public function placeMarker(string $streamId, MarkerRequest $marker): MarkerResponse
    {
        $result = $this->transport->post(
            $this->transport->path('/api/v1/streams/%s/markers', $streamId),
            $marker->toArray(),
        );

        return MarkerResponse::fromArray($result->body, created: $result->status === 202);
    }

    /**
     * GET /api/v1/streams/:id/markers: every marker told on the stream, every
     * session, oldest first. `404` for no such stream.
     */
    public function listMarkers(string $streamId): MarkerListResponse
    {
        return MarkerListResponse::fromArray(
            $this->transport->get($this->transport->path('/api/v1/streams/%s/markers', $streamId))
        );
    }
}
