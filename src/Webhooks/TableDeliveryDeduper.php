<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Dedupe on a cache store, with the replay log in a table the app already owns.
 *
 * For a consumer that keeps every inbound webhook in one shared table (a `source`
 * column, a JSON `payload`, `processed_at`) and wants no second one. The two jobs are
 * split:
 *
 *  - The DEDUPE is an atomic `add()` of `tmesis:delivery:{id}` on the configured cache
 *    store, for the retention window. A shared table with no unique key cannot be the
 *    dedupe, and a JSON scan of a large one is no place for it either.
 *  - The table is the durable REPLAY LOG. The claim inserts one row (source, the raw
 *    signed body as the payload, timestamps), and keeps the new row's primary key in
 *    the cache under the same key, so every later update is by primary key. When the
 *    key has expired or was lost, the fallback is a query on `source` and
 *    `payload->delivery`, which is slow without an index and exists only so a row is
 *    never orphaned.
 *  - Optionally, a DELIVERY column (`columns.delivery`) the claim fills with the id,
 *    under a unique index the app adds. Without it the cache is the ONLY dedupe, so a
 *    key lost inside the window (a `cache:clear` on deploy, an eviction) lets a second
 *    sight of the delivery dispatch again. With it the insert is the backstop: a
 *    unique violation on that column is a duplicate, never a second row. No lookup
 *    runs on a cache miss, because every first sight is one.
 *
 * The table has no lease column, so the dispatch lease is an atomic cache lock
 * (`tmesis:delivery-lease:{id}`, LEASE_SECONDS), taken by the claim for the original
 * request and by lease() for a retry, and released by whichever settles the row in
 * the same process. A retried success sets `reprocessed_at` beside `processed_at`
 * when that column is configured. A failure has nowhere to be written: the
 * dispatcher logs it, the row keeps a null `processed_at` and gets a fresh
 * `updated_at`.
 *
 * Every read and write is scoped to the configured source, so other sources' rows are
 * never listed, counted or touched. Only the mapped columns are ever written (never
 * a `reprocessing_queued` or a `deleted_at`), and no row is ever deleted: the app
 * owns that table's retention and its own reprocessing, which must skip this source's
 * rows (their payloads are Tmesis deliveries it does not understand).
 */
class TableDeliveryDeduper implements DeliveryDeduper
{
    /** How long a dispatch lease holds, in seconds. */
    public const LEASE_SECONDS = 600;

    /**
     * Cache drivers whose `add()` is atomic across processes and that outlive a
     * restart of the app.
     *
     * @var list<string>
     */
    public const DURABLE_CACHE_DRIVERS = ['redis', 'database', 'memcached', 'dynamodb'];

    /**
     * The column map's keys that must name a column. OPTIONAL_COLUMNS may be null.
     *
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = ['id', 'source', 'payload', 'processed_at', 'created_at', 'updated_at'];

    /**
     * The column map's optional keys, each null when the table has no such column.
     *
     * @var list<string>
     */
    public const OPTIONAL_COLUMNS = ['reprocessed_at', 'delivery'];

    /** The cache value between the claim and the insert, before there is a row id. */
    private const CLAIMED = 'claimed';

    /**
     * @var array{id: string, source: string, payload: string, processed_at: string, reprocessed_at: string|null, created_at: string, updated_at: string, delivery: string|null}
     */
    private readonly array $columns;

    /**
     * Leases this process holds, by delivery id. `retry` marks one taken by lease()
     * rather than by the claim.
     *
     * @var array<string, array{lock: Lock, retry: bool}>
     */
    private array $leases = [];

    /**
     * Row ids read by unprocessed(), so a retry of a row whose cache key has expired
     * still settles it by primary key.
     *
     * @var array<string, int>
     */
    private array $knownRows = [];

    /**
     * @param  array<string, mixed>  $columns  the `table.columns` config map
     * @param  string|null  $cacheDriver  the configured cache store's driver, for isDurable()
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly CacheFactory $cache,
        private readonly int $retentionHours,
        private readonly ?string $cacheStore,
        private readonly string $table,
        private readonly string $source,
        array $columns,
        private readonly ?string $cacheDriver = null,
    ) {
        if ($retentionHours < 48) {
            throw InvalidConfigurationException::invalid(
                'tmesis.webhooks.dedupe.retention_hours',
                'it must be at least 48, because the node retries a delivery for 24 hours'
            );
        }

        if (blank($table)) {
            throw InvalidConfigurationException::missing('tmesis.webhooks.dedupe.table.name');
        }

        if (blank($source)) {
            throw InvalidConfigurationException::missing('tmesis.webhooks.dedupe.table.source');
        }

        $this->columns = self::columnMap($columns);
    }

    public function claim(string $deliveryId, ReceivedDelivery $delivery, ?array $payload = null): bool
    {
        $key = $this->key($deliveryId);
        $ttl = now()->addHours($this->retentionHours);

        if (! $this->store()->add($key, self::CLAIMED, $ttl)) {
            return false;
        }

        $lease = $this->lock($deliveryId);
        $leased = (bool) $lease->get();
        $now = now();

        $row = [
            $this->columns['source'] => $this->source,
            $this->columns['payload'] => $delivery->rawBody,
            $this->columns['processed_at'] => null,
            $this->columns['created_at'] => $now,
            $this->columns['updated_at'] => $now,
        ];

        try {
            $rowId = $this->insert($row, $deliveryId);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->columns['delivery'] === null ? null : $this->claimedRow($deliveryId);

            if ($existing === null) {
                // Another unique index on the app's table, or no delivery column: an
                // insert that failed like any other.
                $this->abandonClaim($key, $lease, $leased);

                throw $e;
            }

            // The cache had lost the key, and the row says this delivery was claimed
            // already: a duplicate. Re-seed the key so the next sight stops at the
            // cache, and leave the row (and whoever holds its lease) alone.
            if ($leased) {
                $lease->release();
            }

            $this->seed($key, $existing, $ttl);

            return false;
        } catch (Throwable $e) {
            $this->abandonClaim($key, $lease, $leased);

            throw $e;
        }

        if ($leased) {
            $this->leases[$deliveryId] = ['lock' => $lease, 'retry' => false];
        }

        $this->seed($key, $rowId, $ttl);

        return true;
    }

    /**
     * Insert the claim's row, with the delivery id in the delivery column when one is
     * configured. Then inside its own transaction (a savepoint under a caller's), so a
     * unique violation on PostgreSQL cannot abort a transaction around the claim.
     *
     * @param  array<string, mixed>  $row
     */
    private function insert(array $row, string $deliveryId): int
    {
        if ($this->columns['delivery'] === null) {
            return (int) $this->connection->table($this->table)->insertGetId($row, $this->columns['id']);
        }

        $row[$this->columns['delivery']] = $deliveryId;

        return (int) $this->connection->transaction(
            fn (): int => (int) $this->connection->table($this->table)->insertGetId($row, $this->columns['id'])
        );
    }

    /**
     * The row already claimed for this delivery, by the indexed delivery column.
     */
    private function claimedRow(string $deliveryId): ?int
    {
        $delivery = $this->columns['delivery'];

        if ($delivery === null) {
            return null;
        }

        $id = $this->scoped()->where($delivery, $deliveryId)->value($this->columns['id']);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The controller is still inside the request, so a failed insert becomes a 500
     * and the node retries. Dropping the key makes that retry a first sight rather
     * than a duplicate of a delivery that was never stored.
     */
    private function abandonClaim(string $key, Lock $lease, bool $leased): void
    {
        $this->store()->forget($key);

        if ($leased) {
            $lease->release();
        }
    }

    /**
     * Keep the row id under the claim key, so later updates go by primary key.
     */
    private function seed(string $key, int $rowId, DateTimeInterface $ttl): void
    {
        try {
            $this->store()->put($key, $rowId, $ttl);
        } catch (Throwable $e) {
            // The claim held and the row is written; only the shortcut to it is
            // missing, and the payload query finds it.
            report($e);
        }
    }

    /**
     * Drop the claim so the same delivery id is new again. The row stays: the table
     * is the app's. With a delivery column, the id is cleared from it too, or the
     * unique index would answer the replay's claim as a duplicate; the old row is
     * then found by its payload only, and the replay's claim writes a new one.
     */
    public function forget(string $deliveryId): void
    {
        try {
            if ($this->columns['delivery'] !== null) {
                $this->scoped()
                    ->where($this->columns['delivery'], $deliveryId)
                    ->update([$this->columns['delivery'] => null]);
            }

            $this->store()->forget($this->key($deliveryId));
        } finally {
            $this->release($deliveryId);
        }
    }

    public function markProcessed(string $deliveryId): void
    {
        $now = now();
        $values = [
            $this->columns['processed_at'] => $now,
            $this->columns['updated_at'] => $now,
        ];

        if (($this->leases[$deliveryId]['retry'] ?? false) && $this->columns['reprocessed_at'] !== null) {
            $values[$this->columns['reprocessed_at']] = $now;
        }

        try {
            $this->rows($deliveryId)->whereNull($this->columns['processed_at'])->update($values);
        } finally {
            $this->release($deliveryId);
        }
    }

    /**
     * The table has no failure columns. The dispatcher has already reported and
     * logged the error; the row keeps a null processed_at and a fresh updated_at,
     * and the lease is released so it is due for tmesis:webhooks:retry.
     */
    public function recordFailure(string $deliveryId, Throwable $error): void
    {
        try {
            $this->rows($deliveryId)->update([$this->columns['updated_at'] => now()]);
        } finally {
            $this->release($deliveryId);
        }
    }

    public function lease(string $deliveryId): bool
    {
        $lock = $this->lock($deliveryId);

        if (! $lock->get()) {
            return false;
        }

        // Checked under the lock: a holder that settled the row between a listing and
        // this call has released it, and the row now reads processed.
        if (! $this->rows($deliveryId)->whereNull($this->columns['processed_at'])->exists()) {
            $lock->release();

            return false;
        }

        $this->leases[$deliveryId] = ['lock' => $lock, 'retry' => true];

        return true;
    }

    /**
     * Rows of this source with a null processed_at, oldest first.
     *
     * `$ids` and `$node` filter on the payload's `delivery` and `node`. A row whose
     * lease is held is left out, which is probed by taking and releasing the lock,
     * and reading continues past it until `$limit` rows are due. A row whose payload
     * does not decode or carries no `delivery` cannot be keyed and is left out too.
     */
    public function unprocessed(int $olderThanSeconds, int $limit, ?array $ids = null, ?string $node = null): array
    {
        $query = $this->scoped()
            ->whereNull($this->columns['processed_at'])
            ->where($this->columns['created_at'], '<=', now()->subSeconds($olderThanSeconds))
            ->orderBy($this->columns['created_at'])
            ->orderBy($this->columns['id']);

        if ($ids !== null) {
            $query->whereIn($this->columns['payload'].'->delivery', $ids);
        }

        if ($node !== null) {
            $query->where($this->columns['payload'].'->node', $node);
        }

        $due = [];

        foreach ($query->lazy(max($limit, 100)) as $row) {
            $stored = $this->toStored((array) $row);

            if ($stored === null || $this->isLeased($stored->deliveryId)) {
                continue;
            }

            $due[] = $stored;

            if (count($due) >= $limit) {
                break;
            }
        }

        return $due;
    }

    public function supportsRetry(): bool
    {
        return true;
    }

    /**
     * Never deletes: the table belongs to the app, and so does its retention.
     */
    public function prune(): int
    {
        return 0;
    }

    /**
     * Scoped to this source. Slow on a large table without an index on
     * (source, processed_at), which the app adds if tmesis:doctor is slow.
     */
    public function unprocessedOlderThan(int $seconds): int
    {
        return $this->scoped()
            ->whereNull($this->columns['processed_at'])
            ->where($this->columns['created_at'], '<', now()->subSeconds($seconds))
            ->count();
    }

    public function isDurable(): bool
    {
        return in_array($this->cacheDriver, self::DURABLE_CACHE_DRIVERS, true);
    }

    public function describe(): string
    {
        return 'table driver on ['.$this->table.'] as source ['.$this->source.'], dedupe on the ['
            .($this->cacheStore ?? 'default').'] cache store, retention '.$this->retentionHours.'h ('
            .($this->isDurable() ? 'durable' : 'not durable').')';
    }

    /**
     * What is wrong with the table and the cache store, for tmesis:doctor.
     *
     * Checks without writing a row: the table is shared with the app, and a probe
     * claim would leave one behind.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->connection instanceof Connection) {
            try {
                $schema = $this->connection->getSchemaBuilder();

                if (! $schema->hasTable($this->table)) {
                    $problems[] = 'table ['.$this->table.'] does not exist on the ['.$this->connection->getName().'] connection';
                } else {
                    $present = array_map('strtolower', $schema->getColumnListing($this->table));
                    $missing = array_values(array_filter(
                        $this->columns,
                        fn (?string $column): bool => $column !== null && ! in_array(strtolower($column), $present, true),
                    ));

                    if ($missing !== []) {
                        $problems[] = 'table ['.$this->table.'] has no column(s) ['.implode(', ', $missing)
                            .'], named in tmesis.webhooks.dedupe.table.columns';
                    }

                    $delivery = $this->columns['delivery'];

                    if ($delivery !== null && ! in_array(strtolower($delivery), array_map('strtolower', $missing), true)
                        && ! $this->hasUniqueIndex($schema->getIndexes($this->table), $delivery)) {
                        $problems[] = 'column ['.$delivery.'] on table ['.$this->table.'] has no unique index of its own, '
                            .'so tmesis.webhooks.dedupe.table.columns.delivery dedupes nothing past the cache';
                    }
                }
            } catch (Throwable $e) {
                $problems[] = 'table ['.$this->table.'] cannot be inspected: '.$e->getMessage();
            }
        }

        try {
            $probe = $this->key('doctor-probe-'.bin2hex(random_bytes(8)));

            if (! $this->store()->add($probe, 1, 60)) {
                $problems[] = 'the cache store reported a fresh probe key as present';
            }

            $this->store()->forget($probe);
        } catch (Throwable $e) {
            $problems[] = 'the cache store is unusable: '.$e->getMessage();
        }

        if (! $this->store()->getStore() instanceof LockProvider) {
            $problems[] = 'the ['.($this->cacheStore ?? 'default').'] cache store does not support locks, which the dispatch lease needs';
        }

        return $problems;
    }

    /**
     * Whether an index is unique on exactly this one column.
     *
     * @param  array<int, array<string, mixed>>  $indexes  Schema\Builder::getIndexes()
     */
    private function hasUniqueIndex(array $indexes, string $column): bool
    {
        foreach ($indexes as $index) {
            $columns = array_map(fn (mixed $c): string => strtolower((string) $c), (array) ($index['columns'] ?? []));

            if (($index['unique'] ?? false) === true && $columns === [strtolower($column)]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array{id: string, source: string, payload: string, processed_at: string, reprocessed_at: string|null, created_at: string, updated_at: string, delivery: string|null}
     */
    private static function columnMap(array $columns): array
    {
        $map = [];

        foreach (self::REQUIRED_COLUMNS as $name) {
            $column = $columns[$name] ?? null;

            if (! is_string($column) || blank($column)) {
                throw InvalidConfigurationException::invalid(
                    "tmesis.webhooks.dedupe.table.columns.{$name}",
                    'it must name a column on the table'
                );
            }

            $map[$name] = $column;
        }

        $optional = [];

        foreach (self::OPTIONAL_COLUMNS as $name) {
            $column = $columns[$name] ?? null;

            if ($column !== null && ! is_string($column)) {
                throw InvalidConfigurationException::invalid(
                    "tmesis.webhooks.dedupe.table.columns.{$name}",
                    'it must name a column on the table, or be null when the table has none'
                );
            }

            $optional[$name] = blank($column) ? null : $column;
        }

        return [
            'id' => $map['id'],
            'source' => $map['source'],
            'payload' => $map['payload'],
            'processed_at' => $map['processed_at'],
            'reprocessed_at' => $optional['reprocessed_at'],
            'created_at' => $map['created_at'],
            'updated_at' => $map['updated_at'],
            'delivery' => $optional['delivery'],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function toStored(array $values): ?StoredDelivery
    {
        $body = $values[$this->columns['payload']] ?? null;

        if (! is_string($body)) {
            return null;
        }

        $payload = json_decode($body, true);
        $deliveryId = is_array($payload) ? ($payload['delivery'] ?? null) : null;

        if (! is_array($payload) || ! is_string($deliveryId) || $deliveryId === '') {
            return null;
        }

        $rowId = $values[$this->columns['id']] ?? null;

        if (is_numeric($rowId)) {
            $this->knownRows[$deliveryId] = (int) $rowId;
        }

        $storedAt = new DateTimeImmutable((string) $values[$this->columns['created_at']]);

        return new StoredDelivery(
            deliveryId: $deliveryId,
            node: is_string($payload['node'] ?? null) ? $payload['node'] : '',
            event: is_string($payload['event'] ?? null) ? $payload['event'] : '',
            attempt: null,
            headers: [],
            body: $body,
            storedAt: $storedAt,
            timestamp: (string) ($this->occurredAt($payload) ?? $storedAt)->getTimestamp(),
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function occurredAt(array $payload): ?DateTimeImmutable
    {
        $occurredAt = $payload['occurred_at'] ?? null;

        if (! is_string($occurredAt) || $occurredAt === '') {
            return null;
        }

        try {
            return Rfc3339::parse($occurredAt);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * This delivery's row: by primary key when the cache (or a listing) has it, then
     * by the indexed delivery column when one is configured and holds the id, and by
     * the payload's `delivery` otherwise (a row stored before the column existed, or
     * one forget() cleared). Always inside this source.
     */
    private function rows(string $deliveryId): Builder
    {
        $rowId = $this->rowId($deliveryId);

        if ($rowId !== null) {
            return $this->scoped()->where($this->columns['id'], $rowId);
        }

        if ($this->columns['delivery'] !== null) {
            $byColumn = $this->scoped()->where($this->columns['delivery'], $deliveryId);

            if ((clone $byColumn)->exists()) {
                return $byColumn;
            }
        }

        return $this->scoped()->where($this->columns['payload'].'->delivery', $deliveryId);
    }

    private function rowId(string $deliveryId): ?int
    {
        if (isset($this->knownRows[$deliveryId])) {
            return $this->knownRows[$deliveryId];
        }

        $value = $this->store()->get($this->key($deliveryId));

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return null;
    }

    private function isLeased(string $deliveryId): bool
    {
        if (isset($this->leases[$deliveryId])) {
            return true;
        }

        $lock = $this->lock($deliveryId);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    private function release(string $deliveryId): void
    {
        $lease = $this->leases[$deliveryId] ?? null;

        unset($this->leases[$deliveryId], $this->knownRows[$deliveryId]);

        if ($lease !== null) {
            $lease['lock']->release();
        }
    }

    private function lock(string $deliveryId): Lock
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw InvalidConfigurationException::invalid(
                'tmesis.webhooks.dedupe.cache_store',
                'the "table" driver takes its dispatch lease as a cache lock, and this store does not support locks'
            );
        }

        return $store->lock('tmesis:delivery-lease:'.$deliveryId, self::LEASE_SECONDS);
    }

    private function scoped(): Builder
    {
        return $this->connection->table($this->table)->where($this->columns['source'], $this->source);
    }

    private function key(string $deliveryId): string
    {
        return 'tmesis:delivery:'.$deliveryId;
    }

    private function store(): Repository
    {
        return $this->cache->store($this->cacheStore);
    }
}
