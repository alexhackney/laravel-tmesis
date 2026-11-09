<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * Where an edit's outputs go. The openapi component MediaEditTargets (tmesis `v0.10.0`,
 * `docs/API.md` 9.13): the REQUIRED `media_edit` (one key target, the complete object
 * key) and an optional `hls_ladder` (a prefix target, which needs the request's
 * `ladder`).
 *
 * Every key and prefix is TOLD by the caller and complete; the node derives none. No
 * output may equal, hold or sit inside an input.
 */
final readonly class MediaEditTargets
{
    private function __construct(
        private JobTarget $mediaEdit,
        private ?JobTarget $hlsLadder = null,
    ) {}

    /**
     * @param  JobTarget  $mediaEdit  built with JobTarget::mediaEdit()
     * @param  JobTarget|null  $hlsLadder  built with JobTarget::hlsLadder(); the request
     *                                     then names a `ladder`
     */
    public static function make(JobTarget $mediaEdit, ?JobTarget $hlsLadder = null): self
    {
        if ($mediaEdit->output() !== Spec::OUTPUT_MEDIA_EDIT) {
            throw InvalidConfigurationException::invalid('targets.media_edit', 'it must be a JobTarget::mediaEdit() target');
        }

        if ($hlsLadder !== null && $hlsLadder->output() !== Spec::OUTPUT_HLS_LADDER) {
            throw InvalidConfigurationException::invalid('targets.hls_ladder', 'it must be a JobTarget::hlsLadder() target');
        }

        if ($hlsLadder !== null && $hlsLadder->id() === $mediaEdit->id()) {
            throw InvalidConfigurationException::invalid('targets.hls_ladder', "the target id {$hlsLadder->id()} is already used in this job");
        }

        return new self($mediaEdit, $hlsLadder);
    }

    public function mediaEdit(): JobTarget
    {
        return $this->mediaEdit;
    }

    public function hlsLadder(): ?JobTarget
    {
        return $this->hlsLadder;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $body = [Spec::OUTPUT_MEDIA_EDIT => $this->mediaEdit->toArray()];

        if ($this->hlsLadder !== null) {
            $body[Spec::OUTPUT_HLS_LADDER] = $this->hlsLadder->toArray();
        }

        return $body;
    }
}
