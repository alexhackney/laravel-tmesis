<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * What an edit does. The openapi component EditSpec (tmesis `v0.10.0`, `docs/API.md`
 * 9.13): the ordered `operations` and the `cut` mode. **EXPERIMENTAL in tmesis
 * `v0.10.0`** (the shapes are stable, the node's behaviour is field-gated until
 * `v0.10.1`; see TranscodeJobs::createMediaEdit()). It is Spec's `edit` on
 * `/transcode/jobs` and the `operations` plus `cut` of a MediaEditRequest.
 *
 * ```php
 * $edit = EditSpec::make()
 *     ->trim(120.0, 148.5)
 *     ->crop(656, 0, 608, 1080)
 *     ->scale(1080, 1920)
 *     ->frameAccurate();
 * ```
 *
 * Checked here, the shape rules the node refuses on the request alone: 1 to 32
 * operations; at most ONE range operation (`trim` or `concat`) and it first; `rewrap`
 * excludes `crop`, `scale` and `extract_audio`; `extract_audio` keeps no frames, so it
 * excludes `crop` and `scale`. Everything the probe decides is the job's failure.
 */
final readonly class EditSpec
{
    public const MAX_OPERATIONS = 32;

    /**
     * @param  list<EditOperation>  $operations
     * @param  array<string, mixed>  $extra  fields a newer node sent that this class does not name
     */
    private function __construct(
        private array $operations = [],
        private ?EditCut $cut = null,
        private array $extra = [],
    ) {}

    /**
     * An edit with no operation yet; add at least one before it is sent. With no range
     * operation the whole source is the input.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * A spec as the node echoed it back, read leniently.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $operations = [];

        foreach (is_array($data['operations'] ?? null) ? $data['operations'] : [] as $operation) {
            if (is_array($operation)) {
                /** @var array<string, mixed> $operation */
                $operations[] = EditOperation::fromArray($operation);
            }
        }

        $cut = $data['cut'] ?? null;
        unset($data['operations'], $data['cut']);

        /** @var array<string, mixed>|null $cut */
        return new self($operations, is_array($cut) ? EditCut::fromArray($cut) : null, $data);
    }

    public function trim(float $inSeconds, float $outSeconds): self
    {
        return $this->add(EditOperation::trim($inSeconds, $outSeconds));
    }

    /**
     * @param  list<EditRange>  $ranges
     */
    public function concat(array $ranges): self
    {
        return $this->add(EditOperation::concat($ranges));
    }

    public function rewrap(): self
    {
        return $this->add(EditOperation::rewrap());
    }

    public function extractAudio(string $codec = EditOperation::CODEC_AAC, ?int $kbps = null): self
    {
        return $this->add(EditOperation::extractAudio($codec, $kbps));
    }

    public function crop(int $x, int $y, int $width, int $height): self
    {
        return $this->add(EditOperation::crop($x, $y, $width, $height));
    }

    public function scale(int $width, int $height, ?string $fit = null): self
    {
        return $this->add(EditOperation::scale($width, $height, $fit));
    }

    /**
     * Add an operation built elsewhere, subject to the same shape rules.
     */
    public function add(EditOperation $operation): self
    {
        $operations = [...$this->operations, $operation];

        if (count($operations) > self::MAX_OPERATIONS) {
            throw InvalidConfigurationException::invalid('operations', 'at most '.self::MAX_OPERATIONS.' operations');
        }

        self::guardShape($operations);

        return new self($operations, $this->cut);
    }

    /**
     * Cut on the keyframe at or before each request: a stream copy, the node's
     * default (the field is omitted).
     */
    public function keyframe(): self
    {
        return new self($this->operations, null);
    }

    /**
     * Cut on the exact frame: the node re-encodes the head and tail GOPs only.
     */
    public function frameAccurate(): self
    {
        return new self($this->operations, EditCut::frameAccurate());
    }

    public function cut(): EditCut
    {
        return $this->cut ?? EditCut::keyframe();
    }

    /**
     * @return list<EditOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Whether any operation is one `/transcode/jobs` refuses (`crop`, `scale`,
     * `rewrap`, `extract_audio`): such an edit goes to `createMediaEdit()`.
     */
    public function needsMediaEdits(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->isMediaEditsOnly()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `extract_audio` is among the operations, which makes the container an
     * audio one.
     */
    public function extractsAudio(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->op() === EditOperation::OP_EXTRACT_AUDIO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any range carries its own source (a `concat` across inputs).
     */
    public function hasRangeSources(): bool
    {
        foreach ($this->operations as $operation) {
            foreach ($operation->ranges() as $range) {
                if ($range->source() !== null) {
                    return true;
                }
            }
        }

        return false;
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
     * The body with every range source URL rebuilt without its query string.
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
        if ($this->operations === []) {
            throw InvalidConfigurationException::invalid('operations', 'an edit needs at least one operation');
        }

        $body = [
            'operations' => array_map(
                static fn (EditOperation $operation): array => $redacted ? $operation->toRedactedArray() : $operation->toArray(),
                $this->operations,
            ),
        ];

        if ($this->cut !== null && $this->cut->isFrameAccurate()) {
            $body['cut'] = $this->cut->toArray();
        }

        return $body + $this->extra;
    }

    /**
     * @param  list<EditOperation>  $operations
     */
    private static function guardShape(array $operations): void
    {
        $ops = array_map(static fn (EditOperation $operation): string => $operation->op(), $operations);
        $rangeOps = array_filter($operations, static fn (EditOperation $operation): bool => $operation->isRangeOperation());

        if (count($rangeOps) > 1) {
            throw InvalidConfigurationException::invalid('operations', 'at most one range operation (trim or concat)');
        }

        if ($rangeOps !== [] && ! $operations[0]->isRangeOperation()) {
            throw InvalidConfigurationException::invalid('operations', 'the range operation (trim or concat) goes first');
        }

        if (in_array(EditOperation::OP_REWRAP, $ops, true)
            && array_intersect([EditOperation::OP_CROP, EditOperation::OP_SCALE, EditOperation::OP_EXTRACT_AUDIO], $ops) !== []) {
            throw InvalidConfigurationException::invalid('operations', 'rewrap excludes crop, scale and extract_audio');
        }

        if (in_array(EditOperation::OP_EXTRACT_AUDIO, $ops, true)
            && array_intersect([EditOperation::OP_CROP, EditOperation::OP_SCALE], $ops) !== []) {
            throw InvalidConfigurationException::invalid('operations', 'extract_audio keeps no frames, so it excludes crop and scale');
        }
    }
}
