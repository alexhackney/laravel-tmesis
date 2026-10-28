<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;

/**
 * The host app's side of the fleet: what each enrolled node should be running.
 *
 * Called on enrolment and on EVERY heartbeat (every 10 seconds per node by default),
 * so it should answer from the app's own cache. `FleetNode::appliedRevision()` says
 * what the node last applied, for a provider that wants to short-circuit.
 *
 * Return the whole document every time; never a delta and never a revision. The
 * package hashes the canonical document and moves the revision only when the content
 * changed. A throw is caught: the heartbeat still records and acks, the
 * answer carries `desired_state: null`, and the node keeps what it last applied
 * (principle 1). `FleetDesiredStateFailed` says so.
 *
 * The told jobs pause (`v0.9.0`, handover 3.5) rides the same document:
 * `DesiredDocument::jobsPause(DesiredJobsPause::make($reason, $until))`. Absent CLEARS
 * it, so a provider that pauses a node before a show sets it on every document until
 * the show is over; a document built without it is the resume.
 */
interface DesiredStateProvider
{
    public function desiredFor(FleetNode $node): DesiredDocument;
}
