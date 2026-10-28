<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Client;

/**
 * One decoded response, with the status that carried it.
 *
 * The status is kept because several writes in this contract say something in it that
 * is not in the body: `createStream` answers 201 for a stream it provisioned and 200
 * for one that already resolved identically, `deleteStream` answers 202 for the call
 * that STARTED the seal and 200 for a retry of one already under way, and since
 * `v0.5.0` `replaceTransferPlan` answers 201 for revision 1, and `releaseArtifact` /
 * `overrideArtifact` 202 for the decision that was recorded and 200 for an exact
 * duplicate. A resource that only read the body would report a retry as a fresh write.
 */
final readonly class TransportResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}
}
