<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * One kept span of one input. The openapi component EditRange (tmesis `v0.10.0`,
 * `docs/API.md` 9.13), an entry of a `concat` operation's `ranges`.
 *
 * `source` is a JobSource of the same forms as the job's; omitted means the top-level
 * source. A range `source` URL may be presigned, and so a credential: log
 * toRedactedArray(), never toArray().
 */
final readonly class EditRange
{
    /**
     * @param  array<string, mixed>  $extra  fields a newer node sent that this class does not name
     */
    private function __construct(
        private float $inSeconds,
        private float $outSeconds,
        private ?JobSource $source,
        private array $extra = [],
    ) {}

    public static function make(float $inSeconds, float $outSeconds, ?JobSource $source = null): self
    {
        if ($inSeconds < 0) {
            throw InvalidConfigurationException::invalid('ranges.in_seconds', 'it must be 0 or more');
        }

        if ($outSeconds <= $inSeconds) {
            throw InvalidConfigurationException::invalid('ranges.out_seconds', 'it must be greater than in_seconds');
        }

        return new self($inSeconds, $outSeconds, $source);
    }

    /**
     * A range as the node echoed it back, read leniently.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $source = $data['source'] ?? null;
        $in = $data['in_seconds'] ?? null;
        $out = $data['out_seconds'] ?? null;
        unset($data['source'], $data['in_seconds'], $data['out_seconds']);

        /** @var array<string, mixed>|null $source */
        return new self(
            is_numeric($in) ? (float) $in : 0.0,
            is_numeric($out) ? (float) $out : 0.0,
            is_array($source) ? JobSource::fromArray($source) : null,
            $data,
        );
    }

    public function inSeconds(): float
    {
        return $this->inSeconds;
    }

    public function outSeconds(): float
    {
        return $this->outSeconds;
    }

    public function source(): ?JobSource
    {
        return $this->source;
    }

    /**
     * The body. A range source URL may be presigned: never log this.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->render(false);
    }

    /**
     * The body with a URL source rebuilt without its query string: safe to log.
     *
     * @return array<string, mixed>
     */
    public function toRedactedArray(): array
    {
        return $this->render(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(bool $redacted): array
    {
        $body = ['in_seconds' => $this->inSeconds, 'out_seconds' => $this->outSeconds] + $this->extra;

        if ($this->source !== null) {
            $body['source'] = $redacted ? $this->source->redacted() : $this->source->toArray();
        }

        return $body;
    }
}
