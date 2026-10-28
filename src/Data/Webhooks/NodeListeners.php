<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * The addresses a node bound at startup, on `node.online`.
 *
 * The `$defs.NodeListeners` shape of `node.online.schema.json`. These are listen
 * strings as configured (`:1935`, `127.0.0.1:7000`), not reachable URLs. `rtmp`
 * and `srt` are `""` when the role or config opens no such listener: a TRANSCODER
 * reports both empty since tmesis `v0.9.0` (handover 3.2). Empty is a real answer.
 */
final readonly class NodeListeners
{
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
     * `""` when no RTMP listener is open.
     */
    public function rtmp(): string
    {
        return (string) ($this->raw['rtmp'] ?? '');
    }

    /**
     * `""` when no SRT listener is open.
     */
    public function srt(): string
    {
        return (string) ($this->raw['srt'] ?? '');
    }

    public function api(): string
    {
        return (string) ($this->raw['api'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
