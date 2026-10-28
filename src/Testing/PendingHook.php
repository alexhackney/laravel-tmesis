<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Support\ContractSchemas;
use AlexHackney\Tmesis\Support\Rfc3339;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\HttpFoundation\Response;

/**
 * A synthetic signed `publish.verify` hook, for a consuming app's own tests.
 *
 * The same shape as PendingWebhook and a different secret: the node signs the hook
 * with `ingest.verify_hook.secret`, which may be set separately from its webhook
 * secret. A consumer's test needs `tmesis.hooks.enabled` true; nothing else, because
 * a hook is never stored or deduped and so needs no delivery store.
 *
 * `decision()` decodes the answer, which is what a decider test actually asserts on.
 */
class PendingHook
{
    private ?string $node = null;

    private ?string $deliveryId = null;

    private ?DateTimeInterface $at = null;

    private ?string $secret = null;

    private string $event = HookRequest::EVENT;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private array $payload = []) {}

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

    public function at(DateTimeInterface $attemptTime): self
    {
        $this->at = $attemptTime;

        return $this;
    }

    /**
     * Sign with something other than the node's configured hook secret, which is how
     * a test proves the endpoint rejects a bad signature.
     */
    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * Send a different X-Tmesis-Event, which the endpoint must refuse. There is no
     * second hook at this tag.
     */
    public function asEvent(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Fill in the fields a test did not set, so a caller writes only what it cares
     * about.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): self
    {
        $this->payload = $payload + $this->payload;

        return $this;
    }

    /**
     * Post it through the application's HTTP kernel and answer with the response.
     *
     * terminate() is always called, because PublishHookDecided is dispatched there:
     * a helper that skipped it would answer correctly and dispatch nothing.
     */
    public function deliverTo(?Application $app = null): Response
    {
        $app ??= app();

        $registry = $app->make(NodeRegistry::class);

        $node = $this->node ?? $registry->defaultName();
        $deliveryId = $this->deliveryId ??= (string) Str::ulid();
        $at = $this->at ?? now();

        $payload = $this->payload;
        $payload['event'] ??= HookRequest::EVENT;
        $payload['delivery'] ??= $deliveryId;
        $payload['node'] ??= $node;
        $payload['node_role'] ??= 'standalone';
        $payload['contract_version'] ??= 1;
        $payload['occurred_at'] ??= Rfc3339::format($at);
        $payload['stream'] ??= 'fake-stream';
        $payload['key_type'] ??= HookRequest::KEY_TYPE_SIGNED;
        $payload['key_id'] ??= '';
        $payload['key_fragment'] ??= 'fake-stream.1.0000...0000';
        $payload['key_epoch'] ??= 1;
        $payload['protocol'] ??= HookRequest::PROTOCOL_SRT;
        $payload['remote'] ??= '198.51.100.1:0';
        $payload['session'] ??= (string) Str::ulid();
        $payload['open_posture'] ??= false;
        $payload['takeover'] ??= false;

        // Serialised once: the bytes signed are the bytes posted.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $timestamp = (string) $at->getTimestamp();
        $secret = $this->secret ?? $registry->hookSecret($node);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'tmesis/fake (+node='.$node.')',
            'HTTP_X_TMESIS_EVENT' => $this->event,
            'HTTP_X_TMESIS_DELIVERY' => $deliveryId,
            'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
            'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign($secret, $timestamp, $body),
            'HTTP_X_TMESIS_NODE' => $node,
            'HTTP_X_TMESIS_ATTEMPT' => '1',
        ];

        $request = Request::create(route('tmesis.hooks.publish'), 'POST', [], [], [], $server, $body);

        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * The decoded answer: `allow`, and `reason` or `patch` when the decider set them.
     *
     * When `opis/json-schema` is installed the raw answer is validated against the
     * bundled `publish.verify.response` schema first (the response is contract since
     * tmesis `v0.6.0`), and a body the node would not accept fails the test. The
     * failure names the schema path, never the body: a patch can carry a relay URL.
     *
     * @return array<string, mixed>
     */
    public function decision(?Application $app = null): array
    {
        $content = (string) $this->deliverTo($app)->getContent();

        if (ContractSchemas::validatorInstalled()) {
            $error = ContractSchemas::validate(HookDecision::RESPONSE_SCHEMA, $content);

            if ($error !== null) {
                PHPUnit::fail('The publish hook answered a body that is not a valid '.HookDecision::RESPONSE_SCHEMA.": {$error}");
            }
        }

        $decoded = json_decode($content, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
