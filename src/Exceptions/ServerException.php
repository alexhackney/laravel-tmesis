<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * The node hit a bug (500 internal), any 5xx not otherwise mapped, and a node answer
 * the client cannot follow (a paging cursor answered twice in a row).
 */
class ServerException extends TmesisException {}
