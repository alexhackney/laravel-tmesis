<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Webhooks;

use Throwable;

/**
 * Delivery deduplication.
 *
 * The node is at-least-once and unordered: a duplicate is normal traffic, not an
 * error, and the receiver's job is to answer 204 without doing the work twice.
 *
 * The dedupe KEY is resolved by the controller (the signed body's `delivery` when the
 * body decoded, the header otherwise) and passed in. An implementation never derives
 * its own key.
 */
interface DeliveryDeduper
{
    /**
     * Claim a delivery. True on first sight, false when it has been seen.
     *
     * Must be atomic: two workers handed the same delivery concurrently must see
     * exactly one true.
     *
     * @param  array<string, mixed>|null  $payload  the decoded body, when it decoded
     */
    public function claim(string $deliveryId, ReceivedDelivery $delivery, ?array $payload = null): bool;

    /**
     * Drop a claim, and with it the stored copy, so the same delivery id is new again.
     *
     * For operators replaying by hand. The receiver never calls it: the node has its
     * 204 before dispatch and never retries an acknowledged delivery, so a failed
     * dispatch keeps its claim and payload (recordFailure()) for tmesis:webhooks:retry.
     */
    public function forget(string $deliveryId): void;

    /**
     * Mark a delivery as fully handled and release its lease. A no-op for stores that
     * record only the id.
     */
    public function markProcessed(string $deliveryId): void;

    /**
     * Record a failed dispatch. The claim and the payload stay, unprocessed.
     *
     * Releases the lease, so the row is due for tmesis:webhooks:retry. Stores the
     * exception class and a truncated message only; never the body or a secret.
     */
    public function recordFailure(string $deliveryId, Throwable $error): void;

    /**
     * Take the dispatch lease on an unprocessed delivery. True for exactly one caller.
     *
     * Atomic: a claim holds the lease while the original request dispatches, so two
     * retry runs, or a retry racing the original, never dispatch one row twice. False
     * for a processed row, a row whose lease is held, an unknown id, and a store that
     * keeps no payloads.
     */
    public function lease(string $deliveryId): bool;

    /**
     * Unprocessed deliveries due for a retry, oldest first.
     *
     * Every filter applies: stored at least $olderThanSeconds ago, not leased, and,
     * when given, among $ids and from $node. Empty for a store that keeps no payloads.
     * A returned row is a candidate only; lease() it before dispatching.
     *
     * @param  list<string>|null  $ids  dedupe keys
     * @return list<StoredDelivery>
     */
    public function unprocessed(int $olderThanSeconds, int $limit, ?array $ids = null, ?string $node = null): array;

    /**
     * Whether this store keeps the payload of a failed dispatch for a local retry.
     */
    public function supportsRetry(): bool;

    /**
     * Remove rows that are processed AND older than the retention window.
     *
     * Never removes an unprocessed row of any age: an unprocessed row is work that
     * has not happened, and deleting it would lose it silently.
     *
     * @return int rows removed
     */
    public function prune(): int;

    /**
     * How many claimed-but-never-processed rows are older than this many seconds.
     *
     * Every one of them is a dispatch that failed or never ran; tmesis:webhooks:retry
     * dispatches them again.
     */
    public function unprocessedOlderThan(int $seconds): int;

    /**
     * Whether this store survives a restart.
     *
     * Load bearing rather than informational: the node is at-least-once and retries,
     * so a store that forgets on restart re-dispatches every delivery retried after
     * the restart and every listener runs twice for those. `recording.registered` is
     * where that costs most (an asset attached twice), which is what `tmesis:doctor`
     * warns about.
     */
    public function isDurable(): bool;

    /**
     * A one-line description for `tmesis:doctor`.
     */
    public function describe(): string;
}
