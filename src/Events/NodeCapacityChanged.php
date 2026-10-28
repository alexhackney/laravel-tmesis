<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\NodeJobsCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeLiveCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeLiveCapacityPrevious;
use AlexHackney\Tmesis\Data\Webhooks\NodeVideoEngine;

/**
 * A node's capacity changed, or was stated at boot (tmesis `v0.9.0`, handover 3.3;
 * WEBHOOKS.md 8.24). Node-scoped: no stream, no labels.
 *
 * Documented as M3 with no schema until `v0.9.0`; the M3 reasons `backend_fallback`,
 * `hardware_change` and `disk` never shipped (a failed pin is `encoder`, a changed box
 * makes the record `stale`, and the disk is `node.disk_floor_near`).
 *
 * The admission and device events report a LATCHED state, not raw ledger edges: full
 * at once, not-full only after the ledger stayed below its limit for a 30 s
 * hold-down, so a refill inside it fires nothing and `full: false` arrives at least
 * 30 s after the release that caused it. Order by occurred_at. What to alert on:
 * `encoder` with either engine not usable(); `admission` or `device` with full()
 * true (the box is refusing publishes `over_capacity`); `benchmark` with a live
 * source of `stale` or `insufficient`.
 */
class NodeCapacityChanged extends TmesisWebhookReceived
{
    public const EVENT = 'node.capacity_changed';

    /**
     * The stored measurements at boot, once per boot right after `node.online`. No
     * history crosses a restart: diff it against what you last saw.
     */
    public const REASON_BENCHMARK = 'benchmark';

    /**
     * Once per boot after `benchmark`, ONLY when either encoder is pinned; carries
     * usable() for both.
     */
    public const REASON_ENCODER = 'encoder';

    /**
     * The units ledger filled or cleared, only while admission is on.
     */
    public const REASON_ADMISSION = 'admission';

    /**
     * The NVENC/QSV session ceiling filled (no room for one more session of the unit
     * ladder) or cleared, whenever a ceiling is known, admission on or off. A running
     * job's encoder counts, so a job can flip it with no publish.
     */
    public const REASON_DEVICE = 'device';

    /**
     * CLOSED set: the REASON_* constants.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * `admission` and `device`: true when the ledger filled, false once it stayed
     * below its limit for the 30 s hold-down. Null for `benchmark` and `encoder`.
     */
    public function full(): ?bool
    {
        $value = $this->payload['full'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * The health `live_capacity` object, with the transition's own used counts and
     * limits on `admission` and `device`, never a later reading.
     */
    public function liveCapacity(): NodeLiveCapacity
    {
        return NodeLiveCapacity::fromArray($this->object('live_capacity'));
    }

    /**
     * The job slots and their source; null when jobs are off.
     */
    public function jobsCapacity(): ?NodeJobsCapacity
    {
        $value = $this->payload['jobs_capacity'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return NodeJobsCapacity::fromArray($value);
    }

    /**
     * The live ladder's encoder, as on `node.online`; null on a node that encodes no
     * live stream.
     */
    public function videoEngine(): ?NodeVideoEngine
    {
        return $this->engine('video_engine');
    }

    /**
     * The VOD job encoder, as on `node.online`; null when jobs are off.
     */
    public function jobsVideoEngine(): ?NodeVideoEngine
    {
        return $this->engine('jobs_video_engine');
    }

    /**
     * The prior value of each live capacity field that changed since the last event
     * of THIS reason from this process. Null for `benchmark` and `encoder`, and for
     * the first event of a reason.
     */
    public function previous(): ?NodeLiveCapacityPrevious
    {
        $value = $this->payload['previous'] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return NodeLiveCapacityPrevious::fromArray($value);
    }

    private function engine(string $key): ?NodeVideoEngine
    {
        $value = $this->payload[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return NodeVideoEngine::fromArray($value);
    }
}
