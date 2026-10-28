<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

use Throwable;

/**
 * The node resolver failed: it threw, or it answered with the wrong node.
 *
 * The message names the resolver class, the node asked for and the class of what was
 * thrown, never the thrown message: a database driver's message can carry a DSN, and
 * this one is logged by the webhook receiver. The original is kept as the previous
 * exception for the consumer's own handling.
 */
class NodeResolverException extends TmesisException
{
    public static function failed(string $resolver, ?string $name, Throwable $previous): self
    {
        $what = $name === null ? 'listing its nodes' : "finding node [{$name}]";

        return new self(
            "The node resolver [{$resolver}] failed {$what}: ".$previous::class.'.',
            previous: $previous,
        );
    }

    public static function mismatch(string $resolver, string $asked, string $answered): self
    {
        return new self(
            "The node resolver [{$resolver}] answered node [{$answered}] when asked for [{$asked}]."
        );
    }
}
