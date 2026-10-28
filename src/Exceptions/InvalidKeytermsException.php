<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A KeytermsProvider answered a list the node would ignore whole (tmesis `v0.8.0`,
 * the keyterm rules of `docs/API.md` 9.4, Support\Keyterms).
 *
 * The keyterms route reports it and answers a 5xx, so the node keeps the list in
 * force and the app's error tracker, not the node's log, is where it shows. The
 * message is the node's own sentence for the first rule broken, which names an index
 * and never a term.
 */
class InvalidKeytermsException extends TmesisException
{
    public static function refused(string $problem): self
    {
        return new self("The keyterms provider answered a list the node would ignore whole: {$problem}.");
    }
}
