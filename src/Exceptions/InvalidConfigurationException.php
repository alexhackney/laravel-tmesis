<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A configuration value this package needs is missing, blank, or out of range.
 *
 * The message always names the config key and never the value: these keys hold
 * secrets. Thrown at use rather than at boot, so a consumer that has one node
 * misconfigured can still talk to the others.
 *
 * `blank()` is the test, not `isset()`. Doppler renders an absent key as an empty
 * string, so `""` must be treated as absent everywhere.
 */
class InvalidConfigurationException extends TmesisException
{
    public static function missing(string $key): self
    {
        return new self("The config key [{$key}] is required and is not set.");
    }

    /**
     * A node name neither config nor the node resolver knows.
     */
    public static function unknownNode(string $name): self
    {
        return new self("No node [{$name}] is configured under [tmesis.nodes.{$name}] or known to the node resolver.");
    }

    public static function invalid(string $key, string $why): self
    {
        return new self("The config key [{$key}] is invalid: {$why}.");
    }
}
