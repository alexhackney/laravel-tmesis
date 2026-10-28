<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Transfers;

/**
 * Signs what the node asked for on a signed-target transfer object (tmesis `v0.5.0`,
 * `docs/API.md` 12a.4, WEBHOOKS.md 8.28 `transfer.authorization_required`).
 *
 * The node never holds a signed target's credentials: it asks, and this answers. An
 * implementation presigns one request per operation in `$need->requiredOperations()`
 * against `$need->target()` and `$need->objectKey()`, plus one per part in
 * `$need->owedParts()` when `parts` is required, and returns them. That is ALL it
 * does: the binding fields, the authorization revision, chunking past 128 parts and
 * the `supplyTransferAuthorization` calls are RefreshTransferAuthorization's.
 *
 * Return null to leave the object waiting (no credentials for that bucket, a
 * destination this platform no longer writes to). The node asks again only for a NEW
 * need or after a restart, so null is a decision, not a retry.
 *
 * `head` is a real request, not an afterthought: `completion_probe_required` asks for
 * a HEAD alone and the node sends nothing for the object until one settles it. A
 * signer that can only mint writes leaves such an object waiting until five probes
 * fail it.
 *
 * Throwing fails the queued job, which retries under the consumer's queue settings;
 * each retry reads the object again, so a retry never replays a stale need.
 *
 * Resolved from the container: constructor injection works. Bind it in a service
 * provider's register(), or name the class in `tmesis.transfers.signer`.
 */
interface TransferSigner
{
    public function sign(TransferAuthorizationNeed $need): ?TransferAuthorization;
}
