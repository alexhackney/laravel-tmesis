<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Relays;

use AlexHackney\Tmesis\Support\RelayUrl;

/**
 * One relay destination on a stream. The openapi component RelayRecord. A
 * relay URL is a CREDENTIAL: an SRT passphrase or an RTMP stream key rides
 * in it. The node redacts to scheme, host and port before sending, and
 * fromArray() redacts again on the way in, so this object cannot hold an
 * unredacted URL even if a node ever sent one. There is deliberately no
 * __toString.
 */
final readonly class RelayRecord
{
    public const SOURCE_STATIC = 'static';

    public const SOURCE_STREAM = 'stream';

    public const SOURCE_API = 'api';

    /**
     * A relay the fleet's desired state owns (tmesis `v0.7.0` D5). The admin API
     * cannot change or remove it: a write answers `409 fleet_owned`, and the next
     * desired-state revision is how it moves.
     */
    public const SOURCE_FLEET = 'fleet';

    public const STATE_IDLE = 'idle';

    public const STATE_CONNECTING = 'connecting';

    public const STATE_CONNECTED = 'connected';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_FAILED = 'failed';

    public const STATE_STOPPED = 'stopped';

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (isset($data['url']) && is_string($data['url'])) {
            $data['url'] = RelayUrl::redact($data['url']);
        }

        return new self($data);
    }

    public function bytesOut(): int
    {
        return $this->int('bytes_out');
    }

    public function droppedBytes(): int
    {
        return $this->int('dropped_bytes');
    }

    public function enabled(): bool
    {
        return $this->bool('enabled');
    }

    public function id(): string
    {
        return $this->string('id');
    }

    public function reconnects(): int
    {
        return $this->int('reconnects');
    }

    /**
     * The feed AS CONFIGURED: `auto`, `ingest`, or `rung:<name>` (tmesis `v0.4.0`,
     * 4.2 / EDGE-5; `RelayFeed`). What is actually sent is feedResolved().
     */
    public function feed(): string
    {
        return $this->string('feed');
    }

    /**
     * What this publish session sends the destination: `ingest` or `rung:<name>`,
     * never `auto`. EMPTY while the stream is idle, while the destination waits on
     * the session's source, or when the session refused it; the schema says empty
     * string, not null, so it is kept as `''` and `RelayFeed::rungName()` reads null
     * out of it.
     */
    public function feedResolved(): string
    {
        return $this->string('feed_resolved');
    }

    public function source(): string
    {
        return $this->string('source');
    }

    public function state(): string
    {
        return $this->string('state');
    }

    public function url(): string
    {
        return $this->string('url');
    }

    /**
     * The response as it arrived, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function bool(string $key): bool
    {
        return (bool) ($this->raw[$key] ?? false);
    }
}
