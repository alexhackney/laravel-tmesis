<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Hooks;

use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The `publish.verify` request body.
 *
 * A SYNCHRONOUS hook, not a webhook: the node holds the publish handshake open
 * waiting for the answer, inside a 500 ms cap, and never stores or dedupes it. It is
 * signed with the node's `ingest.verify_hook.secret`, which may differ from its
 * webhook secret.
 *
 * The endpoint that answers this is v0.2. The shape lives here now so that a consumer
 * can build against it and so the contract test can hold it to
 * `publish.verify.schema.json`.
 */
final readonly class HookRequest
{
    /** The only hook name at this tag. There is no publish.takeover. */
    public const EVENT = 'publish.verify';

    public const KEY_TYPE_SIGNED = 'signed';

    public const KEY_TYPE_BARE_CONFIGURED = 'bare_configured';

    public const KEY_TYPE_BARE_UNPROVEN = 'bare_unproven';

    public const PROTOCOL_SRT = 'srt';

    public const PROTOCOL_RTMP = 'rtmp';

    /**
     * RTMP over TLS on the RTMP port (tmesis `v0.8.0`, `ingest.rtmp.encryption`): a
     * WIDENING, so a receiver that switched on the two old values must take this one.
     */
    public const PROTOCOL_RTMPS = 'rtmps';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function event(): string
    {
        return $this->string('event');
    }

    public function deliveryId(): string
    {
        return $this->string('delivery');
    }

    public function occurredAtRaw(): string
    {
        return $this->string('occurred_at');
    }

    public function occurredAt(): DateTimeImmutable
    {
        return Rfc3339::parse($this->occurredAtRaw());
    }

    public function node(): string
    {
        return $this->string('node');
    }

    public function nodeRole(): string
    {
        return $this->string('node_role');
    }

    public function contractVersion(): int
    {
        return (int) ($this->raw['contract_version'] ?? 0);
    }

    /**
     * The stream id parsed out of the presented key.
     */
    public function stream(): string
    {
        return $this->string('stream');
    }

    public function keyType(): string
    {
        return $this->string('key_type');
    }

    /**
     * Which configured verify secret matched.
     */
    public function keyId(): string
    {
        return $this->string('key_id');
    }

    /**
     * The redacted key: stream and epoch in full, then four and four hex characters
     * of the MAC. This is the only form of a key that may be logged.
     */
    public function keyFragment(): string
    {
        return $this->string('key_fragment');
    }

    /**
     * The KEY epoch from field 2 of the presented key.
     *
     * Typed, because this hook is exactly where confusing it with the session epoch
     * would authorise the wrong publish.
     */
    public function keyEpoch(): KeyEpoch
    {
        return KeyEpoch::fromInt((int) ($this->raw['key_epoch'] ?? 0));
    }

    public function protocol(): string
    {
        return $this->string('protocol');
    }

    /**
     * Publisher address and port. For operators; it is not an authorization input.
     */
    public function remote(): string
    {
        return $this->string('remote');
    }

    public function session(): string
    {
        return $this->string('session');
    }

    public function openPosture(): bool
    {
        return (bool) ($this->raw['open_posture'] ?? false);
    }

    public function takeover(): bool
    {
        return (bool) ($this->raw['takeover'] ?? false);
    }

    /**
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
}
