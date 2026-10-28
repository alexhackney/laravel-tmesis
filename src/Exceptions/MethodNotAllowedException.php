<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * The wrong method for an existing route (405 method_not_allowed).
 */
class MethodNotAllowedException extends TmesisException {}
