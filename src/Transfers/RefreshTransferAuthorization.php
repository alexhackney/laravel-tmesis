<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Transfers;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\PendingNode;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Resources\JobArtifacts;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Answers `transfer.authorization_required` with the bound TransferSigner.
 *
 * Registered by the service provider ONLY when a signer is configured
 * (`tmesis.transfers.signer`) or bound in the container; with neither, nothing
 * listens and the object waits. Queued: the queue payload is the event, which carries
 * no secret, and the signing happens on the worker, so a signed URL is never
 * serialised into a job.
 *
 * One run: read the need (TransferAuthorizationNeed::resolve(); null ends it), ask the
 * signer (null or an empty answer ends it), then supply the answer as one refresh per
 * 128 parts. Each refresh is the object's authorization revision plus 1, taken from
 * the status the previous refresh answered, because the node MERGES every accepted
 * revision into the object's in-memory bundle (`docs/API.md` 12a.4): a later chunk
 * adds its parts to the earlier ones, and nothing is revoked.
 *
 * A `409` on a refresh ends the run with a warning and is NOT rethrown. Every 409 the
 * route answers means the object moved under this run: another refresh took the
 * revision (`request_conflict`, or `transfer_identity_mismatch` when a newer one
 * landed first), or the plan was replaced (`transfer_plan_stale`,
 * `transfer_identity_mismatch` on the plan revision). A retry would read the new state
 * and sign it, which is what the newer event is already doing. Anything else (a `422`
 * for a request the node will not bind, a `5xx`, a connection failure) is rethrown and
 * the job retries from the read.
 *
 * Nothing logged here carries a URL or a header value: the object, the revision and
 * the supplied operation NAMES only.
 */
class RefreshTransferAuthorization implements ShouldQueue
{
    public function __construct(private readonly Container $container) {}

    /**
     * Whether a signer is configured or bound, which is what registers this listener.
     */
    public static function signerConfigured(Container $container): bool
    {
        return $container->bound(TransferSigner::class) || ! blank(config('tmesis.transfers.signer'));
    }

    public function handle(TransferAuthorizationRequired $event): void
    {
        $node = $this->node($event);
        $name = $node->config()->name;
        // A job generation's routes are the job's (tmesis v0.6.0).
        $artifacts = $event->identity()->isJobGeneration() ? $node->jobArtifacts() : $node->artifacts();

        $need = TransferAuthorizationNeed::resolve($artifacts, $event, $name);

        if ($need === null) {
            return;
        }

        $answer = $this->signer()->sign($need);

        if ($answer === null || $answer->isEmpty()) {
            Log::debug('Tmesis transfer signer left the object waiting.', $this->context($need));

            return;
        }

        $object = $need->object();

        foreach ($answer->bundles() as $bundle) {
            $refresh = AuthorizationRequest::nextFor($object, $need->inventorySha256(), $bundle);

            try {
                $object = $artifacts instanceof JobArtifacts
                    ? $artifacts->supplyJobTransferAuthorization((string) $need->jobId(), $need->generation(), $need->objectId(), $refresh)
                    : $artifacts->supplyTransferAuthorization((string) $need->stream(), $need->generation(), $need->objectId(), $refresh);
            } catch (ConflictException $e) {
                Log::warning('Tmesis transfer authorization refresh was refused: the object moved on.', $this->context($need) + [
                    'authorization_revision' => $refresh->authorizationRevision(),
                    'supplied' => $bundle->supplied(),
                    'code' => $e->code(),
                    'request_id' => $e->requestId(),
                ]);

                return;
            }
        }
    }

    /**
     * The queue the job goes on; null is the connection's default.
     */
    public function viaQueue(): ?string
    {
        $queue = config('tmesis.transfers.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    /**
     * The node the event came from: the verified `X-Tmesis-Node` of the delivery,
     * then the payload's `node`, each only when configured or supplied by the node
     * resolver, then the default node.
     *
     * A resolver that fails throws here and the job retries from the start.
     */
    protected function node(TransferAuthorizationRequired $event): PendingNode
    {
        $client = $this->container->make(TmesisClientInterface::class);
        $registry = $this->container->make(NodeRegistry::class);

        foreach ([$event->delivery()?->node, $event->node()] as $name) {
            if (is_string($name) && $name !== '' && $registry->has($name)) {
                return $client->node($name);
            }
        }

        return $client->node();
    }

    /**
     * The bound signer, else the configured class.
     */
    protected function signer(): TransferSigner
    {
        $signer = null;

        if ($this->container->bound(TransferSigner::class)) {
            $signer = $this->container->make(TransferSigner::class);
        } else {
            $class = config('tmesis.transfers.signer');

            if (is_string($class) && $class !== '' && class_exists($class)) {
                $signer = $this->container->make($class);
            }
        }

        if (! $signer instanceof TransferSigner) {
            throw InvalidConfigurationException::invalid(
                'tmesis.transfers.signer',
                'it must name a class implementing '.TransferSigner::class
            );
        }

        return $signer;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(TransferAuthorizationNeed $need): array
    {
        return [
            'node' => $need->node(),
            'stream' => $need->stream(),
            'job' => $need->jobId(),
            'generation' => $need->generation(),
            'plan_revision' => $need->planRevision(),
            'target' => $need->targetId(),
            'object' => $need->objectId(),
            'required' => $need->requiredOperations(),
            'reason' => $need->reason(),
        ];
    }
}
