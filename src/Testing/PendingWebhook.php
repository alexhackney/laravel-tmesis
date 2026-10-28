<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Support\Rfc3339;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A synthetic signed delivery, for a consuming app's own tests.
 *
 * The payload is whatever the caller gives and is NOT validated against a schema.
 * A test that wants a schema-valid body copies one of this package's payload
 * fixtures, or from v0.2 a captured one.
 *
 * A consumer's test needs `tmesis.webhooks.enabled` true and a dedupe store. The
 * documented choice for tests is the `cache` driver on the `array` store, which needs
 * no migration.
 */
class PendingWebhook
{
    private ?string $node = null;

    private ?string $deliveryId = null;

    private ?DateTimeInterface $at = null;

    private int $attempt = 1;

    private ?string $secret = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $event,
        private array $payload,
    ) {}

    public function fromNode(string $node): self
    {
        $this->node = $node;

        return $this;
    }

    public function withDeliveryId(string $ulid): self
    {
        $this->deliveryId = $ulid;

        return $this;
    }

    /**
     * The attempt time, which is what the timestamp header and the signature use.
     */
    public function at(DateTimeInterface $attemptTime): self
    {
        $this->at = $attemptTime;

        return $this;
    }

    public function attempt(int $attempt): self
    {
        $this->attempt = $attempt;

        return $this;
    }

    /**
     * Sign with something other than the node's configured secret, which is how a
     * test proves its receiver rejects a bad signature.
     */
    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * Deliver through the application's HTTP kernel.
     *
     * The kernel's terminate() is ALWAYS called, because the dispatch lives in the
     * route middleware's terminate(): a deliverTo() that skipped it would answer 204
     * and dispatch nothing, which would make every consumer's listener test fail for
     * a reason that has nothing to do with their code.
     *
     * Calling this twice on one PendingWebhook re-signs with a fresh timestamp and
     * the SAME delivery id, which is exactly what a node retry looks like.
     *
     * The application is optional: inside a test `deliverTo()` and
     * `deliverTo($this->app)` do the same thing. It is a parameter at all so a
     * caller holding a specific application instance can name it.
     */
    public function deliverTo(?Application $app = null): Response
    {
        $app ??= app();

        $registry = $app->make(NodeRegistry::class);

        $node = $this->node ?? $registry->defaultName();
        $deliveryId = $this->deliveryId ??= (string) Str::ulid();
        $at = $this->at ?? now();

        $payload = $this->payload;
        $payload['event'] ??= $this->event;
        $payload['delivery'] ??= $deliveryId;
        $payload['node'] ??= $node;
        $payload['contract_version'] ??= 1;
        $payload['occurred_at'] ??= Rfc3339::format($at);

        // Serialised ONCE. The bytes that are signed are the bytes that are posted;
        // re-encoding between the two is the mistake the vector file exists to catch.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $timestamp = (string) $at->getTimestamp();
        $secret = $this->secret ?? $registry->webhookSecret($node);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'tmesis/fake (+node='.$node.')',
            'HTTP_X_TMESIS_EVENT' => $this->event,
            'HTTP_X_TMESIS_DELIVERY' => $deliveryId,
            'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
            'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign($secret, $timestamp, $body),
            'HTTP_X_TMESIS_NODE' => $node,
            'HTTP_X_TMESIS_ATTEMPT' => (string) $this->attempt,
        ];

        $request = Request::create(route('tmesis.webhooks.receive'), 'POST', [], [], [], $server, $body);

        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
