<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * No such stream, relay, job, or route (404 not_found).
 *
 * A 404 is a real 404. An unbuilt contract route answers 501 with a milestone, so
 * NotImplementedException is the discovery signal, never this class.
 */
class NotFoundException extends TmesisException {}
