<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Node;

/**
 * The liveness-only body of GET /api/v1/health: what the route answers to a
 * request with NO bearer, on any bind (docs/API.md section 5 and 9.1). `status`,
 * `node`, `version` and `contract_version`, and nothing else: capacity, the stream
 * counts, `degraded_reasons` and the config source stay behind the token.
 *
 * Node::getPublicHealth() decodes it: the same operation as getHealth(), sent with
 * no bearer (the operation's `security` is `[{}, {adminToken: []}]`). A wrong token
 * on getHealth() is a 401 (the node does not tell "no token" from "wrong token"),
 * never this shape. A consumer that probes the route without the package (a
 * container HEALTHCHECK, a systemd probe, a load balancer written against Http
 * directly) can decode into it too.
 *
 * @see HealthPublic in docs/contract/openapi.json at tmesis v0.2.0, where the route's
 * 200 became `oneOf: [HealthFull, HealthPublic]`, HealthFull to a bearer and this to
 * nobody.
 */
final readonly class HealthPublic
{
    public const STATUS_OK = 'ok';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function contractVersion(): int
    {
        $value = $this->raw['contract_version'] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    public function node(): string
    {
        return $this->string('node');
    }

    /**
     * CLOSED set: `ok`, `degraded` or `unavailable`. The reasons behind `degraded`
     * are on HealthFull only.
     */
    public function status(): string
    {
        return $this->string('status');
    }

    public function version(): string
    {
        return $this->string('version');
    }

    /**
     * The response as it arrived, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
