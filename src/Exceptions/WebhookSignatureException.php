<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * An inbound delivery failed verification.
 *
 * The receiver answers 401 rather than throwing, so this exists for callers that
 * verify a signature themselves.
 */
class WebhookSignatureException extends TmesisException {}
