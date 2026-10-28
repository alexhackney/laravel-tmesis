<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Preview;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The body of `createPreviewGrant`: which producer addresses may open a WHEP monitor,
 * for how long, and on which streams. The openapi component PreviewGrantInput (tmesis
 * v0.6.0, `docs/API.md` 9.20a).
 *
 * **Every POST is an INDEPENDENT grant.** Two grants for one address are two grants,
 * and revoking or outliving one never ends the other's monitors; nothing here
 * replaces anything. The panel flow is grant on open, extend on reload
 * (`updatePreviewGrant()` on the id), revoke on close.
 *
 * **Send every address family the panel was seen on** (its IPv4 AND its IPv6) in one
 * grant: the WHEP offer may arrive over either.
 *
 * Only the GRAMMAR is checked here: an IP literal with no CIDR and no zone
 * (`filter_var(FILTER_VALIDATE_IP)`), 1 to 16 of them after the duplicates are
 * dropped, a TTL of 60 to 86400, 1 to 64 well-formed stream ids when given, and the
 * label limits. The canonical form (an IPv4-mapped IPv6 stored as its IPv4, IPv6
 * compressed) and the unspecified and multicast refusals are the NODE's; read the
 * canonical addresses back off `PreviewGrant::ips()`.
 *
 * Every instance is immutable: each setter returns a new input.
 */
final readonly class PreviewGrantInput
{
    public const MAX_IPS = 16;

    public const MIN_TTL_SECONDS = 60;

    public const MAX_TTL_SECONDS = 86400;

    public const MAX_STREAMS = 64;

    public const MAX_LABELS = 16;

    public const MAX_LABEL_KEY_BYTES = 64;

    public const MAX_LABEL_VALUE_BYTES = 256;

    /** The stream id charset (`docs/API.md` section 4). */
    public const STREAM_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,127}$/';

    /**
     * @param  list<string>  $ips
     * @param  list<string>|null  $streams
     * @param  array<string, string>  $labels
     */
    private function __construct(
        private array $ips,
        private int $ttlSeconds,
        private ?array $streams,
        private array $labels,
    ) {}

    /**
     * @param  array<array-key, string>  $ips  IPv4 or IPv6 literals; duplicates are dropped
     * @param  int  $ttlSeconds  60 to 86400, from now
     */
    public static function make(array $ips, int $ttlSeconds): self
    {
        return new self(self::guardIps($ips), self::guardTtl($ttlSeconds), null, []);
    }

    /**
     * Limit the grant to these streams. Null (the default) covers every stream on the
     * node. An EMPTY list is refused here with the node's sentence: the node answers
     * it `422`, never reading it as every stream.
     *
     * @param  array<array-key, string>|null  $streams
     */
    public function forStreams(?array $streams): self
    {
        if ($streams !== null) {
            $streams = array_values(array_unique(array_values($streams)));

            if ($streams === []) {
                throw InvalidConfigurationException::invalid('streams', 'an empty list covers nothing; omit it or send null for every stream');
            }

            if (count($streams) > self::MAX_STREAMS) {
                throw InvalidConfigurationException::invalid('streams', 'at most '.self::MAX_STREAMS.' streams; omit it or send null for every stream');
            }

            foreach ($streams as $index => $stream) {
                if (preg_match(self::STREAM_ID_PATTERN, $stream) !== 1) {
                    throw InvalidConfigurationException::invalid("streams[{$index}]", 'must match ^[a-z0-9][a-z0-9_-]{0,127}$, and must not contain a dot');
                }
            }
        }

        return new self($this->ips, $this->ttlSeconds, $streams, $this->labels);
    }

    /**
     * Bookkeeping the node echoes on every read and never interprets, so the control
     * plane can find its own grants (a panel id, a user id). At most 16 keys, 64-byte
     * keys, 256-byte values. An empty map sends no labels at all.
     *
     * @param  array<string, string>  $labels
     */
    public function withLabels(array $labels): self
    {
        if (count($labels) > self::MAX_LABELS) {
            throw InvalidConfigurationException::invalid('labels', 'at most '.self::MAX_LABELS.' keys');
        }

        foreach ($labels as $key => $value) {
            if (strlen((string) $key) > self::MAX_LABEL_KEY_BYTES) {
                throw InvalidConfigurationException::invalid("labels.{$key}", 'key is over 64 bytes');
            }

            if (strlen($value) > self::MAX_LABEL_VALUE_BYTES) {
                throw InvalidConfigurationException::invalid("labels.{$key}", 'value is over 256 bytes');
            }
        }

        return new self($this->ips, $this->ttlSeconds, $this->streams, $labels);
    }

    /**
     * @return list<string>
     */
    public function ips(): array
    {
        return $this->ips;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * @return list<string>|null null covers every stream
     */
    public function streams(): ?array
    {
        return $this->streams;
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * The request body: `ips` and `ttl_seconds` always, `streams` and `labels` only
     * when set (omitted is every stream and no labels).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['ips' => $this->ips, 'ttl_seconds' => $this->ttlSeconds];

        if ($this->streams !== null) {
            $body['streams'] = $this->streams;
        }

        if ($this->labels !== []) {
            $body['labels'] = $this->labels;
        }

        return $body;
    }

    /**
     * @param  array<array-key, string>  $ips
     * @return list<string>
     */
    private static function guardIps(array $ips): array
    {
        $ips = array_values(array_unique(array_values($ips)));

        if ($ips === []) {
            throw InvalidConfigurationException::invalid('ips', 'required: at least one address');
        }

        if (count($ips) > self::MAX_IPS) {
            throw InvalidConfigurationException::invalid('ips', 'at most '.self::MAX_IPS.' addresses');
        }

        foreach ($ips as $index => $ip) {
            // FILTER_VALIDATE_IP refuses a name, a CIDR and a zone alike.
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw InvalidConfigurationException::invalid("ips[{$index}]", 'must be an IPv4 or IPv6 address, not a name or a CIDR');
            }
        }

        return $ips;
    }

    /**
     * Shared with PreviewGrantPatch: the same bounds on both verbs.
     */
    public static function guardTtl(int $ttlSeconds): int
    {
        if ($ttlSeconds < self::MIN_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw InvalidConfigurationException::invalid('ttl_seconds', 'must be between '.self::MIN_TTL_SECONDS.' and '.self::MAX_TTL_SECONDS);
        }

        return $ttlSeconds;
    }
}
