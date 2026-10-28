<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * The request never produced a response: DNS, connect, TLS, or read timeout.
 *
 * Wraps Laravel's own connection exception as `getPrevious()`, so a caller can catch
 * one Tmesis type for everything the client can do wrong.
 */
class ConnectionException extends TmesisException {}
