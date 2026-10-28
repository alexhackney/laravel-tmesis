<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Hooks;

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Events\PublishHookDecided;
use AlexHackney\Tmesis\Support\ContractSchemas;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use AlexHackney\Tmesis\Webhooks\VerifyTmesisSignature;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The synchronous publish veto: decide, answer, then record.
 *
 * The node is holding a publish handshake open under a hard cap while this runs, and
 * `docs/WEBHOOKS.md` 12.4 and 12.5 turn that into five things this endpoint must never
 * do:
 *
 *  - never an empty body. A zero-byte 200 carries no JSON document, so the node lands
 *    on its on_timeout posture, which for a bare_unproven key is a forced deny.
 *  - never `200 {}`. That DECODES, and a decision object with no `allow` is a deny.
 *    Every answer here comes from HookDecision::toArray(), which always writes allow.
 *  - never a 5xx for a decision. A non-2xx tells the node NOTHING and is never read as
 *    a deny, so one broken deploy would black out every publish on the platform. A
 *    decider that throws answers the configured tmesis.hooks.on_error posture instead.
 *  - never I/O on the default path. The default decider reads nothing.
 *  - never let a listener add latency. PublishHookDecided is dispatched from the
 *    middleware's terminate(), after the response has left.
 *
 * A 400 is not a decision and is used only for a request that is not a
 * `publish.verify` at all: a wrong X-Tmesis-Event, or a body that will not decode.
 * The node reads it the way it reads any transport failure.
 *
 * Nothing here is deduped or stored. A hook is answered, not recorded, so the delivery
 * id is logged and never claimed.
 *
 * A decision's patch can carry relay URLs, which are credentials. No log line here
 * carries the decision at all, and PublishHookDecided hands listeners
 * redactedDecision(); the raw patch leaves this process only in the response body.
 */
class HookController
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(Request $request): Response
    {
        $delivery = $request->attributes->get('tmesis.delivery');

        if (! $delivery instanceof ReceivedDelivery) {
            // Only reachable if the route lost its middleware.
            return $this->refuse('bad_request', 'the hook route is not signed');
        }

        if ($delivery->event !== HookRequest::EVENT) {
            Log::warning('Tmesis publish hook called with an unexpected event.', [
                'node' => $delivery->node,
                'delivery' => $delivery->deliveryId,
                'event' => $delivery->event,
            ]);

            // publish.takeover does not exist at this tag and no other hook is
            // registered, so this is a node calling the wrong URL or a probe.
            return $this->refuse('unknown_hook', "[{$delivery->event}] is not a hook this endpoint answers");
        }

        $payload = $this->decode($delivery->rawBody);

        if ($payload === null) {
            Log::warning('Tmesis publish hook body did not decode as JSON.', [
                'node' => $delivery->node,
                'delivery' => $delivery->deliveryId,
            ]);

            return $this->refuse('bad_request', 'the hook body is not a JSON object');
        }

        $this->validateAgainstSchema($delivery);

        $hook = HookRequest::fromArray($payload);

        $started = hrtime(true);
        $decision = $this->decide($hook, $delivery);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->reportIfSlow($elapsedMs, $delivery);

        $request->attributes->set(
            VerifyTmesisSignature::HOOK_ATTRIBUTE,
            new PublishHookDecided($hook, $decision, $elapsedMs),
        );

        // toArray() always carries allow. See 12.4.
        return response()->json($decision->toArray());
    }

    private function decide(HookRequest $hook, ReceivedDelivery $delivery): HookDecision
    {
        try {
            $configured = config('tmesis.hooks.decider', AllowAllDecider::class);

            $decider = $this->container->make(is_string($configured) ? $configured : AllowAllDecider::class);

            if (! $decider instanceof PublishHookDecider) {
                throw new \RuntimeException(
                    'tmesis.hooks.decider ['.(is_string($configured) ? $configured : 'not a string')
                    .'] does not implement '.PublishHookDecider::class
                );
            }

            return $decider->decide($hook);
        } catch (Throwable $e) {
            report($e);

            $posture = (bool) config('tmesis.hooks.on_error', false);

            Log::error('Tmesis publish hook decider failed.', [
                'node' => $delivery->node,
                'delivery' => $delivery->deliveryId,
                'stream' => $hook->stream(),
                'key_fragment' => $hook->keyFragment(),
                'decider' => is_string(config('tmesis.hooks.decider')) ? config('tmesis.hooks.decider') : null,
                'answered' => $posture ? 'allow' : 'deny',
            ]);

            // A configured answer, not a 5xx. Handing the decision to the node's
            // on_timeout posture would be the one thing worse than being wrong here.
            return $posture
                ? HookDecision::allow(reason: 'decider failed')
                : HookDecision::deny('decider failed');
        }
    }

    /**
     * Validated from the raw bytes, never the decoded array (ContractSchemas::validate).
     */
    private function validateAgainstSchema(ReceivedDelivery $delivery): void
    {
        if (! config('tmesis.contract.validate_incoming', false)) {
            return;
        }

        try {
            $error = ContractSchemas::validate(HookRequest::EVENT, $delivery->rawBody);
        } catch (Throwable $e) {
            // The switch is on and the validator is not installed, or a schema is
            // missing from the install. That is a deployment problem, and it must not
            // become a 5xx: a non-2xx tells the node nothing about this publish.
            Log::error('Tmesis cannot validate incoming bodies: '.$e->getMessage());

            return;
        }

        if ($error === null) {
            return;
        }

        // Logged loudly and answered anyway. A body that stopped matching the pinned
        // schema is a repin signal, and refusing to decide about a live publish
        // because a field moved would be the wrong way to raise it.
        Log::error('Tmesis publish hook body does not validate against its bundled schema.', [
            'node' => $delivery->node,
            'delivery' => $delivery->deliveryId,
            'schema' => HookRequest::EVENT,
            'error' => $error,
        ]);
    }

    private function reportIfSlow(float $elapsedMs, ReceivedDelivery $delivery): void
    {
        $budget = (int) config('tmesis.hooks.slow_ms', 100);

        if ($budget <= 0 || $elapsedMs <= $budget) {
            return;
        }

        Log::warning('Tmesis publish hook decider is slow.', [
            'node' => $delivery->node,
            'delivery' => $delivery->deliveryId,
            'decider' => is_string(config('tmesis.hooks.decider')) ? config('tmesis.hooks.decider') : null,
            'elapsed_ms' => round($elapsedMs, 1),
            'budget_ms' => $budget,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $body): ?array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($payload) ? $payload : null;
    }

    /**
     * Not a decision: a request this endpoint cannot read as a publish.verify at all.
     *
     * The body is small and JSON so a receiver's own logs say what happened; the node
     * reads only the status code here.
     */
    private function refuse(string $code, string $message): Response
    {
        return response()->json(
            ['error' => ['code' => $code, 'message' => $message]],
            Response::HTTP_BAD_REQUEST,
        );
    }
}
