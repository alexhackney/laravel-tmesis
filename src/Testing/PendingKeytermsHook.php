<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Data\Hooks\KeytermsHookRequest;
use AlexHackney\Tmesis\Support\ContractSchemas;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\HttpFoundation\Response;

/**
 * A synthetic signed keyterms hook GET (tmesis `v0.8.0`), for a consuming app's
 * tests of its own KeytermsProvider through the REAL route.
 *
 * Signed the way the node signs it: the node's keyterms hook secret (falling back to
 * its webhook secret) over the timestamp and an EMPTY body, the query not covered. A
 * consumer's test needs `tmesis.hooks.keyterms.enabled` true and a node configured
 * (or resolved) under the name it sends; nothing is stored, so no migration. The
 * switch is read per request, so `config()->set()` inside the test is enough; with it
 * off the route answers `503 keyterms_disabled` (assertKeptInForce() passes), never a
 * 404.
 *
 * ```php
 * FakeTmesis::keytermsHook('show-2041')->assertKeyterms(['Hector Rodriguez', 'UFC']);
 * ```
 */
class PendingKeytermsHook
{
    private ?string $node = null;

    private ?string $session = null;

    private string $lang = 'en';

    private ?string $deliveryId = null;

    private ?DateTimeInterface $at = null;

    private ?string $secret = null;

    private string $event = KeytermsHookRequest::EVENT;

    private ?Response $response = null;

    public function __construct(private string $stream = 'fake-stream') {}

    public function fromNode(string $node): self
    {
        $this->node = $node;

        return $this;
    }

    public function forSession(string $ulid): self
    {
        $this->session = $ulid;

        return $this;
    }

    public function inLanguage(string $lang): self
    {
        $this->lang = $lang;

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
     * Sign with something other than the node's keyterms hook secret, which is how a
     * test proves the route rejects a bad signature.
     */
    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * Send a different X-Tmesis-Event, which the route must refuse.
     */
    public function asEvent(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Send the GET through the application's HTTP kernel, terminate() included, and
     * answer with the response. Sent once per instance: a second call answers the
     * first response.
     */
    public function deliverTo(?Application $app = null): Response
    {
        if ($this->response !== null) {
            return $this->response;
        }

        $app ??= app();

        $registry = $app->make(NodeRegistry::class);

        $node = $this->node ?? $registry->defaultName();
        $deliveryId = $this->deliveryId ??= (string) Str::ulid();
        $timestamp = (string) ($this->at ?? now())->getTimestamp();
        $secret = $this->secret ?? $registry->keytermsHookSecret($node);

        $query = http_build_query([
            'stream' => $this->stream,
            'session' => $this->session ??= (string) Str::ulid(),
            'lang' => $this->lang,
        ], '', '&', PHP_QUERY_RFC3986);

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'tmesis/fake (+node='.$node.')',
            'HTTP_X_TMESIS_EVENT' => $this->event,
            'HTTP_X_TMESIS_DELIVERY' => $deliveryId,
            'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
            'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign($secret, $timestamp, ''),
            'HTTP_X_TMESIS_NODE' => $node,
            'HTTP_X_TMESIS_ATTEMPT' => '1',
        ];

        $request = Request::create(route('tmesis.hooks.keyterms').'?'.$query, 'GET', [], [], [], $server);

        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $this->response = $response;
    }

    /**
     * The list answered, held to what the node accepts: a `200` whose body validates
     * against the bundled `keyterms.hook.response` schema (when `opis/json-schema` is
     * installed). Any other status fails the test, naming it.
     *
     * @return list<string>
     */
    public function keyterms(?Application $app = null): array
    {
        $response = $this->deliverTo($app);
        $content = (string) $response->getContent();

        PHPUnit::assertSame(Response::HTTP_OK, $response->getStatusCode(), "The keyterms hook answered {$response->getStatusCode()}, not 200: {$content}");

        if (ContractSchemas::validatorInstalled()) {
            $error = ContractSchemas::validate(KeytermsHookRequest::RESPONSE_SCHEMA, $content);

            if ($error !== null) {
                PHPUnit::fail('The keyterms hook answered a body that is not a valid '.KeytermsHookRequest::RESPONSE_SCHEMA.": {$error}");
            }
        }

        $decoded = json_decode($content, true);
        $keyterms = is_array($decoded) && is_array($decoded['keyterms'] ?? null) ? $decoded['keyterms'] : [];

        /** @var list<string> */
        return array_values(array_filter($keyterms, 'is_string'));
    }

    /**
     * @param  list<string>  $expected  in order
     */
    public function assertKeyterms(array $expected, ?Application $app = null): self
    {
        PHPUnit::assertSame($expected, $this->keyterms($app), 'The keyterms hook did not answer the expected list.');

        return $this;
    }

    /**
     * That the route answered a 5xx, so the node keeps the list in force: a provider
     * that threw (`503`) or a list the node would ignore whole (`500`).
     */
    public function assertKeptInForce(?Application $app = null): self
    {
        $status = $this->deliverTo($app)->getStatusCode();

        PHPUnit::assertTrue($status >= 500 && $status < 600, "The keyterms hook answered {$status}; the node keeps its list in force only on a 5xx (or a timeout).");

        return $this;
    }

    public function assertStatus(int $status, ?Application $app = null): self
    {
        PHPUnit::assertSame($status, $this->deliverTo($app)->getStatusCode());

        return $this;
    }
}
