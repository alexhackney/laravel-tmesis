<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Events;

use AlexHackney\Tmesis\Data\Jobs\JobSourceReport;

/**
 * A job is queued, and why NOW (tmesis `v0.6.0`, WEBHOOKS.md 8.17): submitted the
 * first time, evicted by a live publish, requeued by the boot sweep, retried after a
 * transient stage failure, or put back because the box could not fit its disk
 * reservation.
 *
 * Only `attempts()` is judged against `jobs.max_attempts`; `evictions()` never fails a
 * job, and `disk_insufficient` moves neither counter.
 */
class JobQueued extends JobEvent
{
    public const EVENT = 'job.queued';

    public const REASON_SUBMITTED = 'submitted';

    public const REASON_LIVE_PREEMPTED = 'live_preempted';

    public const REASON_NODE_RESTARTED = 'node_restarted';

    public const REASON_TRANSIENT_RETRY = 'transient_retry';

    public const REASON_DISK_INSUFFICIENT = 'disk_insufficient';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_SUBMITTED,
        self::REASON_LIVE_PREEMPTED,
        self::REASON_NODE_RESTARTED,
        self::REASON_TRANSIENT_RETRY,
        self::REASON_DISK_INSUFFICIENT,
    ];

    /**
     * One of REASONS. A string, not an enum: the set is open to a newer node.
     */
    public function reason(): string
    {
        return $this->string('reason');
    }

    /**
     * True for the first queueing of a submission, the one to correlate with
     * `createTranscodeJob`.
     */
    public function isSubmission(): bool
    {
        return $this->reason() === self::REASON_SUBMITTED;
    }

    /**
     * `low`, `normal` or `high`.
     */
    public function priority(): string
    {
        return $this->string('priority');
    }

    /**
     * The caller-minted key the job was submitted under; null when it had none.
     */
    public function idempotencyKey(): ?string
    {
        return $this->nullableString('idempotency_key');
    }

    /**
     * The requested output kinds, in the order submitted.
     *
     * @return list<string>
     */
    public function outputs(): array
    {
        $value = $this->payload['outputs'] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    public function source(): JobSourceReport
    {
        return $this->sourceReport();
    }

    public function attempts(): int
    {
        return $this->int('attempts');
    }

    public function evictions(): int
    {
        return $this->int('evictions');
    }
}
