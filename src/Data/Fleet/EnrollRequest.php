<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Fleet;

use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use stdClass;

/**
 * What `tmesis join` POSTs (`fleet.enroll.request`, docs/API.md 15.1).
 *
 * `node` is the name in the box's config. It is RECORDED, never adopted: the name the
 * fleet knows the box by is the one its join token was minted for, and the node adopts
 * what it is told. `capabilities` is a name-to-bool map a newer node may widen; an
 * unknown name is kept, never an error.
 */
final readonly class EnrollRequest
{
    public const ROLES = ['edge', 'origin', 'transcoder', 'standalone'];

    /**
     * @param  array{rtmp: string, srt: string}  $listen
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        public string $node,
        public string $role,
        public string $version,
        public array $listen,
        public array $capabilities,
    ) {}

    /**
     * @throws FleetPayloadException
     */
    public static function fromRaw(string $raw): self
    {
        $o = FleetWire::decode($raw);
        $listen = FleetWire::object($o, 'listen', '$');
        $capabilities = FleetWire::object($o, 'capabilities', '$');

        $caps = [];

        foreach (get_object_vars($capabilities) as $name => $value) {
            if (! is_bool($value)) {
                throw new FleetPayloadException("\$.capabilities.{$name}", 'must be a boolean');
            }

            $caps[(string) $name] = $value;
        }

        return new self(
            FleetWire::nonEmptyString($o, 'node', '$'),
            FleetWire::enum($o, 'role', '$', self::ROLES),
            FleetWire::nonEmptyString($o, 'version', '$'),
            [
                'rtmp' => FleetWire::string($listen, 'rtmp', '$.listen'),
                'srt' => FleetWire::string($listen, 'srt', '$.listen'),
            ],
            $caps,
        );
    }

    /**
     * Rebuild from a decoded object, for callers that already hold one.
     */
    public static function fromObject(stdClass $o): self
    {
        return self::fromRaw((string) json_encode($o, JSON_THROW_ON_ERROR));
    }
}
