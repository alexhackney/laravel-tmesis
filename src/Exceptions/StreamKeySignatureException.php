<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A stream key parsed cleanly but its MAC does not match the secret.
 *
 * Distinct from MalformedStreamKeyException on purpose: the vector file separates
 * malformed from bad_signature, and so does the node.
 */
class StreamKeySignatureException extends TmesisException {}
