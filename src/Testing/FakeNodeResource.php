<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Node\DoctorResponse;
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthPublic;
use AlexHackney\Tmesis\Data\Node\NodeStats;
use AlexHackney\Tmesis\Resources\Node;

/**
 * Node reads, answered from FakeTmesis's canned data.
 */
class FakeNodeResource extends Node
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function getHealth(): HealthFull
    {
        $this->fake->record('getHealth', $this->node, ['token' => true]);

        return HealthFull::fromArray($this->fake->health());
    }

    /**
     * The four liveness fields of whatever withHealth() set, as the node would answer
     * a request with no token.
     *
     * Recorded as `getHealth` with `token: false`, the operation it is, the same way
     * getStreamTranscriptText() records `getStreamTranscript` with its format; so
     * `throwOn('getHealth', ...)` downs both reads, as a down node would.
     */
    public function getPublicHealth(): HealthPublic
    {
        $this->fake->record('getHealth', $this->node, ['token' => false]);

        return HealthPublic::fromArray($this->fake->publicHealth());
    }

    public function getDoctor(bool $checks = true): DoctorResponse
    {
        $this->fake->record('getDoctor', $this->node, ['checks' => $checks]);

        return DoctorResponse::fromArray($this->fake->doctor());
    }

    public function getNodeStats(): NodeStats
    {
        $this->fake->record('getNodeStats', $this->node);

        return NodeStats::fromArray($this->fake->nodeStats());
    }
}
