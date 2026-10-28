<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use stdClass;

/**
 * One stream as the node sees it, from a heartbeat (`$defs.StreamState`).
 *
 * `session` and `protocol` are empty strings when nothing is attached (the schema says
 * empty, not null). `dualAccept()` is the re-keying workflow's data source: the
 * attached producer is still on a legacy bare key.
 */
final readonly class FleetStreamState
{
    public const STATES = ['idle', 'publishing', 'live', 'draining', 'sealed'];

    public const PROTOCOLS = ['srt', 'rtmp', 'rtmps', ''];

    public function __construct(
        public string $stream,
        public string $state,
        public string $session,
        public int $epoch,
        public string $protocol,
        public bool $dualAccept,
    ) {}

    public static function fromWire(stdClass $o, string $path): self
    {
        return new self(
            FleetWire::nonEmptyString($o, 'stream', $path),
            FleetWire::enum($o, 'state', $path, self::STATES),
            FleetWire::string($o, 'session', $path),
            FleetWire::integer($o, 'epoch', $path),
            FleetWire::enum($o, 'protocol', $path, self::PROTOCOLS),
            FleetWire::boolean($o, 'dual_accept', $path),
        );
    }

    /**
     * @param  array<string, mixed>  $a
     */
    public static function fromStored(array $a): self
    {
        return new self(
            (string) ($a['stream'] ?? ''),
            (string) ($a['state'] ?? ''),
            (string) ($a['session'] ?? ''),
            (int) ($a['epoch'] ?? 0),
            (string) ($a['protocol'] ?? ''),
            (bool) ($a['dual_accept'] ?? false),
        );
    }

    public function attached(): bool
    {
        return $this->session !== '';
    }

    public function dualAccept(): bool
    {
        return $this->dualAccept;
    }

    /**
     * @return array{stream: string, state: string, session: string, epoch: int, protocol: string, dual_accept: bool}
     */
    public function toArray(): array
    {
        return [
            'stream' => $this->stream,
            'state' => $this->state,
            'session' => $this->session,
            'epoch' => $this->epoch,
            'protocol' => $this->protocol,
            'dual_accept' => $this->dualAccept,
        ];
    }
}
