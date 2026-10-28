<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Doubles;

use AlexHackney\Tmesis\Data\Fleet\FleetNode;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredStateProvider;
use RuntimeException;

/**
 * A desired-state provider a test drives: a document per node NAME, the empty document
 * otherwise, and a switch that makes it throw. It counts its calls, so a test can prove
 * the heartbeat asked (or did not).
 */
class ArrayDesiredStateProvider implements DesiredStateProvider
{
    /** @var array<string, DesiredDocument> */
    public static array $documents = [];

    public static bool $throws = false;

    public static int $calls = 0;

    public static function reset(): void
    {
        self::$documents = [];
        self::$throws = false;
        self::$calls = 0;
    }

    public function desiredFor(FleetNode $node): DesiredDocument
    {
        self::$calls++;

        if (self::$throws) {
            throw new RuntimeException('the provider is down (test)');
        }

        return self::$documents[$node->name] ?? DesiredDocument::make();
    }
}
