<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Str;

/**
 * Combine a stream's ingest parts into one master MP4 and upload it to ONE told key:
 * the body of `createMaster`. The openapi component MasterRequest (tmesis `v0.8.0`,
 * `docs/API.md` 12a.8).
 *
 * **The master is a request, never a stream setting.** `recording.master` on a stream
 * body is `422` since `v0.8.0`; a show's parts (one per producer reconnect) are joined
 * after the show by naming them here.
 *
 * **`request_id` is the idempotency key**, unique per stream, the PlanRequest
 * precedent: minted here as a ULID unless given, so mint the request ONCE, keep it
 * (`requestId()`), and send the same object on every retry. An exact retry answers
 * `200` with the generation as it is now; the same id with another body is `409
 * request_conflict`.
 *
 * `sources` are 1 to 64 `recording_ingest` generation ids of THIS stream, each once.
 * The node joins them in the order their recordings STARTED, whatever order they are
 * listed in. Whether each is usable (`422 invalid_source`), whether they can be joined
 * by a stream copy (`422 sources_incompatible`), and whether one is still recording
 * (`409 artifact_not_finalized`) are the node's.
 *
 * The target is one PlanTarget of kind `recording_master`
 * (`PlanTarget::recordingMaster()`): a `destination_ref` or a signed bucket WITHOUT
 * initial authorizations (the master's plan revision exists only once the remux
 * finishes: supply them by refresh when `transfer.authorization_required` names the
 * object), and `key`, the complete bucket-relative object key, used verbatim. The node
 * never builds a name.
 *
 * `reason` is optional and OMITTED when not set: an omitted reason and `""` hash
 * differently at the node.
 */
final readonly class MasterRequest
{
    public const MAX_SOURCES = 64;

    public const MAX_REASON_BYTES = 1024;

    /**
     * @param  list<string>  $sources
     */
    private function __construct(
        private string $requestId,
        private array $sources,
        private PlanTarget $target,
        private ?string $reason,
    ) {}

    /**
     * @param  array<array-key, string>  $sources  `recording_ingest` generation ids of
     *                                             this stream, re-indexed
     * @param  PlanTarget  $target  `PlanTarget::recordingMaster()`
     * @param  string|null  $requestId  a caller-minted ULID, minted here when null
     */
    public static function make(array $sources, PlanTarget $target, ?string $requestId = null): self
    {
        if ($sources === []) {
            throw InvalidConfigurationException::invalid('sources', 'one or more recording_ingest generation ids');
        }

        if (count($sources) > self::MAX_SOURCES) {
            throw InvalidConfigurationException::invalid('sources', 'at most '.self::MAX_SOURCES.' sources');
        }

        if ($target->artifact() !== ArtifactStatus::KIND_RECORDING_MASTER) {
            throw InvalidConfigurationException::invalid('target.artifact', 'must be recording_master');
        }

        if ($target->signed() !== null && $target->signed()->authorizations() !== []) {
            throw InvalidConfigurationException::invalid(
                'target.signed.authorizations',
                'not taken here: the master\'s plan revision exists only once the remux finishes; supply signed requests by refresh when transfer.authorization_required names the object'
            );
        }

        return new self($requestId ?? (string) Str::ulid(), array_values($sources), $target, null);
    }

    /**
     * Why, at most 1024 bytes. `""` is a reason, and a different request from one
     * with none.
     */
    public function withReason(string $reason): self
    {
        if (strlen($reason) > self::MAX_REASON_BYTES) {
            throw InvalidConfigurationException::invalid('reason', 'must be at most '.self::MAX_REASON_BYTES.' bytes');
        }

        return new self($this->requestId, $this->sources, $this->target, $reason);
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /**
     * As listed; the node joins them in recording start order.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    public function target(): PlanTarget
    {
        return $this->target;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * The request body. A signed target carries no credential here: initial
     * authorizations are refused at make().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'request_id' => $this->requestId,
            'sources' => $this->sources,
            'target' => $this->target->toArray(),
        ];

        if ($this->reason !== null) {
            $body['reason'] = $this->reason;
        }

        return $body;
    }
}
