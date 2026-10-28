<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

/**
 * The node received SIGTERM and is draining. Milestone M1. It stops accepting
 * new publishes and jobs, finalizes what it has, and exits. In fleet mode this
 * is the signal to stop scheduling work here. The reason enum has three values
 * and this build emits only sigterm; the others are in the frozen set so a
 * receiver keeps compiling when they arrive.
 */
class NodeDraining extends TmesisWebhookReceived
{
    public const EVENT = 'node.draining';

    public const REASON_SIGTERM = 'sigterm';

    public const REASON_API = 'api';

    public const REASON_DEPLOY = 'deploy';

    /**
     * An in-place drain by fleet command (tmesis `v0.7.0` D3): admission closes and the
     * process STAYS UP, so no exit follows. Every other reason ends in process exit.
     */
    public const REASON_FLEET = 'fleet';

    public function reason(): string
    {
        return $this->string('reason');
    }

    public function graceSeconds(): int
    {
        return $this->int('grace_seconds');
    }

    public function activeStreams(): int
    {
        return $this->int('active_streams');
    }

    public function activeJobs(): int
    {
        return $this->int('active_jobs');
    }

    public function outboxDepth(): int
    {
        return $this->int('outbox_depth');
    }
}
