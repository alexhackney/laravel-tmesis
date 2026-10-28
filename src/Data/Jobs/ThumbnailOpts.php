<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The `thumbnails` output's options. The openapi component ThumbnailOpts (tmesis
 * `v0.6.0`, `docs/API.md` 9.10).
 *
 * The four selection modes are ADDITIVE and the output is their union, deduplicated by
 * snapped frame time: a grid (`interval_seconds`, narrowed by `count`), exact offsets
 * (`at_seconds`), offsets as a fraction of the duration (`at_percent`), and
 * `random_count` random offsets whose seed is reported on the output.
 */
final readonly class ThumbnailOpts
{
    public const MIN_WIDTH = 16;

    public const MAX_WIDTH = 3840;

    public const MAX_RANDOM_COUNT = 1000;

    /**
     * @param  list<float>  $atSeconds
     * @param  list<float>  $atPercent
     */
    private function __construct(
        private ?float $intervalSeconds,
        private ?int $width,
        private ?int $count,
        private array $atSeconds,
        private array $atPercent,
        private ?int $randomCount,
    ) {}

    /**
     * Null (or empty) leaves the node's default: a frame every 10 seconds, 640 wide,
     * no cap, no exact or random offsets.
     *
     * @param  array<array-key, int|float>  $atSeconds  each at least 0
     * @param  array<array-key, int|float>  $atPercent  each 0 to 100
     */
    public static function make(
        int|float|null $intervalSeconds = null,
        ?int $width = null,
        ?int $count = null,
        array $atSeconds = [],
        array $atPercent = [],
        ?int $randomCount = null,
    ): self {
        if ($intervalSeconds !== null && $intervalSeconds <= 0) {
            throw InvalidConfigurationException::invalid('thumbnails.interval_seconds', 'it must be greater than 0');
        }

        if ($width !== null && ($width < self::MIN_WIDTH || $width > self::MAX_WIDTH || $width % 2 !== 0)) {
            throw InvalidConfigurationException::invalid('thumbnails.width', 'it must be even and between '.self::MIN_WIDTH.' and '.self::MAX_WIDTH);
        }

        if ($count !== null && $count < 1) {
            throw InvalidConfigurationException::invalid('thumbnails.count', 'it must be at least 1');
        }

        foreach ($atSeconds as $offset) {
            if ($offset < 0) {
                throw InvalidConfigurationException::invalid('thumbnails.at_seconds', 'each offset must be at least 0');
            }
        }

        foreach ($atPercent as $offset) {
            if ($offset < 0 || $offset > 100) {
                throw InvalidConfigurationException::invalid('thumbnails.at_percent', 'each offset must be between 0 and 100');
            }
        }

        if ($randomCount !== null && ($randomCount < 0 || $randomCount > self::MAX_RANDOM_COUNT)) {
            throw InvalidConfigurationException::invalid('thumbnails.random_count', 'it must be between 0 and '.self::MAX_RANDOM_COUNT);
        }

        return new self(
            $intervalSeconds === null ? null : (float) $intervalSeconds,
            $width,
            $count,
            array_values(array_map(floatval(...), $atSeconds)),
            array_values(array_map(floatval(...), $atPercent)),
            $randomCount,
        );
    }

    public function intervalSeconds(): ?float
    {
        return $this->intervalSeconds;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function count(): ?int
    {
        return $this->count;
    }

    /**
     * @return list<float>
     */
    public function atSeconds(): array
    {
        return $this->atSeconds;
    }

    /**
     * @return list<float>
     */
    public function atPercent(): array
    {
        return $this->atPercent;
    }

    public function randomCount(): ?int
    {
        return $this->randomCount;
    }

    /**
     * Only what was set; an empty offset list is omitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'interval_seconds' => $this->intervalSeconds,
            'width' => $this->width,
            'count' => $this->count,
            'at_seconds' => $this->atSeconds === [] ? null : $this->atSeconds,
            'at_percent' => $this->atPercent === [] ? null : $this->atPercent,
            'random_count' => $this->randomCount,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
