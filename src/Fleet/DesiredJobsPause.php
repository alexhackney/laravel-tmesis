<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The told jobs pause in a desired state (`$defs.DesiredJobsPause`, `v0.9.0`, handover
 * 3.5; docs/API.md 15.3): the node claims no new transcode job until `until`, running
 * jobs finish and queued jobs wait. A submission is still accepted and queued.
 *
 * A FULL replacement like every desired-state field: a document without a pause CLEARS
 * the fleet's pause (and an API pause the box held when it joined), so the provider
 * hands this to EVERY document for as long as the pause should hold.
 *
 * Both fields are checked here because a bad value rejects the WHOLE revision at the
 * node, streams included (`desired_revision_rejected` at `jobs_pause.until` or
 * `jobs_pause.reason`): `until` is RFC 3339 UTC or null (until a revision clears it; a
 * time already past is accepted and simply inactive), `reason` 1 to 200 BYTES.
 */
final readonly class DesiredJobsPause
{
    /** The reason's bound in BYTES, the node's `MaxJobsPauseReason`. */
    public const MAX_REASON_BYTES = 200;

    private function __construct(
        public ?string $until,
        public string $reason,
    ) {}

    /**
     * @param  string  $reason  1 to 200 bytes, shown verbatim in health, doctor, the heartbeat and `jobs.paused`
     * @param  DateTimeInterface|string|null  $until  a time (converted to UTC), an RFC 3339 UTC string, or null for "until cleared"
     */
    public static function make(string $reason, DateTimeInterface|string|null $until = null): self
    {
        $bytes = strlen($reason);

        if ($bytes < 1 || $bytes > self::MAX_REASON_BYTES) {
            throw InvalidConfigurationException::invalid('jobs_pause.reason', 'must be 1 to '.self::MAX_REASON_BYTES." bytes, got {$bytes}");
        }

        if (! mb_check_encoding($reason, 'UTF-8')) {
            throw InvalidConfigurationException::invalid('jobs_pause.reason', 'must be valid UTF-8');
        }

        return new self(self::until($until), $reason);
    }

    /**
     * @return array{until: string|null, reason: string}
     */
    public function toWire(): array
    {
        return [
            'until' => $this->until,
            'reason' => $this->reason,
        ];
    }

    private static function until(DateTimeInterface|string|null $until): ?string
    {
        if ($until === null) {
            return null;
        }

        if ($until instanceof DateTimeInterface) {
            $utc = DateTimeImmutable::createFromInterface($until)->setTimezone(new DateTimeZone('UTC'));

            return $utc->format('u') === '000000' ? $utc->format('Y-m-d\TH:i:s\Z') : $utc->format('Y-m-d\TH:i:s.u\Z');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,9})?Z\z/', $until, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            throw InvalidConfigurationException::invalid('jobs_pause.until', 'must be an RFC 3339 UTC time ending in Z (2026-08-28T19:00:00Z), or null');
        }

        return $until;
    }
}
