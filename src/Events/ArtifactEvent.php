<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Webhooks\ArtifactIdentity;

/**
 * The shared base of the six `artifact.*` and `transfer.*` events (tmesis `v0.5.0`,
 * WEBHOOKS.md 8.28).
 *
 * Every one carries the `ArtifactIdentity` block flat beside its own fields, and every
 * one is enqueued in the SAME journal transaction as the state change it reports, so
 * unlike the publish events there is no window where the state moved and the event was
 * never written. Delivery is still at-least-once and unordered: dedupe and order by
 * `occurred_at` as for every other event.
 *
 * None of the six ever carries a secret, a signed URL, or a raw provider response.
 *
 * **A job generation's events carry no stream (tmesis `v0.6.0`).** `stream()` is null
 * and jobId() names the transcode job; they are delivered to the job's `callback_url`.
 * Read, re-plan and release a job generation through `jobArtifacts()`, keyed by the
 * job, never through `artifacts()`.
 */
abstract class ArtifactEvent extends TmesisWebhookReceived
{
    public function identity(): ArtifactIdentity
    {
        return ArtifactIdentity::fromArray($this->payload);
    }

    /**
     * The transcode job a job generation belongs to; null for a stream's. The same
     * value as identity()->jobId().
     */
    public function jobId(): ?string
    {
        return $this->nullableString('job_id');
    }

    /**
     * The generation this event is about; the same value as identity()->generation().
     */
    public function generation(): string
    {
        return $this->string('generation');
    }

    /**
     * @return list<string>
     */
    protected function stringList(string $key): array
    {
        $value = $this->payload[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
