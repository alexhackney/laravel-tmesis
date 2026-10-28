<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet\Http;

use AlexHackney\Tmesis\Data\Fleet\EnrollRequest;
use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use AlexHackney\Tmesis\Fleet\Enroller;
use AlexHackney\Tmesis\Fleet\EnrollRefused;
use AlexHackney\Tmesis\Fleet\FleetUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * `POST {prefix}/fleet/enroll` and `DELETE {prefix}/fleet/enroll` (docs/API.md 15.1,
 * plan F2 and F8).
 *
 * The POST takes the one-time join token as `Authorization: Bearer` and the
 * `fleet.enroll.request` body. Order: the token (`401 unknown_token`), the body
 * (`422` naming the path), the heartbeat URL this answer would carry (`503
 * heartbeat_url_insecure`, BEFORE anything is spent), then the enrollment. The answer is
 * `fleet.enroll.response` with `Cache-Control: no-store`: it carries the fleet token.
 *
 * The DELETE takes the fleet token as a bearer: `204` when it deregistered a node,
 * `404` when the token is unknown or already left (the node reads both as gone).
 *
 * Nothing here logs a token, a body, or a desired document.
 */
class EnrollController
{
    public function __construct(private readonly Enroller $enroller) {}

    public function enroll(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return $this->error(401, 'unknown_token', 'The join token is not valid.');
        }

        try {
            $body = EnrollRequest::fromRaw($request->getContent());
        } catch (FleetPayloadException $e) {
            return $this->error(422, 'validation_failed', $e->getMessage());
        }

        $heartbeatUrl = route('tmesis.fleet.heartbeat');

        if (! FleetUrl::acceptable($heartbeatUrl)) {
            // The node refuses the whole answer over an insecure heartbeat URL (D8), so
            // answering would spend the join token on a document it throws away. The
            // usual cause is TLS terminated at a proxy the app does not trust.
            Log::error('Tmesis fleet enroll refused: the heartbeat URL this app generates is not https (and not loopback), and the node would refuse it. Trust the proxy (TrustProxies) or set APP_URL / URL::forceScheme("https").', [
                'heartbeat_url' => $heartbeatUrl,
            ]);

            return $this->error(503, 'heartbeat_url_insecure', 'This control plane cannot hand out an https heartbeat URL.');
        }

        try {
            $outcome = $this->enroller->enroll($token, $body);
        } catch (EnrollRefused $e) {
            return $this->error($e->status, $e->errorCode, $e->getMessage());
        }

        return response()->json($outcome->toResponse($heartbeatUrl), 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }

    public function leave(Request $request): Response|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '' || ! $this->enroller->leave($token)) {
            return $this->error(404, 'unknown_node', 'No enrolled node holds this fleet token.');
        }

        return response()->noContent();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
