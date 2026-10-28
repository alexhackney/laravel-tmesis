<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;

/**
 * Mints a box's secrets and its bootstrap token.
 *
 * Every secret is 32 bytes from `random_bytes()`, base64url without padding (43
 * characters of `A-Z a-z 0-9 - _`), so it goes into a `.env` line and a YAML scalar
 * without quoting.
 */
class SecretMinter
{
    public const DEFAULT_TTL_MINUTES = 15;

    public function __construct(private readonly Repository $config) {}

    public function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * A fresh bootstrap token, expiring `tmesis.enrollment.bootstrap_ttl_minutes`
     * from now.
     */
    public function bootstrapToken(): BootstrapToken
    {
        return new BootstrapToken(
            $this->secret(),
            $this->now()->modify('+'.$this->ttlMinutes().' minutes'),
        );
    }

    /**
     * @throws InvalidConfigurationException when the TTL is below one minute
     */
    public function ttlMinutes(): int
    {
        $ttl = $this->config->get('tmesis.enrollment.bootstrap_ttl_minutes');

        if (blank($ttl)) {
            return self::DEFAULT_TTL_MINUTES;
        }

        if (! is_numeric($ttl) || (int) $ttl < 1) {
            throw InvalidConfigurationException::invalid('tmesis.enrollment.bootstrap_ttl_minutes', 'it must be a whole number of minutes, at least 1');
        }

        return (int) $ttl;
    }

    /**
     * A new enrolment for a box, with its bootstrap token.
     *
     * The hook secret is the webhook secret unless `$distinctHookSecret`: the node's
     * `ingest.verify_hook.secret` may differ from its `webhooks.secret`, the grain is
     * per box either way, and the resolver falls back to the webhook secret when the
     * row keeps no separate one. The keyterms hook secret (tmesis `v0.8.0`,
     * `transcription.keyterms_hook.secret`) follows the same rule on its own switch,
     * `$distinctKeytermsHookSecret`: the registry falls back to the webhook secret
     * for it too, so an app that stores only the webhook secret verifies all three.
     *
     * @return array{0: NodeEnrollment, 1: BootstrapToken}
     */
    public function enrollment(string $name, NodeRole $role, ?string $region = null, ?string $plan = null, bool $distinctHookSecret = false, bool $distinctKeytermsHookSecret = false): array
    {
        $token = $this->bootstrapToken();
        $webhookSecret = $this->secret();

        $node = new NodeEnrollment(
            name: $name,
            role: $role,
            region: $region,
            plan: $plan,
            adminToken: $this->secret(),
            webhookSecret: $webhookSecret,
            hookSecret: $distinctHookSecret ? $this->secret() : $webhookSecret,
            bootstrapTokenSha256: $token->sha256(),
            bootstrapExpiresAt: $token->expiresAt,
            enrolledAt: $this->now(),
            keytermsHookSecret: $distinctKeytermsHookSecret ? $this->secret() : $webhookSecret,
        );

        return [$node, $token];
    }

    private function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable()->setTimezone(new DateTimeZone('UTC'));
    }
}
