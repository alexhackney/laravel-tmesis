<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * The request conflicts with the node's current state (409).
 *
 * Codes: `stream_exists`, `stream_sealed`, `stream_busy`, `stream_not_live`,
 * `lease_held_elsewhere`, `job_not_cancellable`, `role_unsupported`, and
 * `relay_immutable`; since tmesis `v0.5.0` the nine artifact codes
 * `artifact_not_finalized`, `artifact_identity_mismatch`, `transfer_plan_stale`,
 * `transfer_identity_mismatch`, `transfer_incomplete`, `request_conflict`,
 * `release_conflict`, `artifact_retiring` and `artifact_reconciliation_required`.
 * `fleet_owned` (an enrolled node, the desired state owns the field; the jobs pause
 * routes since `v0.9.0`) is the subclass FleetOwnedException.
 * Branch on `code()`: `transfer_plan_stale` and `artifact_identity_mismatch` are the
 * two a caller answers by re-reading the generation and deciding again.
 *
 * `relay_immutable` is emitted by the relay routes and described in
 * `docs/contract/openapi.json`, but it is absent from the `docs/API.md` 6.2 table at
 * `v0.1.0-rc2`. It is mapped here on the artifact's authority; the omission is
 * reported in the handoff note.
 */
class ConflictException extends TmesisException {}
