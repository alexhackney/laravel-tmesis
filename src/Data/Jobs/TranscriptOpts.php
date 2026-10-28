<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The `transcript` output's options. The openapi component TranscriptOpts (tmesis
 * `v0.6.0`, `docs/API.md` 9.10). Whether the node has a transcription provider is its
 * `422`.
 */
final readonly class TranscriptOpts
{
    /** @var list<string> */
    public const FORMATS = ['vtt', 'srt', 'json'];

    /**
     * @param  list<string>|null  $formats
     */
    private function __construct(private ?string $provider, private ?string $language, private ?array $formats) {}

    /**
     * Null leaves the node's batch provider, `transcription.language`, and all three
     * formats. Since tmesis v0.9.1, K3, provider must match `transcription.batch_provider`.
     *
     * @param  string|null  $provider  `deepgram` or `self_hosted`; must equal the node's `transcription.batch_provider` (not the live provider since tmesis v0.9.1)
     * @param  string|null  $language  an RFC 5646 tag
     * @param  array<array-key, string>|null  $formats  any of FORMATS
     */
    public static function make(?string $provider = null, ?string $language = null, ?array $formats = null): self
    {
        if ($formats !== null) {
            $formats = array_values(array_unique($formats));

            if ($formats === []) {
                throw InvalidConfigurationException::invalid('transcript.formats', 'name at least one format, or null for all three');
            }

            foreach ($formats as $format) {
                if (! in_array($format, self::FORMATS, true)) {
                    throw InvalidConfigurationException::invalid('transcript.formats', 'each must be one of '.implode(', ', self::FORMATS));
                }
            }
        }

        return new self($provider, $language, $formats);
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    /**
     * @return list<string>|null
     */
    public function formats(): ?array
    {
        return $this->formats;
    }

    /**
     * Only what was set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            ['provider' => $this->provider, 'language' => $this->language, 'formats' => $this->formats],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
