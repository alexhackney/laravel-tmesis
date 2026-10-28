<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The `audio` output's options. The openapi component AudioOpts (tmesis `v0.6.0`,
 * `docs/API.md` 9.10). Applies only when the job asks for `audio`.
 */
final readonly class AudioOpts
{
    /** aac in M4A, mp3 in MP3, opus in Ogg. */
    public const FORMATS = ['aac', 'mp3', 'opus'];

    public const MIN_BITRATE_KBPS = 32;

    public const MAX_BITRATE_KBPS = 512;

    private function __construct(private ?string $format, private ?int $bitrateKbps) {}

    /**
     * Null leaves the node's default: `aac` at 128 kbps.
     */
    public static function make(?string $format = null, ?int $bitrateKbps = null): self
    {
        if ($format !== null && ! in_array($format, self::FORMATS, true)) {
            throw InvalidConfigurationException::invalid('audio.format', 'it must be one of '.implode(', ', self::FORMATS));
        }

        if ($bitrateKbps !== null && ($bitrateKbps < self::MIN_BITRATE_KBPS || $bitrateKbps > self::MAX_BITRATE_KBPS)) {
            throw InvalidConfigurationException::invalid('audio.bitrate_kbps', 'it must be between '.self::MIN_BITRATE_KBPS.' and '.self::MAX_BITRATE_KBPS);
        }

        return new self($format, $bitrateKbps);
    }

    public function format(): ?string
    {
        return $this->format;
    }

    public function bitrateKbps(): ?int
    {
        return $this->bitrateKbps;
    }

    /**
     * Only what was set.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter(
            ['format' => $this->format, 'bitrate_kbps' => $this->bitrateKbps],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
