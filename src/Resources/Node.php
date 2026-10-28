<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Node\DoctorResponse;
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthPublic;
use AlexHackney\Tmesis\Data\Node\NodeStats;

/**
 * Node-level reads. Method names are the openapi operationIds, except
 * getPublicHealth(), which is `getHealth` asked without a token.
 */
class Node
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /api/v1/health.
     *
     * A not-ready node answers 503 with the error envelope rather than a health body,
     * surfacing as UnavailableException. `not_ready` is the only code the node emits
     * there (the health seam is unwired); `draining` and `journal_unavailable` are
     * RESERVED on this route (TMESIS-API-4) but map the same way if they ever arrive.
     */
    public function getHealth(): HealthFull
    {
        return HealthFull::fromArray($this->transport->get('/api/v1/health'));
    }

    /**
     * GET /api/v1/health with NO bearer token: the unauthenticated liveness probe.
     *
     * The same operation as getHealth() (`getHealth`, whose `security` is
     * `[{}, {adminToken: []}]`): with no token the node answers the HealthPublic
     * branch of its `oneOf`, the four fields `status`, `node`, `version` and
     * `contract_version`, on any bind. Capacity, the stream counts and
     * `degraded_reasons` stay behind the token. The node's admin token is never read,
     * so this works against a node configured with none.
     *
     * The error paths are getHealth()'s: a not-ready node answers 503 with the error
     * envelope, surfacing as UnavailableException. `not_ready` is the only code the
     * node emits there; `draining` and `journal_unavailable` are reserved (TMESIS-API-4).
     */
    public function getPublicHealth(): HealthPublic
    {
        return HealthPublic::fromArray($this->transport->withoutToken()->get('/api/v1/health'));
    }

    /**
     * GET /api/v1/system/doctor: the effective config, hardware, and checks.
     *
     * `checks=false` returns the cached snapshot without re-running the probes,
     * which is the cheap call for a polling dashboard.
     */
    public function getDoctor(bool $checks = true): DoctorResponse
    {
        return DoctorResponse::fromArray(
            $this->transport->get('/api/v1/system/doctor', ['checks' => $checks])
        );
    }

    /**
     * GET /api/v1/stats.
     */
    public function getNodeStats(): NodeStats
    {
        return NodeStats::fromArray($this->transport->get('/api/v1/stats'));
    }
}
