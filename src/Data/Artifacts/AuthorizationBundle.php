<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The signed requests one refresh supplies for one transfer object. The openapi
 * component AuthorizationBundle (tmesis `v0.5.0`, `docs/API.md` 12a.4): `{put,
 * initiate, parts, complete, abort, head}`, every key always present.
 *
 * **A null operation is "not supplied", never a revocation.** The node MERGES a
 * refresh into the object's in-memory bundle, so a signer with nothing new for
 * `abort` leaves it null and the earlier one stays live.
 *
 * The staged multipart order is the node's: `initiate` first; `parts` and `abort`
 * only once the upload id is committed (read it from `ObjectStatus`, never predict
 * it); `complete` only once every part is durable. `head` is the reconciliation probe
 * the node asks for with `completion_probe_required`.
 */
final readonly class AuthorizationBundle
{
    /** The node refuses more part requests than this in one refresh. */
    public const MAX_PARTS = 128;

    /**
     * @param  list<PartAuthorization>|null  $parts
     */
    private function __construct(
        private ?SignedRequest $put,
        private ?SignedRequest $initiate,
        private ?array $parts,
        private ?SignedRequest $complete,
        private ?SignedRequest $abort,
        private ?SignedRequest $head,
    ) {}

    /**
     * @param  array<array-key, PartAuthorization>|null  $parts  at most MAX_PARTS;
     *                                                           chunk a larger set
     *                                                           over several refreshes
     */
    public static function make(
        ?SignedRequest $put = null,
        ?SignedRequest $initiate = null,
        ?array $parts = null,
        ?SignedRequest $complete = null,
        ?SignedRequest $abort = null,
        ?SignedRequest $head = null,
    ): self {
        if ($parts !== null && count($parts) > self::MAX_PARTS) {
            throw InvalidConfigurationException::invalid(
                'requests.parts',
                'at most '.self::MAX_PARTS.' part requests per refresh; send the rest in the next one'
            );
        }

        return new self($put, $initiate, $parts === null ? null : array_values($parts), $complete, $abort, $head);
    }

    public function put(): ?SignedRequest
    {
        return $this->put;
    }

    public function initiate(): ?SignedRequest
    {
        return $this->initiate;
    }

    /**
     * @return list<PartAuthorization>|null
     */
    public function parts(): ?array
    {
        return $this->parts;
    }

    public function complete(): ?SignedRequest
    {
        return $this->complete;
    }

    public function abort(): ?SignedRequest
    {
        return $this->abort;
    }

    public function head(): ?SignedRequest
    {
        return $this->head;
    }

    /**
     * The operations this bundle supplies, in the document's order, for a log line.
     *
     * @return list<string>
     */
    public function supplied(): array
    {
        return array_keys(array_filter([
            'put' => $this->put,
            'initiate' => $this->initiate,
            'parts' => $this->parts,
            'complete' => $this->complete,
            'abort' => $this->abort,
            'head' => $this->head,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The request body, credentials included. Every key is present; null is "not
     * supplied".
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'put' => $this->put?->toArray(),
            'initiate' => $this->initiate?->toArray(),
            'parts' => $this->parts === null ? null : array_map(
                static fn (PartAuthorization $part): array => $part->toArray(),
                $this->parts,
            ),
            'complete' => $this->complete?->toArray(),
            'abort' => $this->abort?->toArray(),
            'head' => $this->head?->toArray(),
        ];
    }
}
