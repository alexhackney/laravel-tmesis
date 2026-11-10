<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * One step of an edit. The openapi component EditOperation (tmesis `v0.10.0`,
 * `docs/API.md` 9.13), an entry of EditSpec's `operations`: one constructor per `op`,
 * each taking only the fields that op takes (the node refuses a field that does not
 * belong, it does not ignore it).
 *
 * Checked here: the ranges of the document and the even-dimension rule, which the
 * node refuses without reading a file. What the probe decides (a range past the end
 * of the source, a crop outside the frame) is the JOB's failure, not the submission's.
 */
final readonly class EditOperation
{
    public const OP_TRIM = 'trim';

    public const OP_CONCAT = 'concat';

    public const OP_REWRAP = 'rewrap';

    public const OP_EXTRACT_AUDIO = 'extract_audio';

    public const OP_CROP = 'crop';

    public const OP_SCALE = 'scale';

    /** @var list<string> */
    public const OPS = [
        self::OP_TRIM,
        self::OP_CONCAT,
        self::OP_REWRAP,
        self::OP_EXTRACT_AUDIO,
        self::OP_CROP,
        self::OP_SCALE,
    ];

    /**
     * The operations that choose WHICH spans are kept: at most one per edit, and first.
     *
     * @var list<string>
     */
    public const RANGE_OPS = [self::OP_TRIM, self::OP_CONCAT];

    public const CODEC_AAC = 'aac';

    public const CODEC_MP3 = 'mp3';

    public const CODEC_OPUS = 'opus';

    /** @var list<string> */
    public const CODECS = [self::CODEC_AAC, self::CODEC_MP3, self::CODEC_OPUS];

    public const FIT_CONTAIN = 'contain';

    public const FIT_COVER = 'cover';

    public const FIT_STRETCH = 'stretch';

    /** @var list<string> */
    public const FITS = [self::FIT_CONTAIN, self::FIT_COVER, self::FIT_STRETCH];

    public const MIN_KBPS = 32;

    public const MAX_KBPS = 512;

    public const MAX_RANGES = 32;

    /**
     * @param  array<string, mixed>  $fields  the op's fields (scalars, and whatever a newer node sent)
     * @param  list<EditRange>  $ranges  a concat's ranges
     */
    private function __construct(
        private string $op,
        private array $fields = [],
        private array $ranges = [],
    ) {}

    /**
     * Keep `[$inSeconds, $outSeconds)` of the source.
     */
    public static function trim(float $inSeconds, float $outSeconds): self
    {
        if ($inSeconds < 0) {
            throw InvalidConfigurationException::invalid('operations.in_seconds', 'it must be 0 or more');
        }

        if ($outSeconds <= $inSeconds) {
            throw InvalidConfigurationException::invalid('operations.out_seconds', 'it must be greater than in_seconds');
        }

        return new self(self::OP_TRIM, ['in_seconds' => $inSeconds, 'out_seconds' => $outSeconds]);
    }

    /**
     * Join 1 to 32 ranges in order. Ranges of one source may not overlap.
     *
     * @param  list<EditRange>  $ranges
     */
    public static function concat(array $ranges): self
    {
        if ($ranges === [] || count($ranges) > self::MAX_RANGES) {
            throw InvalidConfigurationException::invalid('operations.ranges', 'a concat takes 1 to '.self::MAX_RANGES.' ranges');
        }

        return new self(self::OP_CONCAT, [], $ranges);
    }

    /**
     * Copy the streams into the target container unchanged. Excludes `crop`, `scale`
     * and `extract_audio`.
     */
    public static function rewrap(): self
    {
        return new self(self::OP_REWRAP);
    }

    /**
     * Keep the audio only, as `$codec` (`aac`, `mp3` or `opus`) at `$kbps` (32 to 512,
     * default 128). Needs an audio container (omitted follows the codec); excludes
     * `crop` and `scale`.
     */
    public static function extractAudio(string $codec = self::CODEC_AAC, ?int $kbps = null): self
    {
        if (! in_array($codec, self::CODECS, true)) {
            throw InvalidConfigurationException::invalid('operations.codec', 'it must be one of '.implode(', ', self::CODECS));
        }

        if ($kbps !== null && ($kbps < self::MIN_KBPS || $kbps > self::MAX_KBPS)) {
            throw InvalidConfigurationException::invalid('operations.kbps', 'it must be between '.self::MIN_KBPS.' and '.self::MAX_KBPS);
        }

        return new self(self::OP_EXTRACT_AUDIO, ['codec' => $codec] + ($kbps === null ? [] : ['kbps' => $kbps]));
    }

    /**
     * A pixel window of the frame: `$x` and `$y` 0 or more, `$width` and `$height`
     * even and at least 2. Whether it sits inside the frame is the JOB's failure
     * (`edit_unsupported`), the probe being what knows the frame.
     */
    public static function crop(int $x, int $y, int $width, int $height): self
    {
        if ($x < 0 || $y < 0) {
            throw InvalidConfigurationException::invalid('operations.x', 'x and y must be 0 or more');
        }

        self::guardDimensions($width, $height);

        return new self(self::OP_CROP, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
    }

    /**
     * Resize to `$width` by `$height` (both even, at least 2). `$fit` is `contain`
     * (the default), `cover` or `stretch`.
     */
    public static function scale(int $width, int $height, ?string $fit = null): self
    {
        self::guardDimensions($width, $height);

        if ($fit !== null && ! in_array($fit, self::FITS, true)) {
            throw InvalidConfigurationException::invalid('operations.fit', 'it must be one of '.implode(', ', self::FITS));
        }

        return new self(self::OP_SCALE, ['width' => $width, 'height' => $height] + ($fit === null ? [] : ['fit' => $fit]));
    }

    /**
     * An operation as the node echoed it back (`MediaEditAccepted::operations()`),
     * read leniently: nothing is re-validated.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fields = [];
        $ranges = [];

        foreach ($data as $key => $value) {
            if ($key === 'op') {
                continue;
            }

            if ($key === 'ranges') {
                foreach (is_array($value) ? $value : [] as $range) {
                    if (is_array($range)) {
                        /** @var array<string, mixed> $range */
                        $ranges[] = EditRange::fromArray($range);
                    }
                }

                continue;
            }

            // Everything else is kept as sent, a field a newer node added included.
            $fields[(string) $key] = $value;
        }

        return new self(is_string($data['op'] ?? null) ? $data['op'] : '', $fields, $ranges);
    }

    /**
     * One of OPS.
     */
    public function op(): string
    {
        return $this->op;
    }

    public function isRangeOperation(): bool
    {
        return in_array($this->op, self::RANGE_OPS, true);
    }

    /**
     * Whether this operation needs `/api/v1/media/edits`: the node refuses it on
     * `/transcode/jobs` (`crop`, `scale`, `rewrap`, `extract_audio`).
     */
    public function isMediaEditsOnly(): bool
    {
        return ! $this->isRangeOperation();
    }

    public function inSeconds(): ?float
    {
        return $this->number('in_seconds');
    }

    public function outSeconds(): ?float
    {
        return $this->number('out_seconds');
    }

    /**
     * A concat's ranges; empty for any other op.
     *
     * @return list<EditRange>
     */
    public function ranges(): array
    {
        return $this->ranges;
    }

    public function codec(): ?string
    {
        $value = $this->fields['codec'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function kbps(): ?int
    {
        return $this->integer('kbps');
    }

    public function x(): ?int
    {
        return $this->integer('x');
    }

    public function y(): ?int
    {
        return $this->integer('y');
    }

    public function width(): ?int
    {
        return $this->integer('width');
    }

    public function height(): ?int
    {
        return $this->integer('height');
    }

    public function fit(): ?string
    {
        $value = $this->fields['fit'] ?? null;

        return is_string($value) ? $value : null;
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
        $body = ['op' => $this->op] + $this->fields;

        if ($this->op === self::OP_CONCAT || $this->ranges !== []) {
            $body['ranges'] = array_map(
                static fn (EditRange $range): array => $redacted ? $range->toRedactedArray() : $range->toArray(),
                $this->ranges,
            );
        }

        return $body;
    }

    private static function guardDimensions(int $width, int $height): void
    {
        foreach (['width' => $width, 'height' => $height] as $name => $value) {
            if ($value < 2 || $value % 2 !== 0) {
                throw InvalidConfigurationException::invalid("operations.{$name}", 'it must be even and at least 2');
            }
        }
    }

    private function number(string $key): ?float
    {
        $value = $this->fields[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function integer(string $key): ?int
    {
        $value = $this->fields[$key] ?? null;

        return is_int($value) ? $value : (is_float($value) ? (int) $value : null);
    }
}
