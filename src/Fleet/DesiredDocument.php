<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Keys\StreamId;

/**
 * What one node should be running: the fleet-owned streams, the revocation list and
 * the open posture (docs/API.md 15.3). The host app builds one per heartbeat through
 * its DesiredStateProvider; the package owns the REVISION.
 *
 * Every collection is a FULL replacement at the node, so an empty document is not
 * "nothing to say": it releases every fleet stream and clears the fleet's revocations.
 *
 * `canonical()` is what the revision is keyed on: streams sorted by id, relays by id,
 * labels by key, the revocations sorted and deduplicated, with no revision in it. Two
 * documents with the same content hash the same whatever order the app built them in,
 * so the revision moves when the content does and never otherwise.
 *
 * The jobs pause (`v0.9.0`, handover 3.5) is part of that content: setting, changing or
 * dropping it moves the revision. The wire ALWAYS carries `jobs_pause` (null when none),
 * because absent and null both CLEAR a pause at the node and "cleared" must never be an
 * accident of omission. The canonical form leaves a null pause out, so a document
 * without one hashes exactly as it did before `v0.9.0` and an upgrade bumps no node.
 */
final class DesiredDocument
{
    /** @var array<string, DesiredStream> */
    private array $streams = [];

    /** @var array<string, true> */
    private array $revocations = [];

    private bool $openPosture = false;

    private ?DesiredJobsPause $jobsPause = null;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Add a fleet-owned stream. A stream id twice is refused: the node rejects the
     * whole revision for it.
     */
    public function stream(DesiredStream $stream): self
    {
        if (isset($this->streams[$stream->id])) {
            throw InvalidConfigurationException::invalid('streams', "the stream id [{$stream->id}] appears twice");
        }

        $this->streams[$stream->id] = $stream;

        return $this;
    }

    /**
     * Revoke streams: the node refuses their NEXT handshake with `revoked`. An attached
     * publisher keeps publishing (docs/API.md 10.2); pair a revocation with a kick.
     */
    public function revoke(string ...$streams): self
    {
        foreach ($streams as $id) {
            if (! StreamId::isValid($id)) {
                throw InvalidConfigurationException::invalid('revocations', "[{$id}] is not a stream id");
            }

            $this->revocations[$id] = true;
        }

        return $this;
    }

    /**
     * Whether the node accepts a validly signed key for a stream it has never seen,
     * overriding its `ingest.keys.open` while enrolled.
     */
    public function openPosture(bool $open = true): self
    {
        $this->openPosture = $open;

        return $this;
    }

    /**
     * Tell the node to claim no new transcode job (`v0.9.0`, handover 3.5); null clears.
     * A FULL replacement: hand the same pause to every document for as long as it should
     * hold, because a document built without one ends it (and an API pause the box held
     * when it joined).
     */
    public function jobsPause(?DesiredJobsPause $pause): self
    {
        $this->jobsPause = $pause;

        return $this;
    }

    public function desiredJobsPause(): ?DesiredJobsPause
    {
        return $this->jobsPause;
    }

    /**
     * The revocation list, sorted and deduplicated: the order the digest is taken in.
     *
     * @return list<string>
     */
    public function revocations(): array
    {
        $ids = array_map('strval', array_keys($this->revocations));
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @return list<DesiredStream> sorted by id
     */
    public function streams(): array
    {
        $streams = $this->streams;
        ksort($streams, SORT_STRING);

        return array_values($streams);
    }

    public function isOpenPosture(): bool
    {
        return $this->openPosture;
    }

    /**
     * The revocation digest the node reports for this list (docs/API.md 15.2).
     */
    public function revocationsDigest(): string
    {
        return RevocationsDigest::of($this->revocations());
    }

    /**
     * The wire object at a revision. Arrays stay arrays and maps stay objects when
     * empty, so the JSON matches the schema's shapes. `jobs_pause` is always present.
     *
     * @return array{revision: int, streams: list<array<string, mixed>>, revocations: list<string>, open_posture: bool, jobs_pause: array{until: string|null, reason: string}|null}
     */
    public function toWire(int $revision): array
    {
        if ($revision < 1) {
            throw InvalidConfigurationException::invalid('revision', 'a desired-state revision is 1 or above');
        }

        return [
            'revision' => $revision,
            'streams' => array_map(fn (DesiredStream $stream): array => $stream->toWire(), $this->streams()),
            'revocations' => $this->revocations(),
            'open_posture' => $this->openPosture,
            'jobs_pause' => $this->jobsPause?->toWire(),
        ];
    }

    /**
     * The canonical encoding the revision is keyed on: the wire object without its
     * revision, every collection in a fixed order, and no `jobs_pause` key when there
     * is no pause (so the pre-`v0.9.0` hash of a pause-free document is unchanged).
     */
    public function canonical(): string
    {
        $wire = $this->toWire(1);
        unset($wire['revision']);

        if ($wire['jobs_pause'] === null) {
            unset($wire['jobs_pause']);
        }

        return json_encode($wire, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'streams' => array_keys($this->streams),
            'revocations' => $this->revocations(),
            'open_posture' => $this->openPosture,
            'jobs_pause' => $this->jobsPause?->toWire(),
        ];
    }
}
