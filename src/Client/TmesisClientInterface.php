<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

use AlexHackney\Tmesis\Data\Node\NodeConfig;

/**
 * The client surface a consumer binds against, and the one FakeTmesis implements.
 */
interface TmesisClientInterface
{
    /**
     * A configured node by name, or the default when none is named.
     */
    public function node(?string $name = null): PendingNode;

    /**
     * A node that is not in config at all.
     *
     * The fleet case: a control plane that discovers nodes at runtime has their URL
     * and token in its own database rather than in `config/tmesis.php`.
     */
    public function connect(NodeConfig $node): PendingNode;
}
