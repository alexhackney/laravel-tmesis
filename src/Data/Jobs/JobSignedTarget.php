<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Jobs;

use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * A caller-owned bucket a job output is written to with requests the CALLER signs. The
 * openapi component JobSignedTarget (tmesis `v0.6.0`, `docs/API.md` 12a.4), a
 * JobTarget's `signed`.
 *
 * SignedDestination without initial authorizations: a job's plan is filed by the node
 * at finalization, so every signed request is supplied on demand, through
 * `transfer.authorization_required` and `jobArtifacts()->supplyJobTransferAuthorization()`
 * (the package's TransferSigner seam does it). No credential reaches the node.
 *
 * The ranges are the document's own and share SignedDestination's constants.
 */
final readonly class JobSignedTarget
{
    private function __construct(
        private string $region,
        private string $bucket,
        private ?string $endpoint,
        private ?string $addressing,
        private ?int $partSizeMb,
        private ?int $uploadConcurrency,
    ) {}

    /**
     * Every optional field left null is omitted, and the node applies its default:
     * AWS regional resolution, `auto` addressing, 16 MiB parts, 8 concurrent uploads.
     *
     * @param  string|null  $endpoint  an absolute http or https URL without userinfo,
     *                                 query or fragment
     */
    public static function make(
        string $region,
        string $bucket,
        ?string $endpoint = null,
        ?string $addressing = null,
        ?int $partSizeMb = null,
        ?int $uploadConcurrency = null,
    ): self {
        SignedDestination::guardEndpoint($endpoint, 'signed.endpoint');

        if ($addressing !== null && ! in_array($addressing, SignedDestination::ADDRESSING, true)) {
            throw InvalidConfigurationException::invalid('signed.addressing', 'it must be one of '.implode(', ', SignedDestination::ADDRESSING));
        }

        if ($partSizeMb !== null && ($partSizeMb < SignedDestination::MIN_PART_SIZE_MB || $partSizeMb > SignedDestination::MAX_PART_SIZE_MB)) {
            throw InvalidConfigurationException::invalid(
                'signed.part_size_mb',
                'it must be between '.SignedDestination::MIN_PART_SIZE_MB.' and '.SignedDestination::MAX_PART_SIZE_MB
            );
        }

        if ($uploadConcurrency !== null && ($uploadConcurrency < SignedDestination::MIN_UPLOAD_CONCURRENCY || $uploadConcurrency > SignedDestination::MAX_UPLOAD_CONCURRENCY)) {
            throw InvalidConfigurationException::invalid(
                'signed.upload_concurrency',
                'it must be between '.SignedDestination::MIN_UPLOAD_CONCURRENCY.' and '.SignedDestination::MAX_UPLOAD_CONCURRENCY
            );
        }

        return new self($region, $bucket, $endpoint, $addressing, $partSizeMb, $uploadConcurrency);
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
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter([
            'region' => $this->region,
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
            'addressing' => $this->addressing,
            'part_size_mb' => $this->partSizeMb,
            'upload_concurrency' => $this->uploadConcurrency,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
