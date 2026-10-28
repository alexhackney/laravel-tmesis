<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Str;

/**
 * One place a generation is copied to. The openapi component PlanTarget (tmesis
 * `v0.5.0`, `docs/API.md` 12a.3), an entry of PlanRequest's `targets`.
 *
 * The shape rules the node refuses on the request alone are made unrepresentable
 * here rather than checked: exactly one of `destination_ref` and `signed` (the
 * `$destination` argument is one or the other), the location field per kind (the
 * constructor per kind), `master_name` on HLS only. Since tmesis `v0.6.0` the rule
 * covers the six job kinds: `prefix` names an HLS target (`hls_sealed`, `job_hls`) or a
 * multi-file `files` target (`job_mp4`, `job_thumbnails`, `job_transcript`), and `key`
 * a one-file generation (a recording, `job_audio`, `job_probe`);
 * `ArtifactStatus::PREFIX_KINDS` is the one list. The openapi schema
 * encodes none of them (every field but `id` and `artifact` is optional there); they
 * are the `422` rules its `replaceTransferPlan` response and `API.md` 12a.3 state.
 * Everything else is the node's: whether the destination is configured, whether a
 * prefix, key or master name is safe, whether `artifact` is the generation's kind,
 * and overlapping keys at one destination.
 *
 * **The location is the complete bucket-relative one.** The node inserts no stream,
 * session, generation or revision component, so overwrite avoidance is the caller's:
 * use a fresh location when an earlier request's outcome is ambiguous.
 */
final readonly class PlanTarget
{
    private function __construct(
        private string $id,
        private string $artifact,
        private ?string $destinationRef,
        private ?SignedDestination $signed,
        private ?string $key,
        private ?string $prefix,
        private ?string $masterName,
    ) {}

    /**
     * An `hls_sealed` target: the replay set under `$prefix`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     * @param  string  $prefix  the complete bucket-relative prefix; empty is the bucket
     *                          root. It REPLACES the destination's live prefix
     * @param  string|null  $masterName  the replay master's remote basename, ending
     *                                   `.m3u8`; null is the node's `playlist.m3u8`
     *                                   (`vod.m3u8` before tmesis `v0.8.0`)
     * @param  string|null  $id  a caller-minted ULID, minted here when null
     */
    public static function hlsSealed(string|SignedDestination $destination, string $prefix, ?string $masterName = null, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_HLS_SEALED, $destination, null, $prefix, $masterName, $id);
    }

    /**
     * A `recording_ingest` target: the one recording file at `$key`. The key's
     * extension never converts the container.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function recordingIngest(string|SignedDestination $destination, string $key, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_RECORDING_INGEST, $destination, $key, null, null, $id);
    }

    /**
     * A `recording_master` target: the one recording file at `$key`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function recordingMaster(string|SignedDestination $destination, string $key, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_RECORDING_MASTER, $destination, $key, null, null, $id);
    }

    /**
     * A `job_hls` target (tmesis `v0.6.0`): a job's HLS asset under `$prefix`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     * @param  string|null  $masterName  the master's remote basename, ending `.m3u8`;
     *                                   null is the node's `playlist.m3u8`
     */
    public static function jobHls(string|SignedDestination $destination, string $prefix, ?string $masterName = null, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_JOB_HLS, $destination, null, $prefix, $masterName, $id);
    }

    /**
     * A `job_mp4` target: the files shape, one `<rung>.mp4` per rung under `$prefix`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function jobMp4(string|SignedDestination $destination, string $prefix, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_JOB_MP4, $destination, null, $prefix, null, $id);
    }

    /**
     * A `job_thumbnails` target: the files shape under `$prefix`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function jobThumbnails(string|SignedDestination $destination, string $prefix, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_JOB_THUMBNAILS, $destination, null, $prefix, null, $id);
    }

    /**
     * A `job_transcript` target: the files shape under `$prefix`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function jobTranscript(string|SignedDestination $destination, string $prefix, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_JOB_TRANSCRIPT, $destination, null, $prefix, null, $id);
    }

    /**
     * A `job_audio` target: the one audio file at `$key`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function jobAudio(string|SignedDestination $destination, string $key, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_JOB_AUDIO, $destination, $key, null, null, $id);
    }

    /**
     * A `job_probe` target: the one probe JSON at `$key`.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function jobProbe(string|SignedDestination $destination, string $key, ?string $id = null): self
    {
        return self::build(ArtifactStatus::KIND_JOB_PROBE, $destination, $key, null, null, $id);
    }

    /**
     * A target for a generation of `$kind` (`ArtifactStatus::kind()`), when the kind
     * arrives as data. `$location` is the prefix for a kind in
     * `ArtifactStatus::PREFIX_KINDS` and the key for every other; a master name on a
     * kind with no master (`ArtifactStatus::MASTER_NAMES`) is refused.
     *
     * @param  string|SignedDestination  $destination  a `destination_ref` configured on
     *                                                 the node, or a signed bucket
     */
    public static function forKind(string $kind, string|SignedDestination $destination, string $location, ?string $masterName = null, ?string $id = null): self
    {
        if (! in_array($kind, ArtifactStatus::KINDS, true)) {
            throw InvalidConfigurationException::invalid('targets.artifact', 'it must be one of '.implode(', ', ArtifactStatus::KINDS));
        }

        if ($masterName !== null && ! isset(ArtifactStatus::MASTER_NAMES[$kind])) {
            throw InvalidConfigurationException::invalid('targets.master_name', 'a master name is for an hls_sealed or job_hls target only; a '.$kind.' target names its '.(in_array($kind, ArtifactStatus::PREFIX_KINDS, true) ? 'prefix' : 'key'));
        }

        return in_array($kind, ArtifactStatus::PREFIX_KINDS, true)
            ? self::build($kind, $destination, null, $location, $masterName, $id)
            : self::build($kind, $destination, $location, null, null, $id);
    }

    private static function build(string $artifact, string|SignedDestination $destination, ?string $key, ?string $prefix, ?string $masterName, ?string $id): self
    {
        return new self(
            $id ?? (string) Str::ulid(),
            $artifact,
            is_string($destination) ? $destination : null,
            $destination instanceof SignedDestination ? $destination : null,
            $key,
            $prefix,
            $masterName,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * One of `ArtifactStatus::KINDS`.
     */
    public function artifact(): string
    {
        return $this->artifact;
    }

    public function destinationRef(): ?string
    {
        return $this->destinationRef;
    }

    public function signed(): ?SignedDestination
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
     * The request body. A signed target's initial authorizations carry credentials;
     * see SignedRequest.
     *
     * The plan's bindings are needed only for a signed target with initial
     * authorizations, which take this target's id and the plan's revision and
     * inventory; PlanRequest::toArray() passes them.
     *
     * @param  int|null  $planRevision  the revision the plan creates: its expected_revision plus 1
     * @param  string|null  $inventorySha256  the plan's inventory_sha256
     * @return array<string, mixed>
     */
    public function toArray(?int $planRevision = null, ?string $inventorySha256 = null): array
    {
        $body = ['id' => $this->id, 'artifact' => $this->artifact];

        if ($this->destinationRef !== null) {
            $body['destination_ref'] = $this->destinationRef;
        }

        if ($this->signed !== null) {
            $body['signed'] = $this->signed->toArray($planRevision, $inventorySha256, $this->id);
        }

        if ($this->key !== null) {
            $body['key'] = $this->key;
        }

        if ($this->prefix !== null) {
            $body['prefix'] = $this->prefix;
        }

        if ($this->masterName !== null) {
            $body['master_name'] = $this->masterName;
        }

        return $body;
    }
}
