<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\PendingNode;
use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Client\TransportAware;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\JobArtifacts;
use AlexHackney\Tmesis\Resources\Markers;
use AlexHackney\Tmesis\Resources\Node;
use AlexHackney\Tmesis\Resources\PreviewGrants;
use AlexHackney\Tmesis\Resources\Relays;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Resources\TranscodeJobs;

/**
 * One node's worth of canned answers, returned by FakeTmesis::node().
 *
 * It extends PendingNode so a consumer's type hints keep working, and overrides every
 * resource, and `withRequestId()`, with a fake that never builds a Transport.
 * `transport()` itself refuses, so a PendingNode method added later that reaches for
 * it fails loudly in a test instead of calling a configured node.
 */
class FakeNode extends PendingNode
{
    public function __construct(
        NodeRegistry $registry,
        NodeConfig $node,
        private readonly FakeTmesis $fake,
    ) {
        parent::__construct($registry, $node);
    }

    public function node(): Node
    {
        return new FakeNodeResource($this->fake, $this->config()->name);
    }

    public function streams(): Streams
    {
        return new FakeStreamsResource($this->fake, $this->config()->name);
    }

    public function relays(): Relays
    {
        return new FakeRelaysResource($this->fake, $this->config()->name);
    }

    public function artifacts(): Artifacts
    {
        return new FakeArtifactsResource($this->fake, $this->config()->name);
    }

    public function previewGrants(): PreviewGrants
    {
        return new FakePreviewGrantsResource($this->fake, $this->config()->name);
    }

    public function markers(): Markers
    {
        return new FakeMarkersResource($this->fake, $this->config()->name);
    }

    public function transcodeJobs(): TranscodeJobs
    {
        return new FakeTranscodeJobsResource($this->fake, $this->config()->name);
    }

    public function jobArtifacts(): JobArtifacts
    {
        return new FakeJobArtifactsResource($this->fake, $this->config()->name);
    }

    public function withRequestId(string $requestId): TransportAware
    {
        return new FakeTransportAware($this->fake, $this->config()->name, $requestId);
    }

    protected function transport(): Transport
    {
        throw InvalidConfigurationException::invalid(
            'tmesis',
            'a FakeTmesis node never builds a transport; this call would have reached a real node'
        );
    }
}
