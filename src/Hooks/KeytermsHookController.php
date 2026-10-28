<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Hooks;

use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;
use AlexHackney\Tmesis\Exceptions\InvalidKeytermsException;
use AlexHackney\Tmesis\Support\Keyterms;
use AlexHackney\Tmesis\Webhooks\ReceivedDelivery;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The keyterms hook (tmesis `v0.8.0`, `docs/WEBHOOKS.md` 13): the node GETs a
 * captioning stream's keyterms, signed with its keyterms hook secret over the
 * timestamp and an EMPTY body (VerifyTmesisSignature with the `keyterms` purpose has
 * already checked that); this asks the KeytermsProvider and answers
 * `200 {"keyterms": [...]}` (`keyterms.hook.response.schema.json`).
 *
 * What the node does with each answer decides what this may send:
 *
 *  - `200`, `204` and `404` are ANSWERS, and any answer, none included, REPLACES the
 *    stream's `captions.keyterms` for the session (decision D14). So nothing here
 *    answers none unless the provider said none: a provider that throws is `503`,
 *    and a list the node would ignore whole is refused here (Support\Keyterms, the
 *    node's rules in one place) and answered `500`. Both keep the list in force; the node counts the failure
 *    and writes `caption.keyterms_hook_failed` on the stream's timeline, and captions
 *    are never interrupted.
 *  - With `tmesis.hooks.keyterms.enabled` false the route still exists and a signed
 *    request is `503 keyterms_disabled`, never a 404: a node pointed at a
 *    switched-off control plane keeps its list rather than clearing it.
 *  - `400` is for a request that is not a keyterms hook at all: a wrong
 *    X-Tmesis-Event or a query missing `stream`, `session` or `lang`.
 *
 * Nothing here is stored, deduped or dispatched. No log line carries a keyterm, the
 * query beyond the stream, or a thrown message (a provider's message can carry a
 * DSN).
 */
class KeytermsHookController
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(Request $request): Response
    {
        $delivery = $request->attributes->get('tmesis.delivery');

        if (! $delivery instanceof ReceivedDelivery) {
            // Only reachable if the route lost its middleware.
            return $this->refuse('bad_request', 'the keyterms hook route is not signed');
        }

        // Switched off: still an answer that KEEPS the node's list. A 404 or 204 would
        // clear it, so the route is always registered and refuses here, after the
        // signature check, so it says nothing to a caller that cannot sign.
        if (! self::enabled()) {
            Log::warning('Tmesis keyterms hook called while hooks.keyterms.enabled is false; answered 503, the node keeps its list.', [
                'node' => $delivery->node,
                'delivery' => $delivery->deliveryId,
            ]);

            return $this->error('keyterms_disabled', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($delivery->event !== KeytermsHookRequest::EVENT) {
            Log::warning('Tmesis keyterms hook called with an unexpected event.', [
                'node' => $delivery->node,
                'delivery' => $delivery->deliveryId,
                'event' => $delivery->event,
            ]);

            return $this->refuse('unknown_hook', "[{$delivery->event}] is not a hook this endpoint answers");
        }

        $hook = KeytermsHookRequest::fromQuery($delivery->node, $delivery->deliveryId, $request->query());

        if ($hook === null) {
            Log::warning('Tmesis keyterms hook query is missing stream, session or lang.', [
                'node' => $delivery->node,
                'delivery' => $delivery->deliveryId,
            ]);

            return $this->refuse('bad_request', 'the keyterms hook query needs stream, session and lang');
        }

        try {
            $terms = $this->provider()->keyterms($hook);
        } catch (Throwable $e) {
            Log::error('Tmesis keyterms provider failed; the node keeps the list in force.', [
                'node' => $hook->node,
                'delivery' => $hook->deliveryId,
                'stream' => $hook->stream,
                'lang' => $hook->lang,
                'provider' => self::configuredProvider(),
                'exception' => $e::class,
            ]);

            return $this->error('keyterms_unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $keyterms = Keyterms::normalize($terms);

        if ($keyterms === null) {
            $e = InvalidKeytermsException::refused((string) Keyterms::problem($terms));
            report($e);

            Log::error('Tmesis keyterms provider answered a list the node would ignore; the node keeps the list in force.', [
                'node' => $hook->node,
                'delivery' => $hook->deliveryId,
                'stream' => $hook->stream,
                'lang' => $hook->lang,
                'provider' => self::configuredProvider(),
                'reason' => $e->getMessage(),
            ]);

            return $this->error('invalid_keyterms', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(['keyterms' => $keyterms], Response::HTTP_OK, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Read at answer time, like the publish hook's decider, so a test can swap it.
     */
    private function provider(): KeytermsProvider
    {
        $configured = self::configuredProvider() ?? NoKeyterms::class;
        $provider = $this->container->make($configured);

        if (! $provider instanceof KeytermsProvider) {
            throw new RuntimeException("tmesis.hooks.keyterms.provider [{$configured}] does not implement ".KeytermsProvider::class);
        }

        return $provider;
    }

    /**
     * `tmesis.hooks.keyterms.enabled`, read per request: the route is always
     * registered, so a test or a deploy flips the switch without re-registering it.
     */
    private static function enabled(): bool
    {
        return filter_var(config('tmesis.hooks.keyterms.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function configuredProvider(): ?string
    {
        $configured = config('tmesis.hooks.keyterms.provider');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    private function error(string $code, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code]], $status);
    }

    private function refuse(string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], Response::HTTP_BAD_REQUEST);
    }
}
