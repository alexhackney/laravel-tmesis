<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Support\RelayUrl;

/**
 * One relay destination, shared by all five `relay.*` events.
 *
 * The `$defs.RelayDestination` shape the five relay schemas share.
 *
 * A destination URL is a CREDENTIAL. The node redacts it to scheme, host and port
 * before it ever leaves, and url() redacts again on the way in, so a node that ever
 * sent an unredacted one cannot make this package hold it.
 */
final readonly class RelayDestination
{
    /**
     * The state `relay.started` and `relay.reconnected` carry: the transport opened
     * and the copy is being sent, and `connected` is promoted on the first write
     * that gets through, with no event of its own (since tmesis `v0.2.0` step 1;
     * `relay.reconnected` carried `failed` before).
     */
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

    /**
     * Stable across a reconnect, and unique WITHIN this stream rather than globally.
     * Correlate on the (stream, id) pair.
     */
    public function id(): string
    {
        return (string) ($this->raw['id'] ?? '');
    }

    /**
     * Scheme, host and port only.
     */
    public function url(): string
    {
        return (string) ($this->raw['url'] ?? '');
    }

    public function state(): string
    {
        return (string) ($this->raw['state'] ?? '');
    }

    public function bytesOut(): int
    {
        return (int) ($this->raw['bytes_out'] ?? 0);
    }

    /**
     * Media this destination did NOT receive.
     *
     * A property of the COPY and never of the show: the relay drops rather than
     * backpressuring the publisher.
     */
    public function droppedBytes(): int
    {
        return (int) ($this->raw['dropped_bytes'] ?? 0);
    }

    /**
     * How many times this destination has come back. A number that climbs while the
     * others are stable points at that destination, not at this node.
     */
    public function reconnects(): int
    {
        return (int) ($this->raw['reconnects'] ?? 0);
    }

    /**
     * What this destination is sent in this session, RESOLVED: `ingest` (the
     * publisher's bytes) or `rung:<name>` (a ladder rung), never `auto`. Empty when
     * the destination was refused before resolving, or stopped while waiting on the
     * session's source. Since tmesis `v0.4.0` (4.2 / EDGE-5); `RelayFeed::rungName()`
     * reads the rung out of it.
     */
    public function feed(): string
    {
        return (string) ($this->raw['feed'] ?? '');
    }

    /**
     * Provisioned destination ids folded into this leg (tmesis `v0.6.0`, WEBHOOKS.md
     * 8.27): a destination provisioned with the same URL and feed as a static relay
     * rides that leg instead of running as a second publisher, so id() stays the
     * static id (`static-0`) and the provisioned id (`origin`) appears here. Empty
     * when there are none (the node omits the field). A receiver waiting on a
     * provisioned id should ask matches(), not compare id().
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        $aliases = $this->raw['aliases'] ?? [];

        if (! is_array($aliases)) {
            return [];
        }

        return array_values(array_filter($aliases, is_string(...)));
    }

    /**
     * Whether this event is about the destination `$id`: its id, or one of its
     * aliases.
     */
    public function matches(string $id): bool
    {
        return $id !== '' && ($this->id() === $id || in_array($id, $this->aliases(), true));
    }

    /**
     * The redacted form, which is the only form this package holds.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
