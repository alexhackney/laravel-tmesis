<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

use AlexHackney\Tmesis\Data\ErrorBody;
use Throwable;

/**
 * The node refused the request for rate reasons (429 `rate_limited`).
 *
 * Reserved in the contract at `v0.1.0-rc2`; mapped so a node that starts sending it
 * does not arrive as a generic client error.
 */
class RateLimitException extends TmesisException
{
    public function __construct(
        string $message,
        ?ErrorBody $error = null,
        ?int $status = null,
        ?string $requestId = null,
        ?string $node = null,
        ?string $nodeVersion = null,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $error, $status, $requestId, $node, $nodeVersion, $previous);
    }

    /**
     * Seconds from the `Retry-After` header, when the node sent one.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
