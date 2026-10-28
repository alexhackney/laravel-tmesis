<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Preview;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;
use stdClass;

/**
 * One live WHEP preview grant: the answer to create, update and revoke, and one row
 * of the list. The openapi component PreviewGrant (tmesis v0.6.0, `docs/API.md`
 * 9.20a).
 *
 * The grant is NODE-scoped (`Tmesis::node($name)->previewGrants()`): a grant on one
 * box covers monitors on that box only. Under `preview.access: granted` a WHEP offer
 * from an address no live grant covers answers `403 not_granted` to the PLAYER on the
 * preview listener; the admin client never sees that code.
 */
final readonly class PreviewGrant
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The grant's ULID: the handle update and revoke take.
     */
    public function id(): string
    {
        return $this->string('id');
    }

    /**
     * The covered addresses in the node's CANONICAL form (IPv4 dotted quad, IPv6
     * compressed, an IPv4-mapped IPv6 as its IPv4), duplicates dropped. Not
     * necessarily what was sent.
     *
     * @return list<string>
     */
    public function ips(): array
    {
        return self::strings($this->raw['ips'] ?? null);
    }

    /**
     * The covered stream ids; null covers every stream on the node.
     *
     * @return list<string>|null
     */
    public function streams(): ?array
    {
        $value = $this->raw['streams'] ?? null;

        return is_array($value) ? self::strings($value) : null;
    }

    public function coversEveryStream(): bool
    {
        return $this->streams() === null;
    }

    /**
     * Whether this grant covers the address (as the node canonicalised it) and, when
     * given, the stream.
     */
    public function covers(string $ip, ?string $stream = null): bool
    {
        if (! in_array($ip, $this->ips(), true)) {
            return false;
        }

        $streams = $this->streams();

        return $stream === null || $streams === null || in_array($stream, $streams, true);
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        $value = $this->raw['labels'] ?? null;
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $label) {
            if (is_string($key) && is_string($label)) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    public function createdAtRaw(): string
    {
        return $this->string('created_at');
    }

    public function createdAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('created_at'));
    }

    /**
     * When the grant stops covering anything. At this instant a connected monitor no
     * other live grant covers is closed within five seconds (`grant_expired`).
     */
    public function expiresAtRaw(): string
    {
        return $this->string('expires_at');
    }

    public function expiresAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->string('expires_at'));
    }

    /**
     * The response as it arrived, unknown fields included, with an EMPTY `labels`
     * map as an object: the node always sends `{}` (the schema types it `object`),
     * PHP decodes that to `[]`, and `[]` would encode back as a JSON array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $raw = $this->raw;

        if (($raw['labels'] ?? null) === []) {
            $raw['labels'] = new stdClass;
        }

        return $raw;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
