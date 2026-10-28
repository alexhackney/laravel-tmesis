<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Facades;

use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\StreamKey;
use AlexHackney\Tmesis\Keys\StreamKeyMinter;
use AlexHackney\Tmesis\Testing\FakeTmesis;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AlexHackney\Tmesis\Client\PendingNode node(?string $name = null)
 * @method static \AlexHackney\Tmesis\Client\PendingNode connect(\AlexHackney\Tmesis\Data\Node\NodeConfig $node)
 *
 * @see TmesisClientInterface
 */
class Tmesis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TmesisClientInterface::class;
    }

    /**
     * Swap the client for a fake.
     *
     * A real static method rather than an `@method` tag: a facade forwards to its
     * accessor, and this replaces the accessor's binding instead.
     */
    public static function fake(): FakeTmesis
    {
        $fake = new FakeTmesis;

        static::swap($fake);

        return $fake;
    }

    /**
     * The fleet control plane: join tokens, node status, commands (tmesis v0.7.0).
     *
     * A real static method, like mint(): the fleet is not a node client. After
     * `Tmesis::fake()`, `Tmesis::fake()->fleet()` binds the in-memory fleet, and this
     * returns it.
     */
    public static function fleet(): FleetControlPlane
    {
        /** @var FleetControlPlane */
        return app(FleetControlPlane::class);
    }

    /**
     * Mint a publish key with the configured secret.
     *
     * Also a real static method: minting is not part of the client interface, since
     * nothing about it talks to a node. Reads as
     * `Tmesis::mint('show-2041-main', KeyEpoch::fromInt(7))`.
     */
    public static function mint(string $stream, KeyEpoch $epoch): StreamKey
    {
        /** @var StreamKeyMinter $minter */
        $minter = app(StreamKeyMinter::class);

        return $minter->mint($stream, $epoch);
    }
}
