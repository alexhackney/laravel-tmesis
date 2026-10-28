<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A 4xx the contract does not name a code for. Kept generic on purpose: the
 * error code set is open, so an unrecognised status must still arrive as a Tmesis
 * exception rather than as a decoding failure.
 */
class ClientException extends TmesisException {}
