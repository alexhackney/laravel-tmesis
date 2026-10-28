<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use DateTimeImmutable;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * A one-time fleet join token: what `tmesis join <url> --token <t>` presents once.
 *
 * Only its sha256 is stored. The plain value is shown once (the command, the user-data
 * render) and is held the way BootstrapToken is: wrapped, so a dump, a cast, an export
 * or a JSON encoding shows nothing usable, and `serialize()` throws.
 *
 * It enrolls a node on its first use, and is good for the D12 repeat (same node id, a
 * fresh fleet token) for `retry_window_minutes` after that; unused, it expires at
 * `expiresAt`.
 */
final readonly class JoinToken
{
    public const PREFIX = 'tmj_';

    private SensitiveParameterValue $plain;

    public function __construct(
        #[SensitiveParameter] string $plain,
        public string $name,
        public ?string $role,
        public DateTimeImmutable $expiresAt,
    ) {
        $this->plain = new SensitiveParameterValue($plain);
    }

    /**
     * A fresh token in memory, NOT stored: `tmesis:enroll-node --fleet` renders the
     * user-data with it first and stores it only once the render and the enrolment
     * succeeded, so a refused render leaves no live token behind.
     */
    public static function mint(string $secret, string $name, ?string $role, DateTimeImmutable $expiresAt): self
    {
        return new self(self::PREFIX.$secret, $name, $role, $expiresAt);
    }

    /**
     * The token itself. Only a "shown once" surface may call this.
     */
    public function reveal(): string
    {
        return $this->plain->getValue();
    }

    public function sha256(): string
    {
        return hash('sha256', $this->reveal());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'plain' => '[redacted]',
            'name' => $this->name,
            'role' => $this->role,
            'expires_at' => $this->expiresAt->format(DATE_RFC3339),
        ];
    }
}
