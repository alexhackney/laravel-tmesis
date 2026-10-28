<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Events\ArtifactDeleted;
use AlexHackney\Tmesis\Events\ArtifactFinalized;
use AlexHackney\Tmesis\Events\ArtifactReleased;
use AlexHackney\Tmesis\Events\CaptionSessionEnded;
use AlexHackney\Tmesis\Events\CaptionSessionStarted;
use AlexHackney\Tmesis\Events\JobCompleted;
use AlexHackney\Tmesis\Events\JobFailed;
use AlexHackney\Tmesis\Events\JobProgress;
use AlexHackney\Tmesis\Events\JobQueued;
use AlexHackney\Tmesis\Events\JobsPaused;
use AlexHackney\Tmesis\Events\JobsResumed;
use AlexHackney\Tmesis\Events\JobStarted;
use AlexHackney\Tmesis\Events\LeaseFenced;
use AlexHackney\Tmesis\Events\MarkerPlaced;
use AlexHackney\Tmesis\Events\NodeCapacityChanged;
use AlexHackney\Tmesis\Events\NodeDiskFloorNear;
use AlexHackney\Tmesis\Events\NodeDraining;
use AlexHackney\Tmesis\Events\NodeOnline;
use AlexHackney\Tmesis\Events\RecordingCompleted;
use AlexHackney\Tmesis\Events\RecordingDegraded;
use AlexHackney\Tmesis\Events\RecordingFailed;
use AlexHackney\Tmesis\Events\RecordingRegistered;
use AlexHackney\Tmesis\Events\RecordingStarted;
use AlexHackney\Tmesis\Events\RelayDegraded;
use AlexHackney\Tmesis\Events\RelayFailed;
use AlexHackney\Tmesis\Events\RelayReconnected;
use AlexHackney\Tmesis\Events\RelayStarted;
use AlexHackney\Tmesis\Events\RelayStopped;
use AlexHackney\Tmesis\Events\StreamInputLost;
use AlexHackney\Tmesis\Events\StreamInputReturned;
use AlexHackney\Tmesis\Events\StreamLive;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Events\StreamPublishRejected;
use AlexHackney\Tmesis\Events\StreamSealed;
use AlexHackney\Tmesis\Events\StreamUnpublished;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Events\TransferCompleted;
use AlexHackney\Tmesis\Events\TransferFailed;

/**
 * Event name to typed class.
 *
 * The 38 names here are exactly the 38 event schema files at tmesis `v0.9.0` (17 at
 * rc2; `stream.input_lost` and `stream.input_returned` were pulled forward from M4
 * with the hold, tmesis final plan 11.30; `caption.session_started` and
 * `caption.session_ended` arrived with live captions at `v0.3.0`, 11.44;
 * `lease.fenced` with the node-side lease at `v0.4.0`, 11.49; the three `artifact.*`
 * and three `transfer.*` events with the destination chain at `v0.5.0`; the five
 * `job.*` events and `marker.placed` at `v0.6.0`; `node.capacity_changed`,
 * `node.disk_floor_near`, `jobs.paused` and `jobs.resumed` at `v0.9.0`, all four
 * node-scoped). The catalog is frozen inside v1 but additive, so an unknown name is expected traffic
 * from a newer node: it maps to the base class and is dispatched like anything else.
 * Nothing here throws.
 *
 * A name the contract documents but ships no schema for (`lease.granted`) is a later
 * milestone. It arrives as the base class today and becomes typed when its schema
 * ships, which is an additive change here too (`node.capacity_changed` did exactly
 * that at `v0.9.0`).
 *
 * Exactly one object is dispatched per delivery: the class mapped here. Laravel
 * resolves listeners by that exact class plus `class_implements()`, never by parent
 * class, so a listener on TmesisWebhookReceived fires only for unknown names. A
 * listener that must see every delivery listens on WebhookEvent, which the base
 * class, and so every class below, implements.
 */
final class EventMap
{
    /**
     * @var array<string, class-string<TmesisWebhookReceived>>
     */
    public const CLASSES = [
        'artifact.deleted' => ArtifactDeleted::class,
        'artifact.finalized' => ArtifactFinalized::class,
        'artifact.released' => ArtifactReleased::class,
        'caption.session_ended' => CaptionSessionEnded::class,
        'caption.session_started' => CaptionSessionStarted::class,
        'job.completed' => JobCompleted::class,
        'job.failed' => JobFailed::class,
        'job.progress' => JobProgress::class,
        'job.queued' => JobQueued::class,
        'job.started' => JobStarted::class,
        'jobs.paused' => JobsPaused::class,
        'jobs.resumed' => JobsResumed::class,
        'lease.fenced' => LeaseFenced::class,
        'marker.placed' => MarkerPlaced::class,
        'node.capacity_changed' => NodeCapacityChanged::class,
        'node.disk_floor_near' => NodeDiskFloorNear::class,
        'node.draining' => NodeDraining::class,
        'node.online' => NodeOnline::class,
        'recording.completed' => RecordingCompleted::class,
        'recording.degraded' => RecordingDegraded::class,
        'recording.failed' => RecordingFailed::class,
        'recording.registered' => RecordingRegistered::class,
        'recording.started' => RecordingStarted::class,
        'relay.degraded' => RelayDegraded::class,
        'relay.failed' => RelayFailed::class,
        'relay.reconnected' => RelayReconnected::class,
        'relay.started' => RelayStarted::class,
        'relay.stopped' => RelayStopped::class,
        'stream.input_lost' => StreamInputLost::class,
        'stream.input_returned' => StreamInputReturned::class,
        'stream.live' => StreamLive::class,
        'stream.publish_rejected' => StreamPublishRejected::class,
        'stream.published' => StreamPublished::class,
        'stream.sealed' => StreamSealed::class,
        'stream.unpublished' => StreamUnpublished::class,
        'transfer.authorization_required' => TransferAuthorizationRequired::class,
        'transfer.completed' => TransferCompleted::class,
        'transfer.failed' => TransferFailed::class,
    ];

    /**
     * @return class-string<TmesisWebhookReceived>
     */
    public static function classFor(string $event): string
    {
        return self::CLASSES[$event] ?? TmesisWebhookReceived::class;
    }

    /**
     * Build the event for a payload.
     *
     * The body's own `event` decides, with the `X-Tmesis-Event` header as a fallback
     * for a body that decoded without one. The header is not covered by the
     * signature, so it is never preferred over the body.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function make(array $payload, ?ReceivedDelivery $delivery = null): TmesisWebhookReceived
    {
        $name = $payload['event'] ?? null;

        if (! is_string($name) || $name === '') {
            $name = $delivery instanceof ReceivedDelivery ? $delivery->event : '';
        }

        $class = self::classFor($name);

        return $class::fromArray($payload, $delivery);
    }

    /**
     * Every event name this build has a typed class for, sorted.
     *
     * @return list<string>
     */
    public static function events(): array
    {
        $names = array_keys(self::CLASSES);
        sort($names);

        return $names;
    }
}
