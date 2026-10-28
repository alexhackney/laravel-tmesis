<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The body of `setTranscodePause`: claim no new transcode job until a time, with a
 * reason. The openapi component JobPauseRequest (tmesis `v0.9.0`, handover 3.5,
 * `docs/API.md` 9.12a).
 *
 * `until` is a REQUIRED key and nullable: null holds the pause until
 * clearTranscodePause(). A time already past is accepted, stored and simply
 * inactive (the PUT answers `{"paused": false}`). `reason` is 1 to 200 BYTES, not
 * characters: a multibyte reason reaches the cap sooner. There are no other keys
 * (the node refuses an unknown field), so the body is always exactly the two.
 *
 * Only the grammar is checked here, with the node's sentences: the same checks, in the
 * same argument order, as the fleet's `Fleet\DesiredJobsPause`, except that `until`
 * here takes any RFC 3339 offset (the openapi component), where the fleet `$def` says
 * UTC. The PUT REPLACES any
 * pause the box holds in place (no second `jobs.paused`). On an ENROLLED box the
 * route is `409 fleet_owned`: the desired state's `jobs_pause` owns the pause there.
 */
final readonly class JobPauseRequest
{
    /** The node's cap on `reason` (`fleet.MaxJobsPauseReason`), in bytes. */
    public const MAX_REASON_BYTES = 200;

    private function __construct(
        private ?string $until,
        private string $reason,
    ) {}

    /**
     * @param  string  $reason  why, 1 to 200 bytes of UTF-8 (`show: fight night 12`)
     * @param  DateTimeInterface|string|null  $until  when the pause ends by itself; null
     *                                                is until cleared. A DateTimeInterface is sent as RFC 3339 UTC,
     *                                                to the second unless it carries a fraction
     */
    public static function make(string $reason, DateTimeInterface|string|null $until = null): self
    {
        $bytes = strlen($reason);

        if ($bytes < 1 || $bytes > self::MAX_REASON_BYTES) {
            throw InvalidConfigurationException::invalid('reason', 'must be 1 to '.self::MAX_REASON_BYTES." bytes, got {$bytes}");
        }

        if (! mb_check_encoding($reason, 'UTF-8')) {
            throw InvalidConfigurationException::invalid('reason', 'must be valid UTF-8');
        }

        return new self(self::normalizeUntil($until), $reason);
    }

    /**
     * A pause with no end: it holds until clearTranscodePause().
     */
    public static function untilCleared(string $reason): self
    {
        return self::make($reason);
    }

    /**
     * The `until` string as sent, or null for until cleared.
     */
    public function until(): ?string
    {
        return $this->until;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The request body: `until` (null included) and `reason`, always both.
     *
     * @return array{until: ?string, reason: string}
     */
    public function toArray(): array
    {
        return ['until' => $this->until, 'reason' => $this->reason];
    }

    /**
     * What Go's `time.Parse(time.RFC3339, ...)` accepts: a `T`, seconds, an optional
     * fraction and a `Z` or numeric offset, every field in range (a calendar date,
     * hour 0 to 23, minute and second 0 to 59, offset under a day). The node stores it
     * in UTC and echoes it to the second.
     */
    private static function normalizeUntil(DateTimeInterface|string|null $until): ?string
    {
        if ($until === null) {
            return null;
        }

        if ($until instanceof DateTimeInterface) {
            $utc = DateTimeImmutable::createFromInterface($until)->setTimezone(new DateTimeZone('UTC'));

            return $utc->format('u') === '000000' ? $utc->format('Y-m-d\TH:i:s\Z') : $utc->format('Y-m-d\TH:i:s.u\Z');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})\z/', $until, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59
            || ($m[8] !== 'Z' && ((int) substr($m[8], 1, 2) > 23 || (int) substr($m[8], 4, 2) > 59))) {
            throw InvalidConfigurationException::invalid('until', 'must be an RFC 3339 time (2026-08-28T19:00:00Z), or null');
        }

        return $until;
    }
}
