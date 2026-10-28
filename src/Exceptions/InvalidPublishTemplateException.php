<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A publish URL template carries no literal {key} placeholder to substitute into.
 */
class InvalidPublishTemplateException extends TmesisException {}
