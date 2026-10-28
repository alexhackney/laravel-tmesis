<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A stream key is not three well formed fields.
 *
 * The shape alone decides this: a bad stream id, a bad epoch, or a MAC that is not 64
 * lowercase hex characters. No secret is consulted, which is why the vector file's
 * malformed rows all land here.
 *
 * The message never carries the key. Log StreamKey::fragment() instead.
 */
class MalformedStreamKeyException extends TmesisException {}
