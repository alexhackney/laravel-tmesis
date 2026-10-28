<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

use AlexHackney\Tmesis\Data\ErrorBody;
use Throwable;

/**
 * The node cannot accept work right now (503).
 *
 * Codes: `draining` (shutting down), `not_ready` (journal recovery or orphan reaping
 * has not finished), `journal_unavailable` (nothing can be accepted because the
 * journal cannot be written). `GET /api/v1/health` answers 503 with this envelope
 * rather than a health body, so a health check surfaces here.
 *
 * `encoder_unavailable` (tmesis `v0.9.0`, a job submit on a box whose pinned jobs
 * encoder failed) is the subclass EncoderUnavailableException: it does not clear by
 * waiting, so it is the one 503 a scheduler must NOT retry on the same node.
 */
class UnavailableException extends TmesisException
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
