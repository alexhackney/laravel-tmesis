<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

use AlexHackney\Tmesis\Data\ErrorDetail;

/**
 * Field-level problems with the request (422).
 *
 * Codes: `validation_failed` and `immutable_field`. An UNKNOWN field is
 * `validation_failed` with a `details` entry whose `message` is the string
 * `unknown field`; there is no `unknown_field` code, so branch on `details[].message`
 * rather than on `error.code`.
 *
 * A milestone-only field on a POST arrives here as `422 unknown field`; the same
 * field on a PATCH arrives as `501`.
 */
class ValidationException extends TmesisException
{
    /**
     * The per-field problems, empty when the body carried none.
     *
     * @return list<ErrorDetail>
     */
    public function details(): array
    {
        return $this->error?->details() ?? [];
    }
}
