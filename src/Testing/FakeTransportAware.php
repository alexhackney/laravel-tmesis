<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Client\TransportAware;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\JobArtifacts;
use AlexHackney\Tmesis\Resources\Markers;
use AlexHackney\Tmesis\Resources\Node;
use AlexHackney\Tmesis\Resources\PreviewGrants;
use AlexHackney\Tmesis\Resources\Relays;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Resources\TranscodeJobs;

/**
 * What `FakeNode::withRequestId()` returns: the same fake resources, so a request id
 * on a faked client never builds a Transport and never reaches a configured node.
 *
 * The parent's transport is deliberately never set; every method is overridden.
 */
final class FakeTransportAware extends TransportAware
{
    public function __construct(
        private readonly FakeTmesis $fake,
        private readonly string $nodeName,
        private readonly string $requestId,
    ) {}

    /**
     * The request id the caller asked to correlate with, for assertions.
     */
    public function requestId(): string
    {
        return $this->requestId;
    }

    public function node(): Node
    {
        return new FakeNodeResource($this->fake, $this->nodeName);
    }

    public function streams(): Streams
    {
        return new FakeStreamsResource($this->fake, $this->nodeName);
    }

    public function relays(): Relays
    {
        return new FakeRelaysResource($this->fake, $this->nodeName);
    }

    public function artifacts(): Artifacts
    {
        return new FakeArtifactsResource($this->fake, $this->nodeName);
    }

    public function previewGrants(): PreviewGrants
    {
        return new FakePreviewGrantsResource($this->fake, $this->nodeName);
    }

    public function markers(): Markers
    {
        return new FakeMarkersResource($this->fake, $this->nodeName);
    }

    public function transcodeJobs(): TranscodeJobs
    {
        return new FakeTranscodeJobsResource($this->fake, $this->nodeName);
    }

    public function jobArtifacts(): JobArtifacts
    {
        return new FakeJobArtifactsResource($this->fake, $this->nodeName);
    }
}
