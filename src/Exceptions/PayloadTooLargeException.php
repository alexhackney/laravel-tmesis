<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * The request body was over the node's limit (413 payload_too_large).
 */
class PayloadTooLargeException extends TmesisException {}
