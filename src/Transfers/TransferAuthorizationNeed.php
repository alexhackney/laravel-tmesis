<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Transfers;

use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\PartStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTargetStatus;
use AlexHackney\Tmesis\Data\Webhooks\ArtifactIdentity;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\JobArtifacts;
use Illuminate\Support\Facades\Log;

/**
 * Everything a TransferSigner needs to presign one object's next step: the
 * `transfer.authorization_required` event PLUS what the node reports now.
 *
 * Not the openapi AuthorizationRequest (the refresh body the package builds from the
 * signer's answer): this is the question, that is the reply.
 *
 * The event says which object and why; the reads add what the event does not carry.
 * The plan's target gives the bucket, region, endpoint and addressing a presigner
 * signs against; the object gives its size, checksum, part layout, completion body
 * hash and authorization revision; the parts give the byte range and checksum of each
 * part still owed. requiredOperations() is the object's CURRENT set, which resolve()
 * holds equal to the event's.
 *
 * Carries no secret: nothing the node reports is one, and the answer is a separate
 * object.
 *
 * A JOB generation's need (tmesis `v0.6.0`: a transcode output told to a signed
 * target) has no stream: `stream()` is null, `jobId()` names the job, and every read
 * and the supply go to `jobArtifacts()`, never to the stream routes.
 */
final readonly class TransferAuthorizationNeed
{
    /**
     * @param  list<PartStatus>  $owedParts
     */
    private function __construct(
        private string $node,
        private TransferAuthorizationRequired $event,
        private PlanTargetStatus $target,
        private ObjectStatus $object,
        private array $owedParts,
    ) {}

    /**
     * @param  string  $node  the configured node name the reads went to
     * @param  array<array-key, PartStatus>  $parts  the object's parts; the complete
     *                                               ones are dropped
     */
    public static function make(
        string $node,
        TransferAuthorizationRequired $event,
        PlanTargetStatus $target,
        ObjectStatus $object,
        array $parts = [],
    ): self {
        $owed = array_values(array_filter(
            $parts,
            static fn (PartStatus $part): bool => $part->state() !== ObjectStatus::STATE_COMPLETE,
        ));

        usort($owed, static fn (PartStatus $a, PartStatus $b): int => $a->partNumber() <=> $b->partNumber());

        return new self($node, $event, $target, $object, $owed);
    }

    /**
     * Read what the event does not carry, or null when there is nothing to sign.
     *
     * Null, with a debug line naming why, when the event's plan revision is no longer
     * current, its target is not a signed one, the object is gone from the current
     * revision or no longer waiting, or it now waits on a different set of operations
     * (a newer need, which fires its own event). Reads: the plan, the transfer
     * listing up to the object, and the object's parts when `parts` is required.
     *
     * There is no single-object read in the contract, so the listing is walked a page
     * of 1000 at a time and stops at the object.
     *
     * `$artifacts` is the resource the generation's routes live on: Artifacts for a
     * stream generation, JobArtifacts for a job generation. The other is refused.
     */
    public static function resolve(Artifacts|JobArtifacts $artifacts, TransferAuthorizationRequired $event, string $node): ?self
    {
        $owner = self::owner($artifacts, $event);
        $generation = $event->generation();

        $plan = $artifacts instanceof JobArtifacts
            ? $artifacts->getJobTransferPlan($owner, $generation)
            : $artifacts->getTransferPlan($owner, $generation);

        if (! $plan->current() || $plan->revision() !== $event->planRevision()) {
            return self::skip($event, $node, 'the plan revision is no longer current');
        }

        $target = null;

        foreach ($plan->targets() as $candidate) {
            if ($candidate->id() === $event->targetId()) {
                $target = $candidate;
            }
        }

        if ($target === null || $target->authorization() !== PlanTargetStatus::AUTHORIZATION_SIGNED) {
            return self::skip($event, $node, 'the target is not a signed target of the current plan');
        }

        $object = self::findObject($artifacts, $owner, $event);

        if ($object === null || $object->planRevision() !== $event->planRevision()) {
            return self::skip($event, $node, 'the object is not in the current plan revision');
        }

        if ($object->state() !== ObjectStatus::STATE_WAITING_AUTHORIZATION || $object->authorizationRequired() === []) {
            return self::skip($event, $node, 'the object is no longer waiting on authorization');
        }

        if (! self::sameOperations($object->authorizationRequired(), $event->requiredOperations())) {
            return self::skip($event, $node, 'the object now waits on a newer need');
        }

        $parts = [];

        if (in_array(TransferAuthorizationRequired::OPERATION_PARTS, $object->authorizationRequired(), true)) {
            $parts = $artifacts instanceof JobArtifacts
                ? $artifacts->allJobTransferParts($owner, $generation, $object->id(), 1000)->all()
                : $artifacts->allTransferParts($owner, $generation, $object->id(), 1000)->all();
        }

        return self::make($node, $event, $target, $object, $parts);
    }

    /**
     * The configured node name the need was read from, and the supply goes to.
     */
    public function node(): string
    {
        return $this->node;
    }

    public function event(): TransferAuthorizationRequired
    {
        return $this->event;
    }

    public function identity(): ArtifactIdentity
    {
        return $this->event->identity();
    }

    /**
     * The stream of a stream generation; null for a job generation.
     */
    public function stream(): ?string
    {
        return $this->event->identity()->stream();
    }

    /**
     * The transcode job of a job generation; null for a stream generation.
     */
    public function jobId(): ?string
    {
        return $this->event->identity()->jobId();
    }

    public function isJobGeneration(): bool
    {
        return $this->event->identity()->isJobGeneration();
    }

    public function generation(): string
    {
        return $this->event->generation();
    }

    /**
     * The generation's committed inventory hash, which the refresh binds.
     */
    public function inventorySha256(): string
    {
        return $this->event->identity()->inventorySha256();
    }

    /**
     * The stream's (a job generation: the job's) labels, echoed by the node: a signer
     * may route on them.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return $this->event->identity()->labels();
    }

    public function planRevision(): int
    {
        return $this->object->planRevision();
    }

    public function targetId(): string
    {
        return $this->target->id();
    }

    /**
     * The signed target as the plan reports it: bucket, region, endpoint (empty is
     * AWS regional resolution), addressing and part size. Never a credential.
     */
    public function target(): PlanTargetStatus
    {
        return $this->target;
    }

    public function objectId(): string
    {
        return $this->object->id();
    }

    /**
     * The exact told key every signed request must name.
     */
    public function objectKey(): string
    {
        return $this->object->objectKey();
    }

    /**
     * The inventory path the object copies.
     */
    public function artifactPath(): string
    {
        return $this->object->artifactPath();
    }

    /**
     * The committed upload id that part, complete and abort requests bind; null
     * before initiation has produced one. Never predict it.
     */
    public function multipartUploadId(): ?string
    {
        return $this->object->multipartUploadId();
    }

    /**
     * The body size a `put` sends.
     */
    public function sizeBytes(): int
    {
        return $this->object->sizeBytes();
    }

    /**
     * SHA-256 of the whole object, lowercase hex.
     */
    public function checksumSha256(): string
    {
        return $this->object->checksumSha256();
    }

    public function partSizeBytes(): ?int
    {
        return $this->object->partSizeBytes();
    }

    /**
     * The frozen completion body hash, for a payload-bound `complete`; null until
     * every part is complete.
     */
    public function completionBodySha256(): ?string
    {
        return $this->object->completionBodySha256();
    }

    /**
     * What to sign: some of `put`, `initiate`, `parts`, `complete`, `abort`, `head`.
     *
     * @return list<string>
     */
    public function requiredOperations(): array
    {
        return $this->object->authorizationRequired();
    }

    public function requires(string $operation): bool
    {
        return in_array($operation, $this->requiredOperations(), true);
    }

    /**
     * A completion probe asks for a HEAD, and only a HEAD.
     */
    public function requiresHead(): bool
    {
        return $this->requires(TransferAuthorizationRequired::OPERATION_HEAD);
    }

    /**
     * One of TransferAuthorizationRequired::REASONS.
     */
    public function reason(): string
    {
        return $this->event->reason();
    }

    public function isCompletionProbe(): bool
    {
        return $this->event->isCompletionProbe();
    }

    /**
     * The parts still to upload, ascending, each with its part number, byte offset,
     * size and SHA-256. Empty unless `parts` is required.
     *
     * @return list<PartStatus>
     */
    public function owedParts(): array
    {
        return $this->owedParts;
    }

    /**
     * @return list<int>
     */
    public function owedPartNumbers(): array
    {
        return array_map(static fn (PartStatus $part): int => $part->partNumber(), $this->owedParts);
    }

    /**
     * The object as the node reported it when the need was read.
     */
    public function object(): ObjectStatus
    {
        return $this->object;
    }

    private static function findObject(Artifacts|JobArtifacts $artifacts, string $owner, TransferAuthorizationRequired $event): ?ObjectStatus
    {
        $objectId = $event->objectId();

        $objects = $artifacts instanceof JobArtifacts
            ? $artifacts->allJobTransfers($owner, $event->generation(), 1000)
            : $artifacts->allTransfers($owner, $event->generation(), 1000);

        return $objects->first(static fn (ObjectStatus $object): bool => $object->id() === $objectId);
    }

    /**
     * The generation's owner on the resource given: the job for JobArtifacts, the
     * stream for Artifacts. A job generation handed the stream routes (or the reverse)
     * is refused rather than read under an empty id.
     */
    private static function owner(Artifacts|JobArtifacts $artifacts, TransferAuthorizationRequired $event): string
    {
        $identity = $event->identity();

        if ($identity->isJobGeneration() !== $artifacts instanceof JobArtifacts) {
            throw InvalidConfigurationException::invalid(
                'artifacts',
                $identity->isJobGeneration()
                    ? 'a job generation is read through jobArtifacts(), not artifacts()'
                    : 'a stream generation is read through artifacts(), not jobArtifacts()'
            );
        }

        return (string) ($identity->isJobGeneration() ? $identity->jobId() : $identity->stream());
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function sameOperations(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    private static function skip(TransferAuthorizationRequired $event, string $node, string $why): null
    {
        Log::debug('Tmesis transfer authorization not signed: '.$why.'.', [
            'node' => $node,
            'stream' => $event->identity()->stream(),
            'job' => $event->identity()->jobId(),
            'generation' => $event->generation(),
            'plan_revision' => $event->planRevision(),
            'object' => $event->objectId(),
        ]);

        return null;
    }
}
