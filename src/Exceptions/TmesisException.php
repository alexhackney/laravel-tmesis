<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

use AlexHackney\Tmesis\Data\ErrorBody;
use RuntimeException;
use Throwable;

/**
 * Base class for everything this package throws.
 *
 * The node answers every error with the same envelope, `{error:{code, message,
 * details, request_id, milestone}}`, and `ErrorMap` is the one place a status and an
 * `error.code` become one of these classes.
 *
 * From milestone M1 on, discovery is 501-based: an unbuilt route answers `501` with
 * `error.milestone` naming the milestone that delivers it. A `404` is a real 404, a
 * stream or route that does not exist, and never "not built yet".
 */
abstract class TmesisException extends RuntimeException
{
    public function __construct(
        string $message,
        protected readonly ?ErrorBody $error = null,
        protected readonly ?int $status = null,
        protected readonly ?string $requestId = null,
        protected readonly ?string $node = null,
        protected readonly ?string $nodeVersion = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * The decoded error envelope, when the response carried one.
     */
    public function error(): ?ErrorBody
    {
        return $this->error;
    }

    /**
     * The wire `error.code`, or null when the body was not the envelope.
     */
    public function code(): ?string
    {
        return $this->error?->code();
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * The node's `request_id`, from the body or the `X-Request-Id` header.
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * The node name from `X-Tmesis-Node`.
     */
    public function node(): ?string
    {
        return $this->node;
    }

    /**
     * The node build from `X-Tmesis-Version`.
     */
    public function nodeVersion(): ?string
    {
        return $this->nodeVersion;
    }
}
