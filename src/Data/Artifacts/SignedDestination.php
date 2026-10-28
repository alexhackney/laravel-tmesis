<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * A bucket the node writes to with requests the CALLER signs, rather than through a
 * destination configured on the node. The openapi component SignedDestination (tmesis
 * `v0.5.0`, `docs/API.md` 12a.3), a PlanTarget's `signed`.
 *
 * The node never holds credentials for it: it asks, with
 * `transfer.authorization_required`, and the caller answers with
 * `supplyTransferAuthorization`. Initial authorizations may ride along; see
 * InitialAuthorization. Their plan and target bindings are not held here: the
 * enclosing PlanTarget passes them to toArray() when the plan is rendered.
 *
 * The ranges below are the document's own and are checked here, and so is the
 * endpoint's grammar (`internal/objectkey` `EndpointProblem`): an absolute http or
 * https URL with a host and no userinfo, query or fragment. A URL that breaks it may
 * carry a credential (a password, a presigned signature), so it is refused BEFORE it
 * can reach a request, a fake's call log or an exception, and the refusal never names
 * the value.
 */
final readonly class SignedDestination
{
    /** @var list<string> */
    public const ADDRESSING = ['auto', 'path', 'virtual'];

    public const MIN_PART_SIZE_MB = 5;

    public const MAX_PART_SIZE_MB = 512;

    public const MIN_UPLOAD_CONCURRENCY = 1;

    public const MAX_UPLOAD_CONCURRENCY = 64;

    /**
     * @param  list<InitialAuthorization>  $authorizations
     */
    private function __construct(
        private string $region,
        private string $bucket,
        private ?string $endpoint,
        private ?string $addressing,
        private ?int $partSizeMb,
        private ?int $uploadConcurrency,
        private array $authorizations,
    ) {}

    /**
     * Every optional field left null is omitted, and the node applies its default:
     * AWS regional resolution, `auto` addressing, 16 MiB parts, 8 concurrent uploads.
     *
     * @param  string|null  $endpoint  an absolute http or https URL without userinfo,
     *                                 query or fragment
     * @param  array<array-key, InitialAuthorization>  $authorizations
     */
    public static function make(
        string $region,
        string $bucket,
        ?string $endpoint = null,
        ?string $addressing = null,
        ?int $partSizeMb = null,
        ?int $uploadConcurrency = null,
        array $authorizations = [],
    ): self {
        self::guardEndpoint($endpoint, 'signed.endpoint');

        if ($addressing !== null && ! in_array($addressing, self::ADDRESSING, true)) {
            throw InvalidConfigurationException::invalid('signed.addressing', 'it must be one of '.implode(', ', self::ADDRESSING));
        }

        if ($partSizeMb !== null && ($partSizeMb < self::MIN_PART_SIZE_MB || $partSizeMb > self::MAX_PART_SIZE_MB)) {
            throw InvalidConfigurationException::invalid(
                'signed.part_size_mb',
                'it must be between '.self::MIN_PART_SIZE_MB.' and '.self::MAX_PART_SIZE_MB
            );
        }

        if ($uploadConcurrency !== null && ($uploadConcurrency < self::MIN_UPLOAD_CONCURRENCY || $uploadConcurrency > self::MAX_UPLOAD_CONCURRENCY)) {
            throw InvalidConfigurationException::invalid(
                'signed.upload_concurrency',
                'it must be between '.self::MIN_UPLOAD_CONCURRENCY.' and '.self::MAX_UPLOAD_CONCURRENCY
            );
        }

        return new self($region, $bucket, $endpoint, $addressing, $partSizeMb, $uploadConcurrency, array_values($authorizations));
    }

    /**
     * The node's endpoint rule, mirrored for both signed shapes (this one and
     * `JobSignedTarget`): null is the node's AWS regional resolution; anything else
     * must be an absolute http or https URL with a host and no userinfo, query (not even
     * a bare `?`) or fragment (not even a bare `#`). Refused with the node's sentence and
     * never the value.
     */
    public static function guardEndpoint(?string $endpoint, string $field): void
    {
        if ($endpoint === null) {
            return;
        }

        $parts = parse_url($endpoint);

        // The authority is what follows `//` up to the next `/`: an `@` there is
        // userinfo, even an empty one (`https://@host`), which Go reads as non-nil.
        $authority = (string) preg_replace('#^[^:]*://([^/]*).*$#s', '$1', $endpoint);

        $sound = is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && ! str_contains($authority, '@')
            && ! str_contains($endpoint, '?')
            && ! str_contains($endpoint, '#');

        if (! $sound) {
            throw InvalidConfigurationException::invalid($field, 'must be an absolute http or https URL without userinfo, query or fragment');
        }
    }

    public function region(): string
    {
        return $this->region;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function addressing(): ?string
    {
        return $this->addressing;
    }

    public function partSizeMb(): ?int
    {
        return $this->partSizeMb;
    }

    public function uploadConcurrency(): ?int
    {
        return $this->uploadConcurrency;
    }

    /**
     * @return list<InitialAuthorization>
     */
    public function authorizations(): array
    {
        return $this->authorizations;
    }

    /**
     * The request body. Initial authorizations carry credentials; see SignedRequest.
     *
     * The three bindings are the enclosing plan's and target's, which PlanTarget and
     * PlanRequest pass down. They are needed only when there are initial
     * authorizations, and rendering those without them is refused rather than guessed.
     *
     * @param  int|null  $planRevision  the revision the plan creates
     * @param  string|null  $inventorySha256  the plan's inventory_sha256
     * @param  string|null  $targetId  the enclosing target's id
     * @return array<string, mixed>
     */
    public function toArray(?int $planRevision = null, ?string $inventorySha256 = null, ?string $targetId = null): array
    {
        $body = ['region' => $this->region, 'bucket' => $this->bucket];

        if ($this->endpoint !== null) {
            $body['endpoint'] = $this->endpoint;
        }

        if ($this->addressing !== null) {
            $body['addressing'] = $this->addressing;
        }

        if ($this->partSizeMb !== null) {
            $body['part_size_mb'] = $this->partSizeMb;
        }

        if ($this->uploadConcurrency !== null) {
            $body['upload_concurrency'] = $this->uploadConcurrency;
        }

        if ($this->authorizations !== []) {
            if ($planRevision === null || $inventorySha256 === null || $targetId === null) {
                throw InvalidConfigurationException::invalid(
                    'signed.authorizations',
                    'initial authorizations are bound to the plan and target they ride in; render them through PlanRequest::toArray()'
                );
            }

            $body['authorizations'] = array_map(
                static fn (InitialAuthorization $authorization): array => $authorization->toArray($planRevision, $inventorySha256, $targetId),
                $this->authorizations,
            );
        }

        return $body;
    }
}
