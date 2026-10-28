<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Exceptions;

/**
 * `503 encoder_unavailable` on a job submit (tmesis `v0.9.0`, handover 3.4 and 3.7,
 * `docs/API.md` 9.10): the box's pinned jobs encoder (`jobs.video_encoder`) failed at
 * boot, and a failed pin is never replaced by a fallback.
 *
 * **Not retryable on this box.** Unlike `draining` it does not clear by waiting: it
 * holds until the pin is repaired (or set to `auto`) and tmesis restarts, and the
 * node sends no `Retry-After`. A scheduler sends the job to ANOTHER node. Queued jobs
 * stay queued and are never claimed; publishes are unaffected. Health's
 * `degraded_reasons` carries `jobs_encoder_unavailable` and the jobs engine block
 * reads `usable: false`, so a scheduler can stop offering the box jobs before it
 * asks.
 *
 * It extends UnavailableException, so an existing `catch (UnavailableException)`
 * still catches it; catch this class FIRST to tell it from a retryable 503.
 */
class EncoderUnavailableException extends UnavailableException
{
    /**
     * Always false: the condition lasts until the box is repaired and restarted.
     */
    public function retryableHere(): bool
    {
        return false;
    }
}
