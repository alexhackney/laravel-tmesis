<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Health;

use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use DateTimeImmutable;

/**
 * One probe of one node's health by `tmesis:health --emit`.
 *
 * NOT a webhook event, which is why it lives here rather than in `Events\`: that
 * namespace means a delivery from a node (decision D5), and `Events\NodeOnline` is
 * already the `node.online` webhook.
 *
 * Stateless. Each probe is reported on its own, with no memory of the last one, so
 * "went offline" is a transition the app's listener works out from its own columns
 * (the store that would remember is the fleet control plane). A node that
 * answers with `status: unavailable` is still `reachable`: the listener reads
 * `health` to tell up from ready.
 *
 * `errorClass` and `errorCode` are carried, never the message: a node's error text is
 * not a stable thing to store, and a connection failure's can carry a url.
 */
final readonly class NodeProbed
{
    public function __construct(
        public string $name,
        public bool $reachable,
        public ?HealthFull $health,
        public ?string $errorClass,
        public ?string $errorCode,
        public float $elapsedMs,
        public DateTimeImmutable $probedAt,
    ) {}

    /**
     * The node answered its health route.
     */
    public static function reached(string $name, HealthFull $health, float $elapsedMs, DateTimeImmutable $probedAt): self
    {
        return new self($name, true, $health, null, null, $elapsedMs, $probedAt);
    }

    /**
     * The health call threw: the node is down, refused the token, or answered with
     * an error envelope. The code is the envelope's `error.code`, null when there was
     * no envelope (a refused connection, a timeout).
     */
    public static function unreachable(string $name, TmesisException $error, float $elapsedMs, DateTimeImmutable $probedAt): self
    {
        return new self($name, false, null, $error::class, $error->code(), $elapsedMs, $probedAt);
    }
}
