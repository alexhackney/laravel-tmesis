<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use Illuminate\Support\Str;

/**
 * One place a job output is copied to. The openapi component JobTarget (tmesis
 * `v0.6.0`, `docs/API.md` 9.10), an entry of Spec's `targets` map: the 12a.3
 * PlanTarget shape WITHOUT `artifact`, which the node fills with the output's
 * generation kind when it files the plan at finalization.
 *
 * The shape rules the node refuses on the request alone are made unrepresentable, as
 * on PlanTarget: one constructor per OUTPUT, each taking the location field that
 * output takes (`prefix` for `hls_ladder`, `mp4`, `thumbnails` and `transcript`; `key`
 * for `audio` and `probe`; `master_name` on `hls_ladder` only), and exactly one of a
 * `destination_ref` and a `signed` bucket (the `$destination` argument is one or the
 * other). The output a target was built for is what Spec::target() files it under.
 *
 * Everything else is the node's `422`: whether the destination is configured, whether
 * the location is safe, and two targets of one job whose objects could meet.
 *
 * **The location is the complete bucket-relative one.** The node inserts no job or
 * generation component; overwrite avoidance is the caller's.
 */
final readonly class JobTarget
{
    private function __construct(
        private string $output,
        private string $id,
        private ?string $destinationRef,
        private ?JobSignedTarget $signed,
        private ?string $key,
        private ?string $prefix,
        private ?string $masterName,
    ) {}

    /**
     * The HLS asset under `$prefix`: its master (`$masterName`, default
     * `playlist.m3u8`), every playlist, a `<rung>/` directory per rung and
     * `captions/<lang>/`.
     *
     * @param  string|JobSignedTarget  $destination  a `destination_ref` configured on
     *                                               the node, or a signed bucket
     * @param  string|null  $id  a caller-minted ULID unique in the job, minted here when null
     */
    public static function hlsLadder(string|JobSignedTarget $destination, string $prefix, ?string $masterName = null, ?string $id = null): self
    {
        return self::build(Spec::OUTPUT_HLS_LADDER, $destination, null, $prefix, $masterName, $id);
    }

    /**
     * One `<rung>.mp4` per video rung under `$prefix`.
     *
     * @param  string|JobSignedTarget  $destination  a `destination_ref` configured on
     *                                               the node, or a signed bucket
     */
    public static function mp4(string|JobSignedTarget $destination, string $prefix, ?string $id = null): self
    {
        return self::build(Spec::OUTPUT_MP4, $destination, null, $prefix, null, $id);
    }

    /**
     * Images named by timestamp under `$prefix`; nothing else may sit under it.
     *
     * @param  string|JobSignedTarget  $destination  a `destination_ref` configured on
     *                                               the node, or a signed bucket
     */
    public static function thumbnails(string|JobSignedTarget $destination, string $prefix, ?string $id = null): self
    {
        return self::build(Spec::OUTPUT_THUMBNAILS, $destination, null, $prefix, null, $id);
    }

    /**
     * `transcript.vtt`, `.srt` and `.json` under `$prefix` (all three names are
     * reserved there, whatever the formats ask for).
     *
     * @param  string|JobSignedTarget  $destination  a `destination_ref` configured on
     *                                               the node, or a signed bucket
     */
    public static function transcript(string|JobSignedTarget $destination, string $prefix, ?string $id = null): self
    {
        return self::build(Spec::OUTPUT_TRANSCRIPT, $destination, null, $prefix, null, $id);
    }

    /**
     * The one audio file at `$key`.
     *
     * @param  string|JobSignedTarget  $destination  a `destination_ref` configured on
     *                                               the node, or a signed bucket
     */
    public static function audio(string|JobSignedTarget $destination, string $key, ?string $id = null): self
    {
        return self::build(Spec::OUTPUT_AUDIO, $destination, $key, null, null, $id);
    }

    /**
     * The one probe JSON at `$key`.
     *
     * @param  string|JobSignedTarget  $destination  a `destination_ref` configured on
     *                                               the node, or a signed bucket
     */
    public static function probe(string|JobSignedTarget $destination, string $key, ?string $id = null): self
    {
        return self::build(Spec::OUTPUT_PROBE, $destination, $key, null, null, $id);
    }

    private static function build(string $output, string|JobSignedTarget $destination, ?string $key, ?string $prefix, ?string $masterName, ?string $id): self
    {
        return new self(
            $output,
            $id ?? (string) Str::ulid(),
            is_string($destination) ? $destination : null,
            $destination instanceof JobSignedTarget ? $destination : null,
            $key,
            $prefix,
            $masterName,
        );
    }

    /**
     * The output this target is for, one of `Spec::OUTPUTS`. Not rendered: it is the
     * key of the `targets` map.
     */
    public function output(): string
    {
        return $this->output;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function destinationRef(): ?string
    {
        return $this->destinationRef;
    }

    public function signed(): ?JobSignedTarget
    {
        return $this->signed;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    public function prefix(): ?string
    {
        return $this->prefix;
    }

    public function masterName(): ?string
    {
        return $this->masterName;
    }

    /**
     * The same target as a PlanTarget for the output's generation, which is how the
     * node files it at finalization (revision 1): the shape to start from when
     * replacing a job generation's plan with `replaceJobTransferPlan()`.
     */
    public function toPlanTarget(): PlanTarget
    {
        $destination = $this->signed === null
            ? (string) $this->destinationRef
            : SignedDestination::make(
                $this->signed->region(),
                $this->signed->bucket(),
                $this->signed->endpoint(),
                $this->signed->addressing(),
                $this->signed->partSizeMb(),
                $this->signed->uploadConcurrency(),
            );

        return PlanTarget::forKind(
            Spec::GENERATION_KINDS[$this->output],
            $destination,
            (string) ($this->prefix ?? $this->key),
            $this->masterName,
            $this->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'destination_ref' => $this->destinationRef,
            'signed' => $this->signed?->toArray(),
            'key' => $this->key,
            'prefix' => $this->prefix,
            'master_name' => $this->masterName,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
