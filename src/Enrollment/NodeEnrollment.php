<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use DateTimeImmutable;
use DateTimeInterface;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * One box, enrolled but not necessarily registered yet: its name, its role, and the
 * four secrets minted for it.
 *
 * `name` is the node's hostname, which is the `X-Tmesis-Node` it will sign with and
 * the name NodeRegistry resolves it by.
 *
 * **The admin token and the webhook, hook and keyterms hook secrets are CREDENTIALS**,
 * held the way SignedRequest holds its URL:
 *
 *  - Each is wrapped in `SensitiveParameterValue`, which exposes no properties, so an
 *    `(array)` cast, `var_export()`, `json_encode()` and VarDumper's `dump()` show
 *    nothing usable. `var_dump()` and `print_r()` show `__debugInfo`, which redacts.
 *  - `serialize()` THROWS, so an enrolment cannot be queued, cached or put in a
 *    session by accident. A repository stores the four accessors' values itself
 *    (encrypted, ideally) and rebuilds one with the constructor.
 *  - There is no `__toString`.
 *  - `envBlock()` is the one rendering that carries them, and it is shown once.
 *
 * Only the bootstrap token's sha256 is kept; the plain token is never on this object.
 */
final readonly class NodeEnrollment
{
    /**
     * The `.env` variables the node reads its secrets from. A template's
     * `${...}` naming one of these is left for the node to interpolate at start.
     *
     * @var list<string>
     */
    public const ENV_PLACEHOLDERS = [
        'TMESIS_ADMIN_TOKEN',
        'TMESIS_WEBHOOK_SECRET',
        'TMESIS_HOOK_SECRET',
        'TMESIS_KEYTERMS_HOOK_SECRET',
        'TMESIS_STREAM_KEY_SECRET',
    ];

    public const NAME_PATTERN = '/^(?=.{1,253}\z)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\z/';

    private SensitiveParameterValue $adminToken;

    private SensitiveParameterValue $webhookSecret;

    private SensitiveParameterValue $hookSecret;

    private SensitiveParameterValue $keytermsHookSecret;

    /**
     * @param  string  $bootstrapTokenSha256  lower-case hex
     * @param  string|null  $keytermsHookSecret  the keyterms hook secret (tmesis
     *                                           `v0.8.0`); null is the webhook secret,
     *                                           which is what a repository that keeps
     *                                           no separate column rebuilds with
     *
     * @throws EnrollmentException when the name is not a hostname
     */
    public function __construct(
        public string $name,
        public NodeRole $role,
        public ?string $region,
        public ?string $plan,
        #[SensitiveParameter] string $adminToken,
        #[SensitiveParameter] string $webhookSecret,
        #[SensitiveParameter] string $hookSecret,
        public string $bootstrapTokenSha256,
        public DateTimeImmutable $bootstrapExpiresAt,
        public DateTimeImmutable $enrolledAt,
        #[SensitiveParameter] ?string $keytermsHookSecret = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw EnrollmentException::invalidName($name);
        }

        $this->adminToken = new SensitiveParameterValue($adminToken);
        $this->webhookSecret = new SensitiveParameterValue($webhookSecret);
        $this->hookSecret = new SensitiveParameterValue($hookSecret);
        $this->keytermsHookSecret = new SensitiveParameterValue($keytermsHookSecret ?? $webhookSecret);
    }

    public function adminToken(): string
    {
        return self::reveal($this->adminToken);
    }

    public function webhookSecret(): string
    {
        return self::reveal($this->webhookSecret);
    }

    public function hookSecret(): string
    {
        return self::reveal($this->hookSecret);
    }

    /**
     * The secret the node signs its keyterms hook GET with (`TMESIS_KEYTERMS_HOOK_SECRET`,
     * tmesis `v0.8.0`): the webhook secret unless minted separately.
     */
    public function keytermsHookSecret(): string
    {
        return self::reveal($this->keytermsHookSecret);
    }

    /**
     * Whether the keyterms hook secret was minted separately
     * (`--distinct-keyterms-secret`).
     */
    public function hasDistinctKeytermsHookSecret(): bool
    {
        return ! hash_equals($this->webhookSecret(), $this->keytermsHookSecret());
    }

    /**
     * Whether the hook secret was minted separately (`--distinct-hook-secret`).
     */
    public function hasDistinctHookSecret(): bool
    {
        return ! hash_equals($this->webhookSecret(), $this->hookSecret());
    }

    /**
     * Whether the bootstrap token has expired (at or after its expiry).
     */
    public function bootstrapExpired(?DateTimeInterface $now = null): bool
    {
        $now ??= now();

        return $now->getTimestamp() >= $this->bootstrapExpiresAt->getTimestamp();
    }

    /**
     * @return list<string>
     */
    public function envPlaceholders(): array
    {
        return self::ENV_PLACEHOLDERS;
    }

    /**
     * The node's `/etc/tmesis/.env` secret lines, in full. Shown once.
     *
     * `TMESIS_STREAM_KEY_SECRET` is the fleet's `tmesis.keys.secret`, not a per-box
     * value (publish keys are self-validating with one secret everywhere); when it
     * is blank here the line is a comment saying so.
     *
     * `$supplied` is ConfigRenderer::suppliedSecrets() (the optional EnrollmentSecrets
     * seam, tmesis `v0.6.0`): each one it carries is a line too, and one it does not
     * is simply absent, which the node reads as the template's empty fallback. A batch
     * or fallback key is a line too (tmesis `v0.9.3`), though a document renders it
     * empty under a `self_hosted` role (ConfigRenderer::render()).
     *
     * @param  array<string, SensitiveParameterValue>  $supplied
     */
    public function envBlock(array $supplied = []): string
    {
        $keySecret = config('tmesis.keys.secret');

        $lines = [
            'TMESIS_ADMIN_TOKEN='.$this->adminToken(),
            'TMESIS_WEBHOOK_SECRET='.$this->webhookSecret(),
            'TMESIS_HOOK_SECRET='.$this->hookSecret(),
            'TMESIS_KEYTERMS_HOOK_SECRET='.$this->keytermsHookSecret(),
            is_string($keySecret) && ! blank($keySecret)
                ? 'TMESIS_STREAM_KEY_SECRET='.self::envValue($keySecret)
                : '# TMESIS_STREAM_KEY_SECRET is unset: tmesis.keys.secret is blank on this control plane',
        ];

        foreach ($supplied as $name => $value) {
            $lines[] = $name.'='.self::envValue(self::reveal($value));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * What `var_dump()` and `print_r()` show: nothing that signs.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role->value,
            'region' => $this->region,
            'plan' => $this->plan,
            'admin_token' => '[redacted]',
            'webhook_secret' => '[redacted]',
            'hook_secret' => '[redacted]',
            'keyterms_hook_secret' => '[redacted]',
            'bootstrap_token_sha256' => $this->bootstrapTokenSha256,
            'bootstrap_expires_at' => $this->bootstrapExpiresAt->format(DATE_RFC3339),
            'enrolled_at' => $this->enrolledAt->format(DATE_RFC3339),
        ];
    }

    /**
     * A value quoted for a `.env` line when it carries anything a dotenv parser
     * would read differently.
     */
    private static function envValue(#[SensitiveParameter] string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.\/+=:@-]+\z/', $value) === 1) {
            return $value;
        }

        return '"'.addcslashes($value, "\"\\\$\n").'"';
    }

    private static function reveal(SensitiveParameterValue $value): string
    {
        $revealed = $value->getValue();

        return is_string($revealed) ? $revealed : '';
    }
}
