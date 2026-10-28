<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\JobArtifacts;
use AlexHackney\Tmesis\Resources\Markers;
use AlexHackney\Tmesis\Resources\Node;
use AlexHackney\Tmesis\Resources\PreviewGrants;
use AlexHackney\Tmesis\Resources\Relays;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Resources\TranscodeJobs;

/**
 * The resources bound to one already-configured transport.
 *
 * Returned by `PendingNode::withRequestId()`, so the request id survives into
 * whichever resource the caller reaches for next.
 */
class TransportAware
{
    public function __construct(private readonly Transport $transport) {}

    public function node(): Node
    {
        return new Node($this->transport);
    }

    public function streams(): Streams
    {
        return new Streams($this->transport);
    }

    public function relays(): Relays
    {
        return new Relays($this->transport);
    }

    public function artifacts(): Artifacts
    {
        return new Artifacts($this->transport);
    }

    public function previewGrants(): PreviewGrants
    {
        return new PreviewGrants($this->transport);
    }

    public function markers(): Markers
    {
        return new Markers($this->transport);
    }

    public function transcodeJobs(): TranscodeJobs
    {
        return new TranscodeJobs($this->transport);
    }

    public function jobArtifacts(): JobArtifacts
    {
        return new JobArtifacts($this->transport);
    }
}
