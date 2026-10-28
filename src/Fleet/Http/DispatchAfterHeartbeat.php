<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet\Http;

use AlexHackney\Tmesis\Fleet\HeartbeatHandler;
use AlexHackney\Tmesis\Fleet\HeartbeatOutcome;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a heartbeat's dispatch after the answer has been sent (plan F3).
 *
 * A terminable ROUTE middleware reading the outcome off the request, the way
 * VerifyTmesisSignature does for the webhook receiver, and never
 * `app()->terminating()`: those callbacks accumulate on the application, so under
 * Octane (one application, many requests) every later request would re-run every
 * earlier heartbeat's dispatch.
 */
class DispatchAfterHeartbeat
{
    public const OUTCOME_ATTRIBUTE = 'tmesis.fleet.outcome';

    public function __construct(private readonly HeartbeatHandler $handler) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response */
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $outcome = $request->attributes->get(self::OUTCOME_ATTRIBUTE);

        if ($outcome instanceof HeartbeatOutcome) {
            $this->handler->afterResponse($outcome);
        }
    }
}
