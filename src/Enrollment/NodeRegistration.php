<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use DateTimeImmutable;

/**
 * What a freshly installed box reports back on `POST {prefix}/enroll/register`: the
 * build it installed (`tmesis version --json`), the admin URL the control plane
 * should dial it on, and, since tmesis `v0.6.0` (DEPLOY.md 5.6), the doctor report
 * and the job benchmark result the bootstrap records "with the registration".
 * Nothing here is a secret.
 *
 * `apiUrl` is NULL when the box reported no address a control plane can dial: the
 * node is then RECEIVE-ONLY (its webhooks and hooks work, an admin call throws naming
 * the missing url), which is what a `NodeResolver` row with no url already means. It
 * is never guessed: see deriveUrl().
 *
 * `doctor` and `bench` are the bootstrap's own documents, handed through untouched
 * and unmodelled (the package does not own their shape).
 */
final readonly class NodeRegistration
{
    /**
     * @param  string  $version  the node build, as `tmesis version --json` reports it
     * @param  string|null  $apiUrl  the node's admin base URL, `http` or `https`, or
     *                               null for a receive-only node
     * @param  array<array-key, mixed>|null  $doctor  the `tmesis doctor` report, as sent
     * @param  array<array-key, mixed>|null  $bench  the `tmesis bench --profile job`
     *                                               result, as sent
     */
    public function __construct(
        public string $version,
        public ?string $apiUrl,
        public DateTimeImmutable $registeredAt,
        public ?array $doctor = null,
        public ?array $bench = null,
    ) {
        if (trim($version) === '') {
            throw EnrollmentException::invalidRegistration('version');
        }

        if ($apiUrl !== null && ! self::isUrl($apiUrl)) {
            throw EnrollmentException::invalidRegistration('api_url');
        }
    }

    public function isReceiveOnly(): bool
    {
        return $this->apiUrl === null;
    }

    public static function isUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && $host !== '';
    }

    /**
     * The admin URL from what `tmesis version --json` reports (DEPLOY.md 5.6,
     * "Registering back"), or null when it names no address to dial:
     *
     *  - `api_domain` set (the ACME path): `https://<api_domain>:<port of api_listen>`,
     *    because only the certificate's name verifies;
     *  - else `api_listen` naming a host: `<api_scheme>://<host>:<port>`, but an `http`
     *    one only when `$allowCleartext` (the `local` and `testing` environments,
     *    Transport::allowsCleartext()): elsewhere the package would refuse every
     *    admin call to it, so the node registers receive-only instead;
     *  - else null. `api_listen` is usually `:7000`, which names no host, and a
     *    wildcard (`0.0.0.0`, `::`) or loopback host names nothing a control plane on
     *    another box can reach, so those are null too. So is a report with no
     *    `api_listen` or `api_scheme` (the node had no config that loaded).
     *
     * A file-pair box whose certificate names a host its `api_listen` does not
     * registers with an explicit `api_url` instead (its own fact).
     */
    public static function deriveUrl(?string $apiListen, ?string $apiScheme, ?string $apiDomain, bool $allowCleartext = false): ?string
    {
        $listen = self::splitListen($apiListen);

        if ($listen === null) {
            return null;
        }

        [$host, $port] = $listen;

        if ($apiDomain !== null && trim($apiDomain) !== '') {
            return 'https://'.trim($apiDomain).':'.$port;
        }

        if (! in_array($apiScheme, ['http', 'https'], true) || ! self::dialable($host)) {
            return null;
        }

        // A dialable host is never loopback, so http here always crosses the network.
        if ($apiScheme === 'http' && ! $allowCleartext) {
            return null;
        }

        return $apiScheme.'://'.(str_contains($host, ':') ? "[{$host}]" : $host).':'.$port;
    }

    /**
     * `host:port`, `:port` or `[v6]:port` into [host, port], the host '' when absent;
     * null when there is no numeric port.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function splitListen(?string $listen): ?array
    {
        if ($listen === null || preg_match('/^(?:\[([^\]]*)\]|([^:\[\]]*)):([0-9]{1,5})\z/', trim($listen), $m) !== 1) {
            return null;
        }

        $port = (int) $m[3];

        if ($port < 1 || $port > 65535) {
            return null;
        }

        return [$m[1] !== '' ? $m[1] : $m[2], $port];
    }

    private static function dialable(string $host): bool
    {
        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        // An unspecified or loopback address is an address on the node's own box.
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false
            && ! in_array($host, ['0.0.0.0', '::'], true);
    }
}
