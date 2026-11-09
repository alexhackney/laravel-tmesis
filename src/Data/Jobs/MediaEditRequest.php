<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * One clip: a source, the operations that make it, and where it goes. The openapi
 * component MediaEditRequest (tmesis `v0.10.0`, `docs/API.md` 9.13), the body of
 * `createMediaEdit` (`POST /api/v1/media/edits`), a thin front over the job API that
 * builds ONE job and submits it. One clip per job.
 *
 * **EXPERIMENTAL in tmesis `v0.10.0`:** the shapes are stable, the node's behaviour is
 * field-gated until `v0.10.1` (see TranscodeJobs::createMediaEdit()).
 *
 * ```php
 * $clip = MediaEditRequest::make(
 *     JobSource::prefix('vod', 'replays/941'),
 *     EditSpec::make()->trim(120.0, 148.5)->crop(656, 0, 608, 1080)->scale(1080, 1920),
 *     JobTarget::mediaEdit('clips', 'clips/941/highlight-1.mp4'),
 *     idempotencyKey: 'clip-5521',
 * )->callbackUrl('https://app.example.com/api/webhooks/tmesis');
 * ```
 *
 * **`idempotency_key` is REQUIRED here and never minted**, as on Spec: a repeat with the
 * same key answers `200` with the EXISTING job in any state, so a caller that timed out
 * resends the same request and cannot cut the same clip twice. Mint it from your own
 * clip record.
 *
 * The pre-`v0.10.0` `destination` field is removed and never exposed. Checked here: the
 * document's ranges and the shape rules the node refuses on the request alone. The
 * node's: whether a destination or ladder exists, whether an input resolves, and every
 * input and output overlap (`422 validation_failed`, `409 destination_busy`). A missing
 * source is the JOB's `source_unreadable`, never a refusal at submission.
 */
final readonly class MediaEditRequest
{
    /**
     * @param  array<string, mixed>  $fields  the optional top-level fields that were set
     */
    private function __construct(
        private JobSource $source,
        private EditSpec $edit,
        private MediaEditTargets $targets,
        private string $idempotencyKey,
        private array $fields = [],
    ) {}

    /**
     * @param  JobTarget|MediaEditTargets  $target  the clip's key target, JobTarget::mediaEdit()
     * @param  string  $idempotencyKey  1 to 255 bytes, caller-minted and reused on every
     *                                  retry of this clip
     */
    public static function make(JobSource $source, EditSpec $edit, JobTarget|MediaEditTargets $target, string $idempotencyKey): self
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > Spec::MAX_IDEMPOTENCY_KEY_BYTES) {
            throw InvalidConfigurationException::invalid('idempotency_key', 'it must be 1 to '.Spec::MAX_IDEMPOTENCY_KEY_BYTES.' bytes');
        }

        return new self($source, $edit, $target instanceof MediaEditTargets ? $target : MediaEditTargets::make($target), $idempotencyKey);
    }

    /**
     * Also write an HLS ladder of the edited file to `$target` (JobTarget::hlsLadder(), a
     * prefix target) from the node's ladder `$ladder`.
     */
    public function hlsLadder(JobTarget $target, string $ladder): self
    {
        if (trim($ladder) === '') {
            throw InvalidConfigurationException::invalid('ladder', 'it must name a ladder');
        }

        $request = new self($this->source, $this->edit, MediaEditTargets::make($this->targets->mediaEdit(), $target), $this->idempotencyKey, $this->fields);

        return $request->with('ladder', $ladder);
    }

    /**
     * The output container: video `mp4` (the default), `mov`, `mkv`, `ts`, or audio
     * `m4a`, `mp3`, `ogg`. An `extract_audio` edit needs an audio one (omitted follows
     * the codec); every other edit a video one.
     */
    public function container(string $container): self
    {
        if (! in_array($container, Spec::CONTAINERS, true)) {
            throw InvalidConfigurationException::invalid('container', 'it must be one of '.implode(', ', Spec::CONTAINERS));
        }

        return $this->with('container', $container);
    }

    /**
     * Where this job's `job.*` events and its generations' `artifact.*` and
     * `transfer.*` events are delivered. Null (the default) is the node's `webhooks.url`.
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
     * `low`, `normal` (the default) or `high`.
     */
    public function priority(string $priority): self
    {
        if (! in_array($priority, JobStatus::PRIORITIES, true)) {
            throw InvalidConfigurationException::invalid('priority', 'it must be one of '.implode(', ', JobStatus::PRIORITIES));
        }

        return $this->with('priority', $priority);
    }

    /**
     * An opaque map echoed in every job event: at most 16 keys, 64-byte keys, 256-byte
     * values.
     *
     * @param  array<string, string>  $labels
     */
    public function labels(array $labels): self
    {
        Spec::guardLabels($labels);

        return $this->with('labels', $labels);
    }

    /**
     * Running time allowed, 60 to 172800 seconds. Omitted is 21600.
     */
    public function timeoutSeconds(int $seconds): self
    {
        if ($seconds < Spec::MIN_TIMEOUT_SECONDS || $seconds > Spec::MAX_TIMEOUT_SECONDS) {
            throw InvalidConfigurationException::invalid('timeout_seconds', 'it must be between '.Spec::MIN_TIMEOUT_SECONDS.' and '.Spec::MAX_TIMEOUT_SECONDS);
        }

        return $this->with('timeout_seconds', $seconds);
    }

    public function source(): JobSource
    {
        return $this->source;
    }

    public function edit(): EditSpec
    {
        return $this->edit;
    }

    public function targets(): MediaEditTargets
    {
        return $this->targets;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * The request body. A URL source or a range source may be presigned, and so a
     * credential: log toRedactedArray(), never this.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->render($this->source->toArray(), $this->edit->toArray());
    }

    /**
     * The body with every URL source rebuilt without its query string: what the fake
     * records and what is safe to log.
     *
     * @return array<string, mixed>
     */
    public function toRedactedArray(): array
    {
        return $this->render($this->source->redacted(), $this->edit->toRedactedArray());
    }

    /**
     * @param  array<string, string>  $source
     * @param  array<string, mixed>  $edit  `operations` and, for a frame-accurate cut, `cut`
     * @return array<string, mixed>
     */
    private function render(array $source, array $edit): array
    {
        $this->guardShape();

        $body = ['source' => $source] + $edit + [
            'targets' => $this->targets->toArray(),
            'idempotency_key' => $this->idempotencyKey,
        ];

        foreach ($this->fields as $field => $value) {
            if ($value === []) {
                continue;
            }

            $body[$field] = $value;
        }

        return $body;
    }

    /**
     * The container rule the node refuses on the request alone: `extract_audio` needs an
     * audio container, every other edit a video one.
     */
    private function guardShape(): void
    {
        if ($this->targets->hlsLadder() !== null && ! isset($this->fields['ladder'])) {
            throw InvalidConfigurationException::invalid('ladder', 'targets.hls_ladder needs a ladder');
        }

        $container = $this->fields['container'] ?? null;

        if (! is_string($container)) {
            return;
        }

        $audio = in_array($container, ['m4a', 'mp3', 'ogg'], true);

        if ($this->edit->extractsAudio() !== $audio) {
            throw InvalidConfigurationException::invalid('container', $this->edit->extractsAudio()
                ? 'an extract_audio edit needs an audio container (m4a, mp3, ogg)'
                : 'only an extract_audio edit takes an audio container; use mp4, mov, mkv or ts');
        }
    }

    private function with(string $field, mixed $value): self
    {
        $fields = $this->fields;
        $fields[$field] = $value;

        return new self($this->source, $this->edit, $this->targets, $this->idempotencyKey, $fields);
    }
}
