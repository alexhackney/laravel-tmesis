<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use AlexHackney\Tmesis\Fleet\JoinToken;
use AlexHackney\Tmesis\Webhooks\VerifyTmesisSignature;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * A new box fetches secret-bearing user-data through `install`, a separate config
 * through `config`, then reports what it installed through `register`.
 *
 * The bootstrap token identifies the enrolment on all three. The two GETs take it as
 * `Authorization: Bearer`, never in the query string (a URL lands in access logs,
 * proxies and shell history). `register` takes it in the body AND requires the
 * node's webhook signature over the raw body, made with the webhook secret minted
 * for that enrolment: a token seen in transit is not enough to register a host of
 * the attacker's choosing, because only the box that received its user-data has the
 * secret.
 *
 * An unknown and an expired token get the same `404 unknown_token`, so a caller
 * learns nothing about which tokens exist. Nothing here logs the token, the
 * document or a secret.
 */
class EnrollmentController
{
    public function __construct(
        private readonly Container $container,
        private readonly ConfigRenderer $renderer,
    ) {}

    /**
     * `POST {prefix}/enroll/register`, signed with `X-Tmesis-Timestamp` and
     * `X-Tmesis-Signature` as a webhook is: `204`, and the repository burns the token.
     *
     * The body is `{token, ...}` plus what `tmesis version --json` reports (DEPLOY.md
     * 5.6): `version`, and `api_listen`, `api_scheme`, `api_domain` when a config
     * loaded, from which NodeRegistration::deriveUrl() makes the admin URL. An
     * explicit `api_url` wins (a hand-run bootstrap, or a file-pair box whose
     * certificate names a host `api_listen` does not). A report that names no
     * dialable address registers with NO url: the node is receive-only, never
     * guessed at. Outside `local` and `testing` an `http` url to a host that is not
     * loopback is never stored: derived, the node is receive-only; given
     * as `api_url`, it is `422`. Optional `doctor` and `bench` objects are recorded
     * untouched.
     *
     * Order: the token (`404`), the signature over the exact received bytes and the
     * skew window (`401 invalid_signature`), the fields (`422`), then register(), a
     * compare-and-set on the token's sha (`404 unknown_token` when it no longer holds
     * a pending enrolment). Only register() burns, so every refusal leaves the token
     * pending.
     */
    public function register(Request $request): Response|JsonResponse
    {
        // The signature covers these bytes; nothing parses the request before them.
        $raw = $request->getContent();
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];

        $token = $body['token'] ?? null;

        if (! is_string($token) || $token === '') {
            return $this->unknownToken();
        }

        $repository = EnrollmentSeams::repository($this->container);
        $node = $this->pending($repository, $token);

        if ($node === null) {
            return $this->unknownToken();
        }

        if (! $this->signed($request, $raw, $node)) {
            return response()->json(
                ['error' => ['code' => 'invalid_signature', 'message' => 'The registration is not signed with this enrolment\'s webhook secret.']],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $version = $body['version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            return $this->invalid('version');
        }

        foreach (['api_url', 'api_listen', 'api_scheme', 'api_domain'] as $field) {
            if (isset($body[$field]) && ! is_string($body[$field])) {
                return $this->invalid($field);
            }
        }

        foreach (['doctor', 'bench'] as $field) {
            if (isset($body[$field]) && ! is_array($body[$field])) {
                return $this->invalid($field);
            }
        }

        if (isset($body['api_scheme']) && ! in_array($body['api_scheme'], ['http', 'https'], true)) {
            return $this->invalid('api_scheme');
        }

        $apiUrl = $body['api_url'] ?? null;

        if ($apiUrl !== null && ! NodeRegistration::isUrl($apiUrl)) {
            return $this->invalid('api_url');
        }

        // an admin url the package would refuse to send the bearer to. An
        // explicit one is the box's own fact, so it is refused like any other bad
        // field (the token stays pending; the box can register again over https); a
        // derived one makes the node receive-only, as any undialable report does.
        $cleartext = Transport::allowsCleartext((string) app()->environment());

        if ($apiUrl !== null && ! $cleartext && Transport::cleartextTarget($apiUrl) !== null) {
            return $this->invalid('api_url', 'it is http to a host that is not loopback, and the admin token is never sent in clear outside local and testing; register https (the node\'s api.tls.domain)');
        }

        $apiUrl ??= NodeRegistration::deriveUrl($body['api_listen'] ?? null, $body['api_scheme'] ?? null, $body['api_domain'] ?? null, $cleartext);

        // A compare-and-set on the token this request authenticated with, never the
        // name: an enrolment replaced since the lookup above answers false, and gets
        // the same 404 as an unknown token (never says which).
        $registered = $repository->register($node->bootstrapTokenSha256, new NodeRegistration(
            $version,
            $apiUrl,
            now()->toDateTimeImmutable(),
            $body['doctor'] ?? null,
            $body['bench'] ?? null,
        ));

        if (! $registered) {
            return $this->unknownToken();
        }

        return response()->noContent();
    }

    /**
     * `GET {prefix}/enroll/install` with `Authorization: Bearer <token>`: only a
     * configured cloud-init.<role>.yml is user-data. The bundled config templates
     * cannot be served here with secrets substituted. A `?token=` is ignored (and
     * answered `404 unknown_token`). Only register() burns the token.
     */
    public function install(Request $request): Response|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return $this->unknownToken();
        }

        $node = $this->pending(EnrollmentSeams::repository($this->container), $token);

        if ($node === null) {
            return $this->unknownToken();
        }

        $path = $this->renderer->templatePath($node->role);

        if ($path === null || basename($path) !== "cloud-init.{$node->role->value}.yml") {
            return response()->json(
                ['error' => ['code' => 'no_template', 'message' => 'No cloud-init user-data template is configured for this role.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $document = $this->renderer->render(
            ConfigRenderer::read($path),
            $node,
            EnrollmentSeams::facts($this->container),
            RenderMode::UserData,
            new BootstrapToken($token, $node->bootstrapExpiresAt),
            secrets: EnrollmentSeams::secrets($this->container),
            joinToken: $this->joinTokenFor($node),
            kind: TemplateKind::App,
        );

        return response($document->reveal(), Response::HTTP_OK, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * `GET {prefix}/enroll/config` with `Authorization: Bearer <token>`: render only
     * the bundled role config in Config mode. No application template override or
     * user-data is read, and this read does not burn the token.
     */
    public function config(Request $request): Response|JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return $this->unknownToken();
        }

        $node = $this->pending(EnrollmentSeams::repository($this->container), $token);

        if ($node === null) {
            return $this->unknownToken();
        }

        $path = ConfigRenderer::bundledDirectory().'/config.'.$node->role->value.'.yml';

        if (! is_file($path) || ! is_readable($path)) {
            return response()->json(
                ['error' => ['code' => 'no_template', 'message' => 'No bundled config template exists for this role.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $document = $this->renderer->render(
            ConfigRenderer::read($path),
            $node,
            EnrollmentSeams::facts($this->container),
            RenderMode::Config,
            kind: TemplateKind::Config,
        );

        return response($document->reveal(), Response::HTTP_OK, [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * A fresh, stored fleet join token when the role's template names
     * `${TMESIS_FLEET_JOIN_TOKEN}` and the fleet is enabled; null otherwise. Minted per
     * fetch because install burns nothing and may be fetched again; an unused join
     * token simply expires.
     */
    private function joinTokenFor(NodeEnrollment $node): ?JoinToken
    {
        if (! Route::has('tmesis.fleet.enroll') || ! $this->renderer->templateUses($node->role, ConfigRenderer::FLEET_JOIN_TOKEN)) {
            return null;
        }

        return $this->container->make(FleetControlPlane::class)->joinToken($node->name, $node->role->value);
    }

    /**
     * The webhook signing function, with the enrolment's own webhook secret, then
     * the receiver's skew window (checked after the MAC, as the receiver does).
     */
    private function signed(Request $request, string $raw, NodeEnrollment $node): bool
    {
        $timestamp = (string) $request->header('X-Tmesis-Timestamp', '');
        $presented = (string) $request->header('X-Tmesis-Signature', '');

        if (preg_match('/^[0-9]+\z/', $timestamp) !== 1 || $presented === '') {
            return false;
        }

        if (! WebhookSignature::verify($node->webhookSecret(), $timestamp, $raw, $presented)) {
            return false;
        }

        return abs(now()->getTimestamp() - (int) $timestamp) <= VerifyTmesisSignature::WINDOW_SECONDS;
    }

    private function pending(NodeRepository $repository, string $token): ?NodeEnrollment
    {
        $node = $repository->pending(BootstrapToken::hash($token));

        if ($node === null || $node->bootstrapExpired()) {
            return null;
        }

        return $node;
    }

    private function unknownToken(): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => 'unknown_token', 'message' => 'The bootstrap token is unknown or has expired.']],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function invalid(string $field, ?string $why = null): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => 'validation_failed', 'message' => $why === null
                ? "The registration's [{$field}] is missing or invalid."
                : "The registration's [{$field}] is refused: {$why}."]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
