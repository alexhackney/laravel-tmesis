<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use DateTimeImmutable;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The one-use token a new box registers back with.
 *
 * Only its sha256 is ever stored (NodeEnrollment::$bootstrapTokenSha256); the plain
 * token is shown once by `tmesis:enroll-node` and handed to the box. It is held the
 * way SignedRequest holds a URL: wrapped, so a dump, a cast, an export or a JSON
 * encoding shows nothing usable, and `serialize()` throws.
 */
final readonly class BootstrapToken
{
    private SensitiveParameterValue $plain;

    public function __construct(
        #[SensitiveParameter] string $plain,
        public DateTimeImmutable $expiresAt,
    ) {
        $this->plain = new SensitiveParameterValue($plain);
    }

    /**
     * The token itself. Only the command's "shown once" output may call this.
     */
    public function plain(): string
    {
        return self::reveal($this->plain);
    }

    /**
     * Lower-case hex, the form the repository stores and looks up by.
     */
    public function sha256(): string
    {
        return self::hash($this->plain());
    }

    public static function hash(#[SensitiveParameter] string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'plain' => '[redacted]',
            'expires_at' => $this->expiresAt->format(DATE_RFC3339),
        ];
    }

    private static function reveal(SensitiveParameterValue $value): string
    {
        $revealed = $value->getValue();

        return is_string($revealed) ? $revealed : '';
    }
}
