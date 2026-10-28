<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The `mp4` output's options. The openapi component MP4Opts (tmesis `v0.6.0`,
 * `docs/API.md` 9.10): which of the ladder's VIDEO rungs to remux. Whether a rung is
 * on the ladder is the node's `422` (the fake models no ladders, D3).
 */
final readonly class MP4Opts
{
    /**
     * @param  list<string>  $rungs
     */
    private function __construct(private array $rungs) {}

    /**
     * An empty list is refused: omit the object for every video rung.
     *
     * @param  array<array-key, string>  $rungs
     */
    public static function make(array $rungs): self
    {
        $rungs = array_values($rungs);

        if ($rungs === []) {
            throw InvalidConfigurationException::invalid('mp4.rungs', 'name at least one rung, or omit mp4 for every video rung');
        }

        foreach ($rungs as $i => $rung) {
            if (trim($rung) === '') {
                throw InvalidConfigurationException::invalid("mp4.rungs[{$i}]", 'a rung name cannot be empty');
            }
        }

        return new self($rungs);
    }

    /**
     * @return list<string>
     */
    public function rungs(): array
    {
        return $this->rungs;
    }

    /**
     * @return array{rungs: list<string>}
     */
    public function toArray(): array
    {
        return ['rungs' => $this->rungs];
    }
}
