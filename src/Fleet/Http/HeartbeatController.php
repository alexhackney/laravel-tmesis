<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet\Http;

use AlexHackney\Tmesis\Data\Fleet\HeartbeatRequest;
use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use AlexHackney\Tmesis\Fleet\FleetConfig;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Fleet\HeartbeatHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST {prefix}/fleet/heartbeat` (docs/API.md 15.2, plan F3).
 *
 * Order: the bearer fleet token (`401`, empty body: the node logs a 401 once per streak
 * and keeps trying), the size (`413` over `fleet.max_body_bytes`, from the header before
 * the body is read and again on the bytes), the body (`422` naming the path; nothing is
 * recorded or acked, because a 2xx would ack events nobody read), then the handler.
 * A failure while recording or claiming is `503` with nothing acked, and the node
 * re-sends the batch. The answer is `fleet.heartbeat.response`; dispatch happens after
 * it has been sent (DispatchAfterHeartbeat, the route's terminable middleware).
 *
 * Nothing here logs a token, a body, an event payload or a desired document.
 */
class HeartbeatController
{
    public function __construct(
        private readonly FleetStore $store,
        private readonly HeartbeatHandler $handler,
    ) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        $row = is_string($token) && $token !== '' ? $this->store->nodeRowByToken(hash('sha256', $token)) : null;

        if ($row === null) {
            return response()->json(null, 401);
        }

        $max = FleetConfig::maxBodyBytes();
        $declared = $request->header('Content-Length');

        if (is_string($declared) && ctype_digit($declared) && (int) $declared > $max) {
            return $this->error(413, 'payload_too_large', "The heartbeat is over {$max} bytes.");
        }

        $raw = $request->getContent();

        if (strlen($raw) > $max) {
            return $this->error(413, 'payload_too_large', "The heartbeat is over {$max} bytes.");
        }

        try {
            $body = HeartbeatRequest::fromRaw($raw);
        } catch (FleetPayloadException $e) {
            Log::warning('Tmesis fleet heartbeat refused: the body is not the contract\'s shape.', [
                'node' => (string) $row->name,
                'path' => $e->path,
                'reason' => $e->reason,
            ]);

            return $this->error(422, 'validation_failed', $e->getMessage());
        }

        try {
            $outcome = $this->handler->handle($row, $body);
        } catch (Throwable $e) {
            report($e);
            Log::error('Tmesis fleet heartbeat not recorded; nothing acked, the node re-sends.', [
                'node' => (string) $row->name,
                'exception' => $e::class,
            ]);

            return $this->error(503, 'unavailable', 'The heartbeat could not be recorded.');
        }

        $request->attributes->set(DispatchAfterHeartbeat::OUTCOME_ATTRIBUTE, $outcome);

        return response()->json($outcome->body, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
