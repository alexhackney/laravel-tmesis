<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * A fleet request body a node sent that is not the contract's shape: a missing
 * required field, a wrong type, a value outside its closed set.
 *
 * `path` is the JSON path of the offending value (`$.stats.uptime_seconds`), which is
 * what the `422` answer names. The message never carries a VALUE: a heartbeat can hold
 * event payloads and an enroll body a token's neighbourhood, and none of it belongs in
 * a log line.
 */
class FleetPayloadException extends TmesisException
{
    public function __construct(public readonly string $path, public readonly string $reason)
    {
        parent::__construct("{$path}: {$reason}");
    }
}
