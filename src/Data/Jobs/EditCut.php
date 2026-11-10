<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * How an edit cuts. The openapi component EditCut (tmesis `v0.10.0`, `docs/API.md`
 * 9.13): `keyframe` (the default, a stream copy that snaps each cut to the keyframe at
 * or before the request, so a clip can run up to one GOP long at each end) or
 * `frame_accurate` (the node re-encodes the head and tail GOPs only and cuts on the
 * exact frame).
 */
final readonly class EditCut
{
    public const MODE_KEYFRAME = 'keyframe';

    public const MODE_FRAME_ACCURATE = 'frame_accurate';

    /** @var list<string> */
    public const MODES = [self::MODE_KEYFRAME, self::MODE_FRAME_ACCURATE];

    /**
     * @param  array<string, mixed>  $extra  fields a newer node sent that this class does not name
     */
    private function __construct(private string $mode, private array $extra = []) {}

    public static function keyframe(): self
    {
        return new self(self::MODE_KEYFRAME);
    }

    public static function frameAccurate(): self
    {
        return new self(self::MODE_FRAME_ACCURATE);
    }

    /**
     * @param  string  $mode  one of MODES
     */
    public static function make(string $mode): self
    {
        if (! in_array($mode, self::MODES, true)) {
            throw InvalidConfigurationException::invalid('cut.mode', 'it must be one of '.implode(', ', self::MODES));
        }

        return new self($mode);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $mode = $data['mode'] ?? null;
        unset($data['mode']);

        return new self(is_string($mode) ? $mode : self::MODE_KEYFRAME, $data);
    }

    /**
     * One of MODES.
     */
    public function mode(): string
    {
        return $this->mode;
    }

    public function isFrameAccurate(): bool
    {
        return $this->mode === self::MODE_FRAME_ACCURATE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode] + $this->extra;
    }
}
