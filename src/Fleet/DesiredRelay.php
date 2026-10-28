<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\RelayUrl;
use SensitiveParameter;

/**
 * One fleet-owned relay destination on a desired stream.
 *
 * The URL is a CREDENTIAL (an SRT passphrase or an RTMP stream key rides in it), so it
 * is held here only to be sent to the node: never logged, never persisted (the fleet
 * store keeps a keyed hash of the document, not the document), and `__debugInfo`,
 * `redacted()` and every event show scheme, host and port only.
 *
 * The scheme is checked because the node's relay engine takes srt, rtmp and rtmps and
 * nothing else, and one bad URL rejects the whole revision (tmesis WP4). The rest of
 * the URL is the node's to judge.
 */
final readonly class DesiredRelay
{
    private const SCHEMES = ['srt', 'rtmp', 'rtmps'];

    private function __construct(
        public string $id,
        private string $url,
        public bool $enabled,
    ) {}

    public static function make(string $id, #[SensitiveParameter] string $url, bool $enabled = true): self
    {
        if ($id === '') {
            throw InvalidConfigurationException::invalid('relays[].id', 'a relay id is required');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), self::SCHEMES, true) || ! is_string($host) || $host === '') {
            throw InvalidConfigurationException::invalid(
                "relays.{$id}.url",
                'it must be an srt://, rtmp:// or rtmps:// URL with a host (got '.RelayUrl::redact($url).')'
            );
        }

        return new self($id, $url, $enabled);
    }

    /**
     * Scheme, host and port: the only form of this destination that may be logged.
     */
    public function redacted(): string
    {
        return RelayUrl::redact($this->url);
    }

    /**
     * The wire object, URL in full. For the heartbeat answer only.
     *
     * @return array{id: string, url: string, enabled: bool}
     */
    public function toWire(): array
    {
        return ['id' => $this->id, 'url' => $this->url, 'enabled' => $this->enabled];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'url' => $this->redacted(), 'enabled' => $this->enabled];
    }
}
