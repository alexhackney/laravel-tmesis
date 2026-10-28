<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\NodeCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeJobsPause;
use AlexHackney\Tmesis\Data\Webhooks\NodeListeners;
use AlexHackney\Tmesis\Data\Webhooks\NodeVideoEngine;
use DateTimeImmutable;

/**
 * The node finished startup and is ready: config loaded, journal recovered,
 * orphans reaped, listeners bound. Milestone M1. Not the process starting.
 * There is no stream and no labels on node.* events. Since tmesis `v0.9.0` it
 * carries both encoders (`video_engine`, `jobs_video_engine`, handover 3.4), the
 * jobs pause restored at boot (`jobs_pause`, 3.5) and `capacity.admission` (3.2).
 * Boot order: this, then `node.capacity_changed` `benchmark`, then `encoder` if
 * either workload is pinned, then `node.disk_floor_near` if the box starts inside
 * the margin. Order by occurred_at, never arrival.
 */
class NodeOnline extends TmesisWebhookReceived
{
    public const EVENT = 'node.online';

    public function version(): string
    {
        return $this->string('version');
    }

    /**
     * RFC3339 UTC, docs/API.md section 4
     */
    public function startedAtRaw(): string
    {
        return $this->required('started_at');
    }

    /**
     * Parsed from the raw value above.
     */
    public function startedAt(): DateTimeImmutable
    {
        return $this->timestamp('started_at');
    }

    public function configSource(): string
    {
        return $this->string('config_source');
    }

    /**
     * Sessions left open by a previous process and handled by the boot sweep. Since
     * tmesis `v0.8.0` this also counts sessions a DRAIN held for this start (a restart
     * holds the show), so non-zero no longer means the last process crashed.
     */
    public function orphansReaped(): int
    {
        return $this->int('orphans_reaped');
    }

    public function capacity(): NodeCapacity
    {
        return NodeCapacity::fromArray($this->object('capacity'));
    }

    public function listeners(): NodeListeners
    {
        return NodeListeners::fromArray($this->object('listeners'));
    }

    /**
     * The live ladder's encoder (`v0.9.0`). Null on a node that encodes no live
     * stream: no default ladder, or the transcoder role, whose encoder is
     * jobsVideoEngine() since `v0.9.0`.
     */
    public function videoEngine(): ?NodeVideoEngine
    {
        $value = $this->payload['video_engine'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return NodeVideoEngine::fromArray($value);
    }

    /**
     * The VOD job encoder (`jobs.video_encoder`, `v0.9.0`); null when jobs are off.
     */
    public function jobsVideoEngine(): ?NodeVideoEngine
    {
        $value = $this->payload['jobs_video_engine'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return NodeVideoEngine::fromArray($value);
    }

    /**
     * The jobs pause in force when the node came up, restored from the journal
     * (`v0.9.0`, handover 3.5); null when none is active. A restored pause emits NO
     * `jobs.paused`: this field, and the first heartbeat, are how a receiver learns
     * of it.
     */
    public function jobsPause(): ?NodeJobsPause
    {
        $value = $this->payload['jobs_pause'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return NodeJobsPause::fromArray($value);
    }
}
