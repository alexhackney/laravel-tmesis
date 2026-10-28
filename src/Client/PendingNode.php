<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\DoctorResponse;
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthPublic;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Data\Node\NodeStats;
use AlexHackney\Tmesis\Data\Streams\StreamList;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\JobArtifacts;
use AlexHackney\Tmesis\Resources\Markers;
use AlexHackney\Tmesis\Resources\Node;
use AlexHackney\Tmesis\Resources\PreviewGrants;
use AlexHackney\Tmesis\Resources\Relays;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Resources\TranscodeJobs;

/**
 * One node, with its resources.
 *
 * `Tmesis::node()` returns this. The shortcuts at the bottom exist so the common
 * reads are one call rather than two.
 */
class PendingNode
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly NodeConfig $node,
    ) {}

    public function config(): NodeConfig
    {
        return $this->node;
    }

    public function node(): Node
    {
        return new Node($this->transport());
    }

    public function streams(): Streams
    {
        return new Streams($this->transport());
    }

    public function relays(): Relays
    {
        return new Relays($this->transport());
    }

    public function artifacts(): Artifacts
    {
        return new Artifacts($this->transport());
    }

    /**
     * WHEP preview grants on THIS node (tmesis v0.6.0): a grant covers monitors on
     * this box only.
     */
    public function previewGrants(): PreviewGrants
    {
        return new PreviewGrants($this->transport());
    }

    /**
     * HLS date-range markers on this node's live streams (tmesis v0.6.0).
     */
    public function markers(): Markers
    {
        return new Markers($this->transport());
    }

    /**
     * P10 VOD transcode jobs on this node (tmesis v0.6.0).
     */
    public function transcodeJobs(): TranscodeJobs
    {
        return new TranscodeJobs($this->transport());
    }

    /**
     * A transcode job's output generations: read, re-plan and release them (tmesis
     * v0.6.0). The job-scoped mirror of artifacts().
     */
    public function jobArtifacts(): JobArtifacts
    {
        return new JobArtifacts($this->transport());
    }

    /**
     * Correlate the next call with the node's own logs.
     */
    public function withRequestId(string $requestId): TransportAware
    {
        return new TransportAware($this->transport()->withRequestId($requestId));
    }

    public function getHealth(): HealthFull
    {
        return $this->node()->getHealth();
    }

    public function getPublicHealth(): HealthPublic
    {
        return $this->node()->getPublicHealth();
    }

    public function getDoctor(bool $checks = true): DoctorResponse
    {
        return $this->node()->getDoctor($checks);
    }

    public function getNodeStats(): NodeStats
    {
        return $this->node()->getNodeStats();
    }

    public function listStreams(?string $state = null): StreamList
    {
        return $this->streams()->listStreams($state);
    }

    public function getStream(string $id, bool $includePreview = false, bool $includeCaptions = false): StreamRecord
    {
        return $this->streams()->getStream($id, $includePreview, $includeCaptions);
    }

    protected function transport(): Transport
    {
        return new Transport($this->registry, $this->node);
    }
}
