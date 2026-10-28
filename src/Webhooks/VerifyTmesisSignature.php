<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Events\PublishHookDecided;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NodeResolverException;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Verifies an inbound delivery, and dispatches it after the response has been sent.
 *
 * Two halves:
 *
 *  - handle() authenticates. Every failure answers 401 with a small JSON body naming
 *    the reason. Every non-2xx is retried by the node for 24 hours, so nothing else
 *    in the receiver ever answers non-2xx, with one exception: when the node's
 *    secret cannot be LOOKED UP (the node resolver threw, or is misconfigured) the
 *    answer is 503, because the delivery may well be genuine and a retry after the
 *    store is back is exactly right, where a 401 would be the same answer as a forgery.
 *  - terminate() does the work. The 204 is the acknowledgement, and it must leave
 *    before listeners run: the node's per-attempt timeout is 10 seconds.
 *
 * Why terminate() and not something else. `Kernel::terminateMiddleware()` calls
 * terminate() on every route middleware exactly once per request, in every supported
 * Laravel version and inside the test helper `$this->call()`. The container's own
 * `terminating()` callbacks are never cleared, so a closure registered by one request
 * would run again on the next one in the same process, which every double-post test
 * and every long-lived worker would hit. `defer()` is not an alternative either: a
 * consumer on a legacy `app/Http/Kernel.php` has no `InvokeDeferredCallbacks` and the
 * callback would simply never run.
 */
class VerifyTmesisSignature
{
    /** The skew window, in seconds. */
    public const WINDOW_SECONDS = 300;

    /** The request attribute the controller stashes the built event under. */
    public const EVENT_ATTRIBUTE = 'tmesis.event';

    /** The request attribute the controller stashes the resolved dedupe key under. */
    public const DELIVERY_ATTRIBUTE = 'tmesis.delivery_id';

    /**
     * The request attribute the hook controller stashes its decision record under.
     *
     * A hook takes this path rather than the container's terminating() callbacks for
     * the reason above: nothing dedupes a hook and nothing claims it, so all
     * terminate() does with one is dispatch it after the answer has left.
     */
    public const HOOK_ATTRIBUTE = 'tmesis.hook_decided';

    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly DeliveryDispatcher $dispatcher,
    ) {}

    public function handle(Request $request, Closure $next, string $purpose = 'webhook'): SymfonyResponse
    {
        // Read the body first, before anything can parse or mutate the request. The
        // signature covers the exact bytes received.
        $rawBody = $request->getContent();

        $node = (string) $request->header('X-Tmesis-Node', '');
        $deliveryId = (string) $request->header('X-Tmesis-Delivery', '');

        // A missing or unknown node answers bad_signature: there is no secret to
        // verify against, and naming the real reason would let an unauthenticated
        // caller enumerate the configured node names. The log line says unknown_node.
        // A node the resolver has stopped knowing (spun down) is unknown the same way.
        try {
            $known = $node !== '' && $this->registry->has($node);
        } catch (NodeResolverException|InvalidConfigurationException $e) {
            return $this->unavailable($request, $e, $node, $deliveryId);
        }

        if (! $known) {
            return $this->reject($request, 'bad_signature', 'unknown_node', $node, $deliveryId);
        }

        $timestamp = (string) $request->header('X-Tmesis-Timestamp', '');

        if (preg_match('/^[0-9]+\z/', $timestamp) !== 1) {
            return $this->reject($request, 'bad_timestamp', 'bad_timestamp', $node, $deliveryId);
        }

        $presented = (string) $request->header('X-Tmesis-Signature', '');

        if ($presented === '') {
            return $this->reject($request, 'missing_signature', 'missing_signature', $node, $deliveryId);
        }

        $purpose = SignaturePurpose::fromName($purpose);

        try {
            $secret = match ($purpose) {
                SignaturePurpose::Hook => $this->registry->hookSecret($node),
                SignaturePurpose::Keyterms => $this->registry->keytermsHookSecret($node),
                SignaturePurpose::Webhook => $this->registry->webhookSecret($node),
            };
        } catch (NodeResolverException|InvalidConfigurationException $e) {
            return $this->unavailable($request, $e, $node, $deliveryId);
        }

        // The keyterms hook is a GET and the node signs it over an EMPTY body, so it is
        // verified over '' whatever arrived: a webhook's signature (over its JSON body)
        // can never be replayed here with that body attached.
        $signed = $purpose === SignaturePurpose::Keyterms ? '' : $rawBody;

        if (! WebhookSignature::verify($secret, $timestamp, $signed, $presented)) {
            return $this->reject($request, 'bad_signature', 'bad_signature', $node, $deliveryId);
        }

        // The window is checked AFTER the signature, so an unsigned request cannot be
        // used to probe where the window sits.
        if (abs(now()->getTimestamp() - (int) $timestamp) > self::WINDOW_SECONDS) {
            return $this->reject($request, 'stale_timestamp', 'stale_timestamp', $node, $deliveryId);
        }

        $request->attributes->set(
            'tmesis.delivery',
            ReceivedDelivery::fromRequest($request, new DateTimeImmutable('@'.now()->getTimestamp()))
        );

        return $next($request);
    }

    /**
     * After the response has left, dispatch and settle the claim.
     *
     * Under PHP-FPM (`fastcgi_finish_request` in `Response::send()`) and under Octane
     * the client already has its 204 here. Under mod_php or `php artisan serve` the
     * client waits for this to finish, which is one more reason for a consumer to
     * queue its own listeners.
     */
    public function terminate(Request $request, SymfonyResponse $response): void
    {
        $this->dispatchHookDecision($request);

        $event = $request->attributes->get(self::EVENT_ATTRIBUTE);
        $deliveryId = $request->attributes->get(self::DELIVERY_ATTRIBUTE);

        // Nothing stashed: a rejected delivery, a duplicate, an undecodable body, or
        // a route that does not dispatch. All of those are already finished.
        if (! $event instanceof TmesisWebhookReceived || ! is_string($deliveryId) || $deliveryId === '') {
            return;
        }

        // A throwing listener keeps the claim and the stored payload, unprocessed and
        // with the failure recorded. The node has its 204 and never retries, so
        // dropping the claim here would lose the delivery; tmesis:webhooks:retry
        // dispatches it again.
        $this->dispatcher->dispatch($deliveryId, $event);
    }

    /**
     * Dispatch the publish hook's decision record, once the answer has been sent.
     *
     * Never rethrows: the node already has its decision, and a listener that fails
     * must not turn an answered hook into an error the node reads as no answer.
     */
    private function dispatchHookDecision(Request $request): void
    {
        $decided = $request->attributes->get(self::HOOK_ATTRIBUTE);

        if (! $decided instanceof PublishHookDecided) {
            return;
        }

        try {
            Event::dispatch($decided);
        } catch (Throwable $e) {
            report($e);

            Log::error('Tmesis publish hook listener failed.', [
                'stream' => $decided->stream(),
                'key_fragment' => $decided->keyFragment(),
                'allowed' => $decided->allowed(),
            ]);
        }
    }

    /**
     * The node's secret could not be looked up: 503, so the node retries.
     *
     * Logged with this package's own message, which names the resolver class, the
     * node and the class of what was thrown and never the thrown message or a secret.
     * The original is not reported from here, because a driver's message can carry
     * a DSN; it is the previous exception for a consumer's own resolver to log.
     */
    private function unavailable(
        Request $request,
        NodeResolverException|InvalidConfigurationException $e,
        string $node,
        string $deliveryId,
    ): Response {
        Log::error('Tmesis webhook deferred: the node could not be looked up.', [
            'node' => $node,
            'delivery' => $deliveryId,
            'event' => (string) $request->header('X-Tmesis-Event', ''),
            'reason' => 'node_lookup_failed',
            'error' => $e->getMessage(),
        ]);

        return new Response(
            ['error' => ['code' => 'node_lookup_failed']],
            SymfonyResponse::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'application/json'],
        );
    }

    private function reject(
        Request $request,
        string $code,
        string $reason,
        string $node,
        string $deliveryId,
    ): Response {
        Log::warning('Tmesis webhook rejected.', [
            'node' => $node,
            'delivery' => $deliveryId,
            'reason' => $reason,
            'event' => (string) $request->header('X-Tmesis-Event', ''),
        ]);

        return new Response(
            ['error' => ['code' => $code]],
            SymfonyResponse::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/json'],
        );
    }
}
