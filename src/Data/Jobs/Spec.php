<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * One transcode job: its source, its outputs, and where each output goes. The openapi
 * component Spec (tmesis `v0.6.0`, `docs/API.md` 9.10), the body of
 * `createTranscodeJob` (`POST /api/v1/transcode/jobs`). Named exactly after the
 * component, generic as that name is, so the inventory pairs them.
 *
 * ```php
 * $job = Spec::make(JobSource::destinationRef('media', 'uploads/abc.mov'), idempotencyKey: 'video-941-v2')
 *     ->ladder('vod-1080p')
 *     ->target(JobTarget::hlsLadder('media', 'videos/941'))
 *     ->target(JobTarget::thumbnails('assets', 'thumbs/941'))
 *     ->callbackUrl('https://app.example.com/api/webhooks/tmesis')
 *     ->labels(['video_id' => '941']);
 * ```
 *
 * **The outputs come from the targets.** `outputs` is rendered from the targets in the
 * order their outputs were first given, and `targets` is the map from each output to
 * its targets, so "an output with no target" and "a target for an output not asked
 * for" (both `422` at the node) cannot be built.
 *
 * **`idempotency_key` is REQUIRED here and never minted.** The document makes it
 * optional; this package does not, because it is the retry handle: a repeat with the
 * same key answers `200` with the EXISTING job (a completed one included), so a caller
 * that timed out resends the same Spec and cannot queue the same work twice. Mint it
 * from your own record (for example the transcode request's id) and keep it.
 *
 * The reserved and removed fields are never exposed: `source.upload_id`
 * (`422 source_not_supported`) and `destination`, which `targets` replaced
 * (`destination.origin` is `422 destination_not_supported`). The output names
 * `storyboard`, `bif` (E8) and `channel_ready` (E4) are `422 output_not_supported`
 * and have no constructor. Checked here: the document's ranges and the shape rules
 * the node refuses on the request alone. The node's: whether a destination, a ladder
 * or a rung exists, whether a transcript provider is configured, and two targets
 * whose objects could meet (D3).
 */
final readonly class Spec
{
    public const OUTPUT_HLS_LADDER = 'hls_ladder';

    public const OUTPUT_MP4 = 'mp4';

    public const OUTPUT_AUDIO = 'audio';

    public const OUTPUT_THUMBNAILS = 'thumbnails';

    public const OUTPUT_PROBE = 'probe';

    public const OUTPUT_TRANSCRIPT = 'transcript';

    /**
     * The v1 output set.
     *
     * @var list<string>
     */
    public const OUTPUTS = [
        self::OUTPUT_HLS_LADDER,
        self::OUTPUT_MP4,
        self::OUTPUT_AUDIO,
        self::OUTPUT_THUMBNAILS,
        self::OUTPUT_PROBE,
        self::OUTPUT_TRANSCRIPT,
    ];

    /**
     * Each output's artifact generation kind (`ArtifactStatus::JOB_KINDS`): what
     * `jobArtifacts()` reports and a PlanTarget for it names.
     *
     * @var array<string, string>
     */
    public const GENERATION_KINDS = [
        self::OUTPUT_HLS_LADDER => ArtifactStatus::KIND_JOB_HLS,
        self::OUTPUT_MP4 => ArtifactStatus::KIND_JOB_MP4,
        self::OUTPUT_AUDIO => ArtifactStatus::KIND_JOB_AUDIO,
        self::OUTPUT_THUMBNAILS => ArtifactStatus::KIND_JOB_THUMBNAILS,
        self::OUTPUT_PROBE => ArtifactStatus::KIND_JOB_PROBE,
        self::OUTPUT_TRANSCRIPT => ArtifactStatus::KIND_JOB_TRANSCRIPT,
    ];

    /**
     * The outputs that need a `ladder`.
     *
     * @var list<string>
     */
    public const LADDER_OUTPUTS = [self::OUTPUT_HLS_LADDER, self::OUTPUT_MP4];

    public const MAX_IDEMPOTENCY_KEY_BYTES = 255;

    public const MIN_TIMEOUT_SECONDS = 60;

    public const MAX_TIMEOUT_SECONDS = 172800;

    /**
     * @param  array<string, list<JobTarget>>  $targets  output to its targets, in order
     * @param  array<string, mixed>  $fields  the optional top-level fields that were set
     */
    private function __construct(
        private JobSource $source,
        private string $idempotencyKey,
        private array $targets = [],
        private array $fields = [],
    ) {}

    /**
     * @param  string  $idempotencyKey  1 to 255 bytes, caller-minted and reused on
     *                                  every retry of this job
     */
    public static function make(JobSource $source, string $idempotencyKey): self
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_BYTES) {
            throw InvalidConfigurationException::invalid('idempotency_key', 'it must be 1 to '.self::MAX_IDEMPOTENCY_KEY_BYTES.' bytes');
        }

        return new self($source, $idempotencyKey);
    }

    /**
     * Ask for the targets' outputs and send each to its target. Called again for an
     * output already asked for, it adds more targets to it. Target ids are unique
     * across the whole job.
     */
    public function target(JobTarget ...$targets): self
    {
        $map = $this->targets;
        $ids = [];

        foreach ($map as $existing) {
            foreach ($existing as $target) {
                $ids[$target->id()] = true;
            }
        }

        foreach ($targets as $target) {
            if (isset($ids[$target->id()])) {
                throw InvalidConfigurationException::invalid("targets.{$target->output()}", "the target id {$target->id()} is already used in this job");
            }

            $ids[$target->id()] = true;
            $map[$target->output()][] = $target;
        }

        return new self($this->source, $this->idempotencyKey, $map, $this->fields);
    }

    /**
     * A ladder in the node's `encode.ladders`. Required when `hls_ladder` or `mp4`
     * is asked for.
     */
    public function ladder(string $ladder): self
    {
        if (trim($ladder) === '') {
            throw InvalidConfigurationException::invalid('ladder', 'it must name a ladder');
        }

        return $this->with('ladder', $ladder);
    }

    /**
     * Where this job's `job.*` events, AND its generations' `artifact.*` and
     * `transfer.*` events, are delivered. Null (the default) is the node's
     * `webhooks.url`.
     */
    public function callbackUrl(?string $url): self
    {
        if ($url !== null) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if (! in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
                throw InvalidConfigurationException::invalid('callback_url', 'it must be an absolute http or https URL');
            }
        }

        return $this->with('callback_url', $url);
    }

    /**
     * `low`, `normal` (the default) or `high`: orders the local queue and never lets
     * a job preempt live work.
     */
    public function priority(string $priority): self
    {
        if (! in_array($priority, JobStatus::PRIORITIES, true)) {
            throw InvalidConfigurationException::invalid('priority', 'it must be one of '.implode(', ', JobStatus::PRIORITIES));
        }

        return $this->with('priority', $priority);
    }

    /**
     * An opaque map echoed in every job event. The stream limits: at most 16 keys,
     * 64-byte keys, 256-byte values. An empty map is omitted, which is the node's
     * default.
     *
     * @param  array<string, string>  $labels
     */
    public function labels(array $labels): self
    {
        if (count($labels) > StreamSpec::MAX_LABELS) {
            throw InvalidConfigurationException::invalid('labels', 'at most '.StreamSpec::MAX_LABELS.' keys');
        }

        foreach ($labels as $key => $value) {
            if (strlen((string) $key) > StreamSpec::MAX_LABEL_KEY_BYTES) {
                throw InvalidConfigurationException::invalid("labels.{$key}", 'the key is over 64 bytes');
            }

            if (strlen($value) > StreamSpec::MAX_LABEL_VALUE_BYTES) {
                throw InvalidConfigurationException::invalid("labels.{$key}", 'the value is over 256 bytes');
            }
        }

        return $this->with('labels', $labels);
    }

    /**
     * Running time allowed, 60 to 172800 seconds, counted only while the job runs:
     * an eviction or a requeue stops the clock. Omitted is 21600.
     */
    public function timeoutSeconds(int $seconds): self
    {
        if ($seconds < self::MIN_TIMEOUT_SECONDS || $seconds > self::MAX_TIMEOUT_SECONDS) {
            throw InvalidConfigurationException::invalid('timeout_seconds', 'it must be between '.self::MIN_TIMEOUT_SECONDS.' and '.self::MAX_TIMEOUT_SECONDS);
        }

        return $this->with('timeout_seconds', $seconds);
    }

    public function audio(AudioOpts $options): self
    {
        return $this->with('audio', $options);
    }

    public function thumbnails(ThumbnailOpts $options): self
    {
        return $this->with('thumbnails', $options);
    }

    public function mp4(MP4Opts $options): self
    {
        return $this->with('mp4', $options);
    }

    public function transcript(TranscriptOpts $options): self
    {
        return $this->with('transcript', $options);
    }

    public function source(): JobSource
    {
        return $this->source;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * The outputs asked for, in the order their targets were first given.
     *
     * @return list<string>
     */
    public function outputs(): array
    {
        return array_keys($this->targets);
    }

    /**
     * @return array<string, list<JobTarget>>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * The request body. A URL source may be presigned, and so a credential: log
     * toRedactedArray(), never this.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->render($this->source->toArray());
    }

    /**
     * The body with a URL source's query string removed: what the fake records and
     * what is safe to log.
     *
     * @return array<string, mixed>
     */
    public function toRedactedArray(): array
    {
        return $this->render($this->source->redacted());
    }

    /**
     * @param  array<string, string>  $source
     * @return array<string, mixed>
     */
    private function render(array $source): array
    {
        if ($this->targets === []) {
            throw InvalidConfigurationException::invalid('outputs', 'a job asks for at least one output: add a target()');
        }

        if (! isset($this->fields['ladder']) && array_intersect(self::LADDER_OUTPUTS, $this->outputs()) !== []) {
            throw InvalidConfigurationException::invalid('ladder', 'hls_ladder and mp4 need a ladder');
        }

        $body = [
            'source' => $source,
            'outputs' => $this->outputs(),
            'targets' => array_map(
                static fn (array $targets): array => array_map(static fn (JobTarget $target): array => $target->toArray(), $targets),
                $this->targets,
            ),
            'idempotency_key' => $this->idempotencyKey,
        ];

        foreach ($this->fields as $field => $value) {
            $value = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : $value;

            // An empty map or option object is the node's default; `[]` would go out
            // as a JSON array, which is a type error there.
            if ($value === []) {
                continue;
            }

            $body[$field] = $value;
        }

        return $body;
    }

    private function with(string $field, mixed $value): self
    {
        $fields = $this->fields;
        $fields[$field] = $value;

        return new self($this->source, $this->idempotencyKey, $this->targets, $fields);
    }
}
