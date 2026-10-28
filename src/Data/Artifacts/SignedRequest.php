<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\RelayUrl;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeInterface;
use SensitiveParameter;
use SensitiveParameterValue;
use stdClass;

/**
 * One presigned request the node may send on the caller's behalf. The openapi
 * component SignedRequest (tmesis `v0.5.0`, `docs/API.md` 12a.4): `{method, url,
 * headers, expires_at}` and nothing else.
 *
 * **The URL and the header values are CREDENTIALS**, the same rule as a relay URL:
 *
 *  - There is no accessor for either. `redactedUrl()` (scheme, host and port) and
 *    `headerNames()` are what may go in a log line, an exception or a recorded call.
 *  - Both are held wrapped in `SensitiveParameterValue`, which exposes no properties,
 *    so what reads the object's properties directly sees nothing that signs: an
 *    `(array)` cast, `var_export()`, `json_encode()`, and Symfony VarDumper's
 *    `dump()`/`dd()`, which casts to an array and shows the private properties NEXT
 *    to `__debugInfo`. `var_dump()` and `print_r()` show `__debugInfo`, which redacts.
 *  - `serialize()` THROWS (`SensitiveParameterValue` refuses it), so the object
 *    cannot be queued, cached or put in a session. Sign on the worker instead.
 *  - There is no `__toString`, so an interpolation is an error.
 *  - `toArray()` unwraps and is the request body. Nothing but the outbound request
 *    may call it, and whatever holds its result (an HTTP client logger, Telescope)
 *    holds the credentials.
 *
 * Everything that holds one of these (PartAuthorization, AuthorizationBundle,
 * InitialAuthorization, SignedDestination, PlanTarget, PlanRequest,
 * AuthorizationRequest, TransferAuthorization) is covered by the same rules, and
 * their `toArray()` carries the real values the same way.
 *
 * The node holds these in memory only, never journals them, and never echoes them.
 */
final readonly class SignedRequest
{
    /** @var list<string> */
    public const METHODS = ['PUT', 'POST', 'DELETE', 'HEAD'];

    /**
     * @param  array<string, list<SensitiveParameterValue>>  $headers  each value wrapped
     */
    private function __construct(
        private string $method,
        private SensitiveParameterValue $url,
        private array $headers,
        private string $expiresAt,
    ) {}

    /**
     * @param  string  $method  PUT, POST, DELETE or HEAD
     * @param  string  $url  the presigned URL
     * @param  array<string, array<array-key, string>|string>  $headers  the headers the
     *                                                                   signature binds,
     *                                                                   a string taken as
     *                                                                   one value
     * @param  DateTimeInterface|string  $expiresAt  RFC3339 UTC; the node refuses one
     *                                               with under 30 seconds left
     */
    public static function make(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $headers, DateTimeInterface|string $expiresAt): self
    {
        if (! in_array($method, self::METHODS, true)) {
            throw InvalidConfigurationException::invalid('signed.method', 'it must be one of '.implode(', ', self::METHODS));
        }

        $normalised = [];

        foreach ($headers as $name => $values) {
            $normalised[(string) $name] = array_map(
                static fn (string $value): SensitiveParameterValue => new SensitiveParameterValue($value),
                is_string($values) ? [$values] : array_values($values),
            );
        }

        return new self(
            $method,
            new SensitiveParameterValue($url),
            $normalised,
            $expiresAt instanceof DateTimeInterface ? Rfc3339::format($expiresAt) : $expiresAt,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function expiresAt(): string
    {
        return $this->expiresAt;
    }

    /**
     * Scheme, host and port. The only form of this URL that may be logged.
     */
    public function redactedUrl(): string
    {
        return RelayUrl::redact(self::reveal($this->url));
    }

    /**
     * The header names, without their values.
     *
     * @return list<string>
     */
    public function headerNames(): array
    {
        return array_keys($this->headers);
    }

    /**
     * The request body, with the URL and header values in full. An empty header set
     * goes out as `{}`, the object the document types it as.
     *
     * @return array{method: string, url: string, headers: array<string, list<string>>|stdClass, expires_at: string}
     */
    public function toArray(): array
    {
        $headers = array_map(
            static fn (array $values): array => array_map(self::reveal(...), $values),
            $this->headers,
        );

        return [
            'method' => $this->method,
            'url' => self::reveal($this->url),
            'headers' => $headers === [] ? new stdClass : $headers,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * What `var_dump()` and `print_r()` show: nothing that signs. VarDumper shows this
     * as well as the properties, which are wrapped (see the class docblock).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->redactedUrl(),
            'headers' => $this->headerNames(),
            'expires_at' => $this->expiresAt,
        ];
    }

    private static function reveal(SensitiveParameterValue $value): string
    {
        $revealed = $value->getValue();

        return is_string($revealed) ? $revealed : '';
    }
}
