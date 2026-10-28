<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Support\ContractSchemas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The webhook receiver: store, acknowledge, then dispatch.
 *
 * Everything here answers 204. The middleware in front of it has already
 * authenticated the delivery, and every non-2xx costs the node 24 hours of retries,
 * so after that point a 2xx is the only correct answer to a signed request. A body
 * that will not decode, an event name this build has never heard of, a
 * `stream.sealed` with no prior `stream.published`, a stream never seen: all of them
 * are 204.
 *
 * Nothing is dispatched here. VerifyTmesisSignature::terminate() dispatches after the
 * response has been sent, so the 204 leaves before any listener runs: the node's
 * per-attempt timeout is 10 seconds and a slow listener would turn a working receiver
 * into a retry storm.
 */
class WebhookController
{
    public function __construct(private readonly DeliveryDeduper $deduper) {}

    public function __invoke(Request $request): Response
    {
        $delivery = $request->attributes->get('tmesis.delivery');

        if (! $delivery instanceof ReceivedDelivery) {
            // Only reachable if the route lost its middleware.
            return response()->noContent();
        }

        $payload = $this->decode($delivery);

        if ($payload === null) {
            Log::warning('Tmesis webhook body did not decode as JSON.', [
                'node' => $delivery->node,
                'delivery' => $delivery->deliveryId,
                'event' => $delivery->event,
            ]);

            // Signed by the node, so it is not an attack. Nothing is claimed and
            // nothing is dispatched: a non-2xx would only buy 24 hours of retries of
            // a body that will never decode.
            return response()->noContent();
        }

        $this->validateAgainstSchema($delivery);

        $deliveryId = $this->dedupeKey($delivery, $payload);

        if (! $this->deduper->claim($deliveryId, $delivery, $payload)) {
            // A duplicate is normal traffic on an at-least-once channel.
            return response()->noContent();
        }

        $request->attributes->set(
            VerifyTmesisSignature::EVENT_ATTRIBUTE,
            EventMap::make($payload, $delivery)
        );
        $request->attributes->set(VerifyTmesisSignature::DELIVERY_ATTRIBUTE, $deliveryId);

        return response()->noContent();
    }

    /**
     * Check the body against the schema this build was pinned to, when the consumer
     * asked for it.
     *
     * Off by default, and LOUD rather than fatal when it is on: every schema is
     * additionalProperties:false, so a newer node's payload fails a pinned schema by
     * design. That failure is a repin signal for the operator, never a reason to
     * refuse a delivery and buy 24 hours of retries.
     *
     * An event with no bundled schema is skipped in silence. The catalog is additive,
     * so a name from a newer node is expected traffic. A name that is not a catalog
     * NAME at all is logged and skipped, not thrown: it is still a 204.
     *
     * Validated from the raw bytes the signature covered, never from the decoded
     * array: a decoded array has turned every empty object into an empty list.
     */
    private function validateAgainstSchema(ReceivedDelivery $delivery): void
    {
        if (! config('tmesis.contract.validate_incoming', false) || $delivery->event === '') {
            return;
        }

        try {
            // Inside the try, and not before it: the event name comes from
            // `X-Tmesis-Event`, which the signature does not cover, so path() refuses
            // a name that is not a catalog name rather than building a path out of it.
            if (! is_file(ContractSchemas::path($delivery->event))) {
                return;
            }

            $error = ContractSchemas::validate($delivery->event, $delivery->rawBody);
        } catch (Throwable $e) {
            Log::error('Tmesis cannot validate incoming bodies: '.$e->getMessage());

            return;
        }

        if ($error === null) {
            return;
        }

        Log::error('Tmesis webhook body does not validate against its bundled schema.', [
            'node' => $delivery->node,
            'delivery' => $delivery->deliveryId,
            'event' => $delivery->event,
            'error' => $error,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(ReceivedDelivery $delivery): ?array
    {
        try {
            $payload = json_decode($delivery->rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($payload) ? $payload : null;
    }

    /**
     * The body's `delivery` wins over the header.
     *
     * The header is NOT covered by the signature and the body is, so a replay of a
     * captured delivery inside the 300 second window with a rewritten header must
     * still dedupe. A mismatch is logged and never rejected: the contract says the
     * two match, so a disagreement is a node bug to report rather than a delivery
     * worth 24 hours of retries.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dedupeKey(ReceivedDelivery $delivery, array $payload): string
    {
        $fromBody = $payload['delivery'] ?? null;

        if (! is_string($fromBody) || $fromBody === '') {
            return $delivery->deliveryId;
        }

        if ($delivery->deliveryId !== '' && $delivery->deliveryId !== $fromBody) {
            Log::warning('Tmesis delivery id disagrees between header and body.', [
                'node' => $delivery->node,
                'header_delivery' => $delivery->deliveryId,
                'body_delivery' => $fromBody,
                'event' => $delivery->event,
            ]);
        }

        return $fromBody;
    }
}
