<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Dedupe on a cache store. The explicit opt-out from the durable default.
 *
 * `add()` is the atomic primitive: it writes only when the key is absent, and returns
 * whether it did. On the `array` and `file` stores that is atomic within one process
 * only, which is fine for tests and wrong for production; `redis`, `database`,
 * `memcached` and `dynamodb` are the production choices.
 *
 * This driver stores nothing but the id. It cannot answer "was this processed" and it
 * does not survive a restart, so a receiver on it can lose the only record of a
 * `recording.registered` the node has already deleted its copy of. Choose it
 * knowingly; `tmesis:doctor` warns whenever it is in use.
 *
 * It keeps no payloads, so it cannot retry. A failed dispatch keeps its claim (a late
 * duplicate is still not dispatched a second time) and is reported and logged, and
 * that is all: `tmesis:webhooks:retry` refuses this driver rather than pretend. A
 * cache is no place for the only copy of a delivery.
 */
class CacheDeliveryDeduper implements DeliveryDeduper
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly int $retentionHours,
        private readonly ?string $store = null,
    ) {}

    public function claim(string $deliveryId, ReceivedDelivery $delivery, ?array $payload = null): bool
    {
        return $this->store()->add($this->key($deliveryId), 1, now()->addHours($this->retentionHours));
    }

    public function forget(string $deliveryId): void
    {
        $this->store()->forget($this->key($deliveryId));
    }

    public function markProcessed(string $deliveryId): void
    {
        // Nothing to mark: this store holds the id and no state beside it.
    }

    public function recordFailure(string $deliveryId, Throwable $error): void
    {
        // Nothing to record against, and the claim stays: dropping it would only let
        // a duplicate through, never bring the delivery back.
    }

    public function lease(string $deliveryId): bool
    {
        return false;
    }

    public function unprocessed(int $olderThanSeconds, int $limit, ?array $ids = null, ?string $node = null): array
    {
        return [];
    }

    public function supportsRetry(): bool
    {
        return false;
    }

    public function prune(): int
    {
        // The cache expires its own entries.
        return 0;
    }

    public function unprocessedOlderThan(int $seconds): int
    {
        // Not knowable here: nothing records when a claim was made or handled.
        return 0;
    }

    public function isDurable(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'cache driver on the ['.($this->store ?? 'default').'] store, retention '
            .$this->retentionHours.'h (not durable)';
    }

    private function key(string $deliveryId): string
    {
        return 'tmesis:delivery:'.$deliveryId;
    }

    private function store(): Repository
    {
        return $this->cache->store($this->store);
    }
}
