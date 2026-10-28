<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * `409 fleet_owned`: the node is enrolled, and the control plane's desired state owns
 * what the request tried to change (tmesis `v0.7.0`; the jobs pause routes since
 * `v0.9.0`, handover 3.5).
 *
 * On `setTranscodePause` and `clearTranscodePause` the detail path is `jobs_pause`:
 * an enrolled box takes its pause from the desired state, so the fix is the next
 * revision's `jobs_pause`, never a retry of the route. On the stream and relay
 * routes the detail names the fleet-owned field or relay (`min_key_epoch`, `labels`,
 * `recording.ingest.*`, `relays`, `retain_config`).
 *
 * It extends ConflictException, so an existing `catch (ConflictException)` still
 * catches it. `ownedPaths()` is the details' paths.
 */
class FleetOwnedException extends ConflictException
{
    /**
     * The `path` of every detail the node sent, in order (`['jobs_pause']` on the pause
     * routes). Empty when the node sent none.
     *
     * @return list<string>
     */
    public function ownedPaths(): array
    {
        $out = [];

        foreach ($this->error?->details() ?? [] as $detail) {
            $out[] = $detail->path();
        }

        return $out;
    }
}
