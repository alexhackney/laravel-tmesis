<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * What a job reads. The openapi component JobSource (tmesis `v0.6.0`, `docs/API.md`
 * 9.10), Spec's `source`: exactly one of a configured destination plus its key, or an
 * HTTPS URL. The two constructors make "both" and "neither" unrepresentable.
 *
 * `upload_id` is RESERVED at v1 (any value is `422 source_not_supported`) and is never
 * exposed. Whether the destination exists on the node is the node's `422`.
 *
 * **A presigned URL is a credential.** Its query string carries the signature: the
 * node never echoes it (JobSourceReport has it removed), and nothing in this package
 * logs or records it. Log redacted(), never toArray(). A URL carrying userinfo
 * (`user:password@`) is refused outright: a credential has no place in a source URL.
 */
final readonly class JobSource
{
    private function __construct(
        private ?string $destinationRef,
        private ?string $key,
        private ?string $url,
    ) {}

    /**
     * A `storage.destinations` entry on the node, read with that entry's credential
     * profile, and the object's bucket-relative key in it (told, never derived: the
     * entry's prefix does not apply).
     */
    public static function destinationRef(string $destinationRef, string $key): self
    {
        if (trim($destinationRef) === '') {
            throw InvalidConfigurationException::invalid('source.destination_ref', 'it must name a destination');
        }

        if ($key === '') {
            throw InvalidConfigurationException::invalid('source.key', 'a destination source needs the object key');
        }

        return new self($destinationRef, $key, null);
    }

    /**
     * An HTTPS URL the node can GET, presigned or public: how a caller-owned bucket
     * is read. Serve a length (`Content-Length` or `Content-Range`) so a cut fetch
     * resumes.
     */
    public static function url(string $url): self
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw InvalidConfigurationException::invalid('source.url', 'it must be an absolute https URL');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw InvalidConfigurationException::invalid('source.url', 'credentials in a source URL are not accepted; use a presigned URL');
        }

        return new self(null, null, $url);
    }

    public function destination(): ?string
    {
        return $this->destinationRef;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    /**
     * The URL as given, query string included: a credential when presigned.
     */
    public function sourceUrl(): ?string
    {
        return $this->url;
    }

    /**
     * The body. A presigned URL is a credential: never log this.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        if ($this->url !== null) {
            return ['url' => $this->url];
        }

        return ['destination_ref' => (string) $this->destinationRef, 'key' => (string) $this->key];
    }

    /**
     * The body with a URL rebuilt as `scheme://host[:port]/path` alone: no userinfo, no
     * query string, no fragment, as the node reports it back. Safe to log and record.
     *
     * @return array<string, string>
     */
    public function redacted(): array
    {
        if ($this->url === null) {
            return $this->toArray();
        }

        return ['url' => self::redactUrl($this->url)];
    }

    /**
     * Rebuilt from its parts, never cut from the raw string, so nothing but the
     * scheme, host, port and path can survive. A URL that does not parse keeps none
     * of itself.
     */
    private static function redactUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port.($parts['path'] ?? '');
    }
}
