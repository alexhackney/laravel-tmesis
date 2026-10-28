<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * The bearer token was missing or wrong (401 unauthorized).
 */
class AuthenticationException extends TmesisException {}
