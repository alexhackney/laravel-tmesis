<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

use AlexHackney\Tmesis\Keys\KeyEpoch;

/**
 * How a publisher authenticated, on `stream.published`.
 *
 * The `$defs.PublishAuth` shape of `stream.published.schema.json`.
 */
final readonly class PublishAuth
{
    public const TYPE_SIGNED = 'signed';

    public const TYPE_BARE_CONFIGURED = 'bare_configured';

    public const TYPE_BARE_UNPROVEN = 'bare_unproven';

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

    /**
     * Which configured verify secret matched, or `dual-accept` for a legacy bare key.
     */
    public function kid(): string
    {
        return (string) ($this->raw['kid'] ?? '');
    }

    /**
     * The KEY epoch, from field 2 of the stream key.
     *
     * Typed rather than an int because this is the epoch that is easiest to confuse:
     * the same payload carries a session epoch under the plain name `epoch`.
     */
    public function keyEpoch(): KeyEpoch
    {
        return KeyEpoch::fromInt((int) ($this->raw['key_epoch'] ?? 0));
    }

    /**
     * True means this producer is still on a legacy bare key and needs re-keying.
     *
     * This field is the re-keying workflow's data source: the window closes when it
     * stops appearing, not on a date.
     */
    public function dualAccept(): bool
    {
        return (bool) ($this->raw['dual_accept'] ?? false);
    }

    /**
     * `signed`, `bare_configured`, or `bare_unproven`.
     */
    public function keyType(): string
    {
        return (string) ($this->raw['key_type'] ?? '');
    }

    public function openPosture(): bool
    {
        return (bool) ($this->raw['open_posture'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
