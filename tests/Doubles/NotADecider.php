<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

/**
 * A class name someone put in tmesis.hooks.decider that does not implement the
 * interface. A misconfiguration must land on the configured posture, never on a 500:
 * a 5xx tells the node nothing and is never read as a deny.
 */
class NotADecider {}
