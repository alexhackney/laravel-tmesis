<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\ErrorResponse;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\ConnectionException;
use AlexHackney\Tmesis\Exceptions\ErrorMap;
use AlexHackney\Tmesis\Exceptions\InsecureTransportException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * The conventions layer: one place that knows how to talk to a node.
 *
 * Base URL, bearer token, JSON, timeouts, path encoding, and the non-2xx to
 * exception translation all live here, so a resource method is a path and a DTO.
 *
 * The URL and the token are read through NodeRegistry's guarded accessors and never
 * off the NodeConfig directly. That is the required-at-use rule: a Doppler `""`
 * throws InvalidConfigurationException naming the key BEFORE a request is built,
 * rather than going out as an empty bearer and coming back as an unexplained 401.
 */
class Transport
{
    private ?string $requestId = null;

    private bool $authenticated = true;

    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly NodeConfig $node,
    ) {}

    /**
     * Send an `X-Request-Id` with the next request, so a call can be correlated with
     * the node's own logs.
     */
    public function withRequestId(string $requestId): self
    {
        $clone = clone $this;
        $clone->requestId = $requestId;

        return $clone;
    }

    /**
     * Send the next request with NO bearer token.
     *
     * For the one operation whose `security` lists the empty requirement: `getHealth`
     * (`[{}, {adminToken: []}]` since tmesis `v0.2.0`), which answers the liveness-only
     * HealthPublic to a request without a token. The token is not read at all, so a
     * node configured for probing only (a blank token) can still be probed.
     */
    public function withoutToken(): self
    {
        $clone = clone $this;
        $clone->authenticated = false;

        return $clone;
    }

    public function node(): NodeConfig
    {
        return $this->node;
    }

    /**
     * The Guzzle `verify` option for `tmesis.http.verify`, or the reason it is refused.
     *
     * The primary path needs none of this: a node on `api.tls.domain` serves its own
     * ACME certificate, which is publicly trusted, so `true` (the default) verifies it
     * against the system store with nothing to configure. A CA bundle path is for the
     * node's file-pair path (a certificate from a private CA) and for labs. `false`
     * is refused outside the `local` and `testing` environments: a control plane must
     * not send a bearer to an unverified peer. There is no client certificate option;
     * the node's admin API takes no mTLS.
     *
     * Pure: the config value and the app environment in, the option out.
     *
     * @throws InvalidConfigurationException
     */
    public static function resolveVerify(mixed $configured, string $environment): bool|string
    {
        $key = 'tmesis.http.verify';

        if ($configured === null || $configured === true || $configured === 'true' || $configured === '') {
            return true;
        }

        if ($configured === false || $configured === 'false') {
            if (in_array($environment, ['local', 'testing'], true)) {
                return false;
            }

            throw InvalidConfigurationException::invalid(
                $key,
                "false is refused in the [{$environment}] environment (local and testing only): "
                .'a control plane must not send a bearer to an unverified peer'
            );
        }

        if (! is_string($configured)) {
            throw InvalidConfigurationException::invalid($key, 'must be true, false, or an absolute path to a CA bundle');
        }

        if (! str_starts_with($configured, '/') || ! is_file($configured) || ! is_readable($configured)) {
            throw InvalidConfigurationException::invalid(
                $key,
                "[{$configured}] is not an absolute path to a readable CA bundle file"
            );
        }

        return $configured;
    }

    /**
     * GET a contract path.
     *
     * `$path` is an openapi `paths` key with its ids substituted, so it already
     * carries `/api/v1`; a node's configured url is scheme, host and port only.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get($path, $this->query($query)));

        return $this->decode($response);
    }

    /**
     * GET a contract path whose 200 is not JSON, and answer the raw body.
     *
     * `$accept` replaces the JSON `Accept` for this request only. A non-2xx still goes
     * through the same error translation: the node's error bodies are JSON whatever
     * was asked for, so there is no second error path.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function getText(string $path, array $query, string $accept): string
    {
        $response = $this->send(fn (PendingRequest $request) => $request->replaceHeaders(['Accept' => $accept])->get($path, $this->query($query)));

        return $response->body();
    }

    /**
     * POST a contract path.
     *
     * A null body sends NO body at all, which is what `kick` and `takeover` want:
     * both read no request body, and sending `{}` to a route that reads none is a
     * request that says something the contract does not define.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, scalar|null>  $query
     */
    public function post(string $path, ?array $body = null, array $query = []): TransportResult
    {
        return $this->sendJson('POST', $path, $body, $query);
    }

    /**
     * PATCH a contract path.
     *
     * The body is a PARTIAL document: an omitted field is not mentioned, and a field
     * set to null is mentioned and cleared (`docs/API.md` 9.6). Nothing here adds a
     * key the caller did not set, which is why StreamSpec tracks what was set rather
     * than defaulting.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, scalar|null>  $query
     */
    public function patch(string $path, array $body, array $query = []): TransportResult
    {
        return $this->sendJson('PATCH', $path, $body, $query);
    }

    /**
     * PUT a contract path.
     *
     * A PUT body is the WHOLE document the route stores (a transfer plan revision, an
     * authorization refresh), never a partial one.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, scalar|null>  $query
     */
    public function put(string $path, array $body, array $query = []): TransportResult
    {
        return $this->sendJson('PUT', $path, $body, $query);
    }

    /**
     * DELETE a contract path.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function delete(string $path, array $query = []): TransportResult
    {
        return $this->sendJson('DELETE', $path, null, $query);
    }

    /**
     * Path ids, encoded. A stream id is already restricted to a path-safe charset,
     * but a relay id comes from the node and is encoded on principle.
     */
    public function path(string $template, string ...$ids): string
    {
        foreach ($ids as $id) {
            $position = strpos($template, '%s');

            if ($position === false) {
                break;
            }

            $template = substr_replace($template, rawurlencode($id), $position, 2);
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, scalar|null>  $query
     */
    private function sendJson(string $method, string $path, ?array $body, array $query): TransportResult
    {
        $options = [];

        if ($query !== []) {
            $options['query'] = $this->query($query);
        }

        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->send(fn (PendingRequest $request) => $request->send($method, $path, $options));

        return new TransportResult($response->status(), $this->decode($response));
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     */
    private function send(callable $send): Response
    {
        try {
            $response = $send($this->request());
        } catch (LaravelConnectionException $e) {
            throw new ConnectionException(
                'Could not reach the Tmesis node ['.$this->node->name.']: '.$e->getMessage(),
                null,
                null,
                $this->requestId,
                $this->node->name,
                null,
                $e,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw ErrorMap::toException(
            $response->status(),
            ErrorResponse::tryFromResponse($response),
            $response->headers(),
        );
    }

    /**
     * Where an admin call to `$url` would send the bearer token in clear, as
     * `scheme://host` (no port, path or userinfo), or null when it would not: `https`,
     * or `http` to a loopback host. Pure.
     */
    public static function cleartextTarget(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme === 'https' || ($scheme === 'http' && self::isLoopback($host))) {
            return null;
        }

        return ($scheme === '' ? 'no scheme' : $scheme).'://'.$host;
    }

    /**
     * Whether `$host` is this box: `localhost`, 127.0.0.0/8, `::1`, or an IPv4-mapped
     * 127.0.0.0/8. A bracketed IPv6 literal is accepted as `parse_url()` returns it.
     */
    public static function isLoopback(string $host): bool
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = (string) inet_pton($host);

        if (strlen($packed) === 4) {
            return ord($packed[0]) === 127;
        }

        return $packed === inet_pton('::1')
            || (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") && ord($packed[12]) === 127);
    }

    /**
     * Whether the environment may send the bearer over cleartext to any host: `local`
     * and `testing` only, the rule `resolveVerify()` applies to `http.verify=false`.
     */
    public static function allowsCleartext(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }

    /**
     * @throws InsecureTransportException
     */
    private function request(): PendingRequest
    {
        $url = $this->registry->url($this->node);

        // Refused before anything is built or sent. A tokenless request (getHealth's
        // liveness) carries no credential, so it is not refused.
        if ($this->authenticated
            && ! self::allowsCleartext((string) app()->environment())
            && ($target = self::cleartextTarget($url)) !== null) {
            throw InsecureTransportException::forNode($this->node->name, $target);
        }

        $request = Http::baseUrl($url)
            ->acceptJson()
            ->timeout((int) config('tmesis.http.timeout', 10))
            ->connectTimeout((int) config('tmesis.http.connect_timeout', 5))
            ->withOptions(['verify' => self::resolveVerify(config('tmesis.http.verify', true), (string) app()->environment())]);

        if ($this->authenticated) {
            $request = $request->withToken($this->registry->token($this->node));
        } else {
            // TOKENLESS MEANS NO Authorization AT ALL, not merely none of ours. A
            // consuming app can put one on every request it makes: Http::globalOptions()
            // headers or `auth`, or Http::globalRequestMiddleware(). Either would send a
            // HOST credential to the node on the one request that is meant to carry none.
            // Guzzle runs middleware in push order and the globals are pushed first, so
            // this runs after them and strips whatever they set; `auth` is cleared too
            // so no curl-level scheme (digest, ntlm) can add one below the stack.
            $request = $request
                ->withOptions(['auth' => null])
                ->withRequestMiddleware(
                    static fn (RequestInterface $outgoing): RequestInterface => $outgoing->withoutHeader('Authorization')
                );
        }

        if ($this->requestId !== null) {
            $request = $request->withHeader('X-Request-Id', $this->requestId);
        }

        return $request;
    }

    /**
     * Query values go out as strings: the node parses `checks=true` and `limit=200`,
     * not PHP's `checks=1`. A null value is dropped rather than sent empty.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, string>
     */
    private function query(array $query): array
    {
        $out = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            $out[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $out;
    }

    /**
     * Unknown response fields are kept, not dropped: every DTO holds the raw array
     * and reads keys by name, so a field from a newer node survives.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
