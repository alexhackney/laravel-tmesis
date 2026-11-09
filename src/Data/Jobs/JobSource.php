<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * What a job reads. The openapi component JobSource (tmesis `v0.6.0`, `docs/API.md`
 * 9.10), Spec's `source`: exactly one of a configured destination plus its key, a
 * configured destination plus an HLS replay prefix (tmesis `v0.10.0`, edits only), or
 * an HTTPS URL. The three constructors make "both" and "neither" unrepresentable.
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
        private ?string $prefix = null,
        private ?string $rendition = null,
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
     * An HLS replay in a configured destination (tmesis `v0.10.0`): its bucket-relative
     * `$prefix`, read from `<prefix>/playlist.m3u8`. Only a job that carries an `edit`
     * may read one (`422` otherwise). Excludes `key`.
     *
     * @param  string|null  $rendition  the variant by its rung directory; null is the
     *                                  highest-BANDWIDTH video variant with its default audio
     */
    public static function prefix(string $destinationRef, string $prefix, ?string $rendition = null): self
    {
        if (trim($destinationRef) === '') {
            throw InvalidConfigurationException::invalid('source.destination_ref', 'it must name a destination');
        }

        if ($prefix === '') {
            throw InvalidConfigurationException::invalid('source.prefix', 'an HLS replay source needs its prefix');
        }

        if ($rendition === '') {
            throw InvalidConfigurationException::invalid('source.rendition', 'it must name a rung directory, or be omitted');
        }

        return new self($destinationRef, null, null, $prefix, $rendition);
    }

    /**
     * A source as the node echoed it back (an edit's ranges in `MediaEditAccepted`):
     * read leniently, a URL rebuilt without its query string and userinfo.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
        $url = $string('url');

        return new self(
            $string('destination_ref'),
            $string('key'),
            $url === null ? null : self::redactUrl($url),
            $string('prefix'),
            $string('rendition'),
        );
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
     * The HLS replay prefix of a prefix() source; null otherwise.
     */
    public function sourcePrefix(): ?string
    {
        return $this->prefix;
    }

    public function rendition(): ?string
    {
        return $this->rendition;
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

        if ($this->prefix !== null) {
            return array_filter([
                'destination_ref' => (string) $this->destinationRef,
                'prefix' => $this->prefix,
                'rendition' => $this->rendition,
            ], static fn (?string $value): bool => $value !== null);
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
