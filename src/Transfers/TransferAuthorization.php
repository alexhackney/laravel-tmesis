<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Transfers;

use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * What a TransferSigner hands back: the signed requests for one need, with NO bound
 * on the part count.
 *
 * Not the wire AuthorizationBundle, which the node caps at 128 parts per refresh.
 * bundles() splits this into refreshes the node takes: the first carries every
 * non-part operation and the first 128 parts, each later one the next 128. The node
 * merges each accepted refresh into the object's in-memory bundle (parts merge by
 * number), so the split loses nothing.
 *
 * Every request inside is a SignedRequest, a credential; see there. Nothing here
 * prints one: supplied() and partNumbers() are the loggable views.
 */
final readonly class TransferAuthorization
{
    /**
     * @param  list<PartAuthorization>  $parts  ascending by part number
     */
    private function __construct(
        private ?SignedRequest $put,
        private ?SignedRequest $initiate,
        private array $parts,
        private ?SignedRequest $complete,
        private ?SignedRequest $abort,
        private ?SignedRequest $head,
    ) {}

    /**
     * @param  array<array-key, PartAuthorization>|null  $parts  any number, each part
     *                                                           number at most once
     */
    public static function make(
        ?SignedRequest $put = null,
        ?SignedRequest $initiate = null,
        ?array $parts = null,
        ?SignedRequest $complete = null,
        ?SignedRequest $abort = null,
        ?SignedRequest $head = null,
    ): self {
        $byNumber = [];

        foreach ($parts ?? [] as $part) {
            if (isset($byNumber[$part->partNumber()])) {
                throw InvalidConfigurationException::invalid(
                    'requests.parts',
                    "part {$part->partNumber()} is signed twice"
                );
            }

            $byNumber[$part->partNumber()] = $part;
        }

        ksort($byNumber);

        return new self($put, $initiate, array_values($byNumber), $complete, $abort, $head);
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
     * @return list<PartAuthorization>
     */
    public function parts(): array
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
     * The signed part numbers, ascending. Safe to log.
     *
     * @return list<int>
     */
    public function partNumbers(): array
    {
        return array_map(static fn (PartAuthorization $part): int => $part->partNumber(), $this->parts);
    }

    /**
     * The operations this answer supplies, in the document's order. Safe to log.
     *
     * @return list<string>
     */
    public function supplied(): array
    {
        return array_keys(array_filter([
            'put' => $this->put,
            'initiate' => $this->initiate,
            'parts' => $this->parts === [] ? null : $this->parts,
            'complete' => $this->complete,
            'abort' => $this->abort,
            'head' => $this->head,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function isEmpty(): bool
    {
        return $this->supplied() === [];
    }

    /**
     * The refreshes that carry this answer, in the order they must be sent, each
     * within the node's per-refresh part cap. Empty when there is nothing to send.
     *
     * @return list<AuthorizationBundle>
     */
    public function bundles(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $chunks = $this->parts === [] ? [null] : array_chunk($this->parts, AuthorizationBundle::MAX_PARTS);
        $bundles = [];

        foreach ($chunks as $index => $parts) {
            $bundles[] = $index === 0
                ? AuthorizationBundle::make($this->put, $this->initiate, $parts, $this->complete, $this->abort, $this->head)
                : AuthorizationBundle::make(parts: $parts);
        }

        return $bundles;
    }
}
