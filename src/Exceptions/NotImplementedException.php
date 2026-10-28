<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A contract route that this node build has not delivered yet (501
 * `not_implemented`).
 *
 * This is how discovery works from M1 on: every route in the contract exists, and one
 * that is not built answers 501 carrying `error.milestone`. A caller that wants to
 * know whether a capability is available asks for it and reads `milestone()` here,
 * rather than probing for 404s.
 */
class NotImplementedException extends TmesisException
{
    /**
     * The milestone that delivers the route, such as `M3`, or null when the node did
     * not name one.
     */
    public function milestone(): ?string
    {
        return $this->error?->milestone();
    }
}
