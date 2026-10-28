<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dedupe on `tmesis_webhook_deliveries`. The default, and durable.
 *
 * Durable by default because the node is at-least-once and retries: a dedupe store
 * that forgets on restart re-dispatches every delivery the node retries after the
 * restart, so every listener runs twice for those, and `recording.registered` is
 * the delivery where a double dispatch costs most (an asset attached twice). The
 * 204 must not leave before the row is committed.
 *
 * The unique index on `delivery` is the dedupe. Insert and catch, rather than check
 * then insert: only the constraint is atomic under concurrency.
 *
 * A row is also the only copy of a delivery whose dispatch failed: the node has its
 * 204 and never retries, so the row stays unprocessed with the failure recorded
 * until `tmesis:webhooks:retry` dispatches it. `leased_until` keeps that to one
 * dispatcher at a time: the claim takes the lease for the original request, and a
 * retry takes it with one conditional UPDATE.
 */
class DatabaseDeliveryDeduper implements DeliveryDeduper
{
    public const TABLE = 'tmesis_webhook_deliveries';

    /**
     * How long a dispatch lease holds, in seconds.
     *
     * Long enough for synchronous listeners to finish; a dispatch that outlives it
     * can be taken by a retry. A row whose request died before dispatching is due
     * once it lapses.
     */
    public const LEASE_SECONDS = 600;

    /** How much of an exception message `last_error` keeps. */
    public const ERROR_LENGTH = 1000;

    /**
     * Rows one prune statement deletes. A first prune can face months of backlog, and
     * one unbounded DELETE would hold locks on the table the receiver inserts into.
     */
    public const PRUNE_CHUNK = 1000;

    /**
     * @param  int  $pruneChunk  rows per prune DELETE; PRUNE_CHUNK unless a test lowers it
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $retentionHours,
        private readonly int $pruneChunk = self::PRUNE_CHUNK,
    ) {
        if ($retentionHours < 48) {
            throw InvalidConfigurationException::invalid(
                'tmesis.webhooks.dedupe.retention_hours',
                'it must be at least 48, because the node retries a delivery for 24 hours'
            );
        }
    }

    public function claim(string $deliveryId, ReceivedDelivery $delivery, ?array $payload = null): bool
    {
        $record = DeliveryRecord::fromDelivery($deliveryId, $delivery, $payload);

        try {
            $this->table()->insert($record->toDatabaseRow() + [
                'leased_until' => now()->addSeconds(self::LEASE_SECONDS),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function forget(string $deliveryId): void
    {
        $this->table()->where('delivery', $deliveryId)->delete();
    }

    public function markProcessed(string $deliveryId): void
    {
        $this->table()->where('delivery', $deliveryId)->update([
            'processed_at' => now(),
            'leased_until' => null,
        ]);
    }

    public function recordFailure(string $deliveryId, Throwable $error): void
    {
        $this->table()->where('delivery', $deliveryId)->update([
            'failed_attempts' => $this->connection->raw('failed_attempts + 1'),
            'last_error' => Str::limit($error::class.': '.$error->getMessage(), self::ERROR_LENGTH),
            'failed_at' => now(),
            'leased_until' => null,
        ]);
    }

    public function lease(string $deliveryId): bool
    {
        $now = now();

        // One statement: the database re-checks the WHERE on the row it locks, so of
        // two concurrent callers exactly one sees an affected row.
        return $this->table()
            ->where('delivery', $deliveryId)
            ->whereNull('processed_at')
            ->where(fn (Builder $query) => $query->whereNull('leased_until')->orWhere('leased_until', '<', $now))
            ->update(['leased_until' => $now->copy()->addSeconds(self::LEASE_SECONDS)]) === 1;
    }

    public function unprocessed(int $olderThanSeconds, int $limit, ?array $ids = null, ?string $node = null): array
    {
        $now = now();

        $query = $this->table()
            ->whereNull('processed_at')
            ->where('stored_at', '<=', self::utcBefore($olderThanSeconds))
            ->where(fn (Builder $query) => $query->whereNull('leased_until')->orWhere('leased_until', '<', $now))
            ->orderBy('stored_at')
            ->orderBy('id')
            ->limit($limit);

        if ($ids !== null) {
            $query->whereIn('delivery', $ids);
        }

        if ($node !== null) {
            $query->where('node', $node);
        }

        return array_values($query->get()->map(fn (object $row): StoredDelivery => $this->toStored($row))->all());
    }

    public function supportsRetry(): bool
    {
        return true;
    }

    /**
     * Deletes processed rows stored before the retention window. The window is the
     * one this store was built with, so with the fleet on it is already at least
     * `fleet.tombstone_days` (FleetConfig::dedupeRetentionHours()). Nothing calls this
     * but `tmesis:webhooks:prune`, which an app schedules.
     */
    public function prune(): int
    {
        $deleted = 0;

        // Bounded statements, keys first: each DELETE locks at most one chunk of rows,
        // so the receiver's inserts are never queued behind a whole backlog. The
        // DELETE keeps the retention filter, so a key read here can only ever name a
        // row that is still processed and past the window.
        do {
            $ids = $this->pastRetention()->orderBy('id')->limit($this->pruneChunk)->pluck('id')->all();

            if ($ids !== []) {
                $deleted += $this->pastRetention()->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $this->pruneChunk);

        return $deleted;
    }

    /**
     * How many processed rows `prune()` would delete now.
     */
    public function prunableCount(): int
    {
        return $this->pastRetention()->count();
    }

    /**
     * When the oldest processed row past retention was stored; null when there is none.
     */
    public function oldestPrunable(): ?DateTimeImmutable
    {
        $storedAt = $this->pastRetention()->min('stored_at');

        return is_string($storedAt) && $storedAt !== '' ? new DateTimeImmutable($storedAt, new DateTimeZone('UTC')) : null;
    }

    /**
     * The retention window, in hours, this store keeps a processed row for.
     */
    public function retentionHours(): int
    {
        return $this->retentionHours;
    }

    public function unprocessedOlderThan(int $seconds): int
    {
        return $this->table()
            ->whereNull('processed_at')
            ->where('stored_at', '<', self::utcBefore($seconds))
            ->count();
    }

    public function isDurable(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'database driver on ['.self::TABLE.'], retention '.$this->retentionHours.'h (durable)';
    }

    /**
     * Processed rows stored before the retention window. An unprocessed row is never
     * in it, whatever its age.
     */
    private function pastRetention(): Builder
    {
        return $this->table()
            ->whereNotNull('processed_at')
            ->where('stored_at', '<', self::utcBefore($this->retentionHours * 3600));
    }

    /**
     * `stored_at` is the delivery's receipt as a UTC wall-clock string
     * (DeliveryRecord, from the request's Unix time), so every bound against it is a
     * UTC string in the same format: now() would be the APP timezone, and a non-UTC
     * app would prune, count and retry at the wrong boundary.
     */
    private static function utcBefore(int $seconds): string
    {
        return now('UTC')->subSeconds($seconds)->format('Y-m-d H:i:s');
    }

    private function toStored(object $row): StoredDelivery
    {
        /** @var array<string, mixed> $values */
        $values = (array) $row;

        $headers = json_decode(is_string($values['headers'] ?? null) ? $values['headers'] : '{}', true);

        return new StoredDelivery(
            deliveryId: (string) $values['delivery'],
            node: (string) $values['node'],
            event: (string) $values['event'],
            attempt: is_numeric($values['attempt'] ?? null) ? (int) $values['attempt'] : null,
            headers: is_array($headers)
                ? array_filter($headers, fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH)
                : [],
            body: (string) $values['body'],
            storedAt: new DateTimeImmutable((string) $values['stored_at'], new DateTimeZone('UTC')),
            failedAttempts: (int) ($values['failed_attempts'] ?? 0),
            lastError: is_string($values['last_error'] ?? null) ? $values['last_error'] : null,
            failedAt: is_string($values['failed_at'] ?? null) ? new DateTimeImmutable($values['failed_at']) : null,
        );
    }

    private function table(): Builder
    {
        return $this->connection->table(self::TABLE);
    }
}
