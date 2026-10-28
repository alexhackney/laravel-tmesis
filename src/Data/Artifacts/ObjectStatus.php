<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * One transfer object: one inventory path written to one target under one plan
 * revision. The openapi component ObjectStatus (tmesis `v0.5.0`, `docs/API.md` 12a.4),
 * an item of `listTransfers` and the body of `supplyTransferAuthorization`.
 *
 * SAFE STATUS ONLY: the node never echoes a signed URL or header here, and this class
 * has nowhere to hold one. The object's own id is `id()`; everything that refers to it
 * from elsewhere (a webhook, a part row) spells it `object_id`.
 */
final readonly class ObjectStatus
{
    public const STATE_QUEUED = 'queued';

    public const STATE_UPLOADING = 'uploading';

    public const STATE_WAITING_AUTHORIZATION = 'waiting_authorization';

    public const STATE_RETRY_FAILED = 'retry_failed';

    public const STATE_FAILED = 'failed';

    public const STATE_COMPLETE = 'complete';

    /** @var list<string> */
    public const STATES = [
        self::STATE_QUEUED,
        self::STATE_UPLOADING,
        self::STATE_WAITING_AUTHORIZATION,
        self::STATE_RETRY_FAILED,
        self::STATE_FAILED,
        self::STATE_COMPLETE,
    ];

    /** @var list<string> */
    public const PHASES = ['put', 'initiate', 'parts', 'complete', 'probe', 'copy'];

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function id(): string
    {
        return $this->string('id');
    }

    public function generation(): string
    {
        return $this->string('generation');
    }

    public function planRevision(): int
    {
        return $this->int('plan_revision');
    }

    public function targetId(): string
    {
        return $this->string('target_id');
    }

    /**
     * The inventory path this object copies.
     */
    public function artifactPath(): string
    {
        return $this->string('artifact_path');
    }

    /**
     * The exact told key the object is written to. No node identity is inserted.
     */
    public function objectKey(): string
    {
        return $this->string('object_key');
    }

    public function sizeBytes(): int
    {
        return $this->int('size_bytes');
    }

    public function checksumSha256(): string
    {
        return $this->string('checksum_sha256');
    }

    /**
     * One of STATES.
     */
    public function state(): string
    {
        return $this->string('state');
    }

    /**
     * One of PHASES.
     */
    public function phase(): string
    {
        return $this->string('phase');
    }

    /**
     * The provider's upload id; null before a multipart upload has one. A signer
     * never predicts it: it names this value back in the refresh.
     */
    public function multipartUploadId(): ?string
    {
        return $this->nullableString('multipart_upload_id');
    }

    /**
     * The effective part size once a multipart layout is recorded; null before.
     */
    public function partSizeBytes(): ?int
    {
        return $this->nullableInt('part_size_bytes');
    }

    public function partCount(): int
    {
        return $this->int('part_count');
    }

    public function completedPartCount(): int
    {
        return $this->int('completed_part_count');
    }

    /**
     * The frozen completion body hash, for a payload-bound signature; null until every
     * part is complete.
     */
    public function completionBodySha256(): ?string
    {
        return $this->nullableString('completion_body_sha256');
    }

    /**
     * The last accepted authorization revision; the next refresh sends this plus 1.
     */
    public function authorizationRevision(): int
    {
        return $this->int('authorization_revision');
    }

    /**
     * Whether signed requests for this object are installed on the node. They live in
     * memory only, so a restart makes this false and the object waits for the next
     * refresh.
     */
    public function authorizationAvailable(): bool
    {
        return (bool) ($this->raw['authorization_available'] ?? false);
    }

    /**
     * The signed operations a waiting signed object needs next (`put`, `initiate`,
     * `parts`, `complete`, `abort`, `head`). Empty for a configured target.
     *
     * @return list<string>
     */
    public function authorizationRequired(): array
    {
        $value = $this->raw['authorization_required'] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * True when a write's outcome is unknown and the node must settle it with a HEAD
     * before anything else; the release stays held while it is.
     */
    public function reconcileRequired(): bool
    {
        return (bool) ($this->raw['reconcile_required'] ?? false);
    }

    /**
     * The provider's ETag. Not a checksum.
     */
    public function etag(): ?string
    {
        return $this->nullableString('etag');
    }

    public function providerChecksum(): ?string
    {
        return $this->nullableString('provider_checksum');
    }

    public function providerChecksumType(): ?string
    {
        return $this->nullableString('provider_checksum_type');
    }

    public function providerVersionId(): ?string
    {
        return $this->nullableString('provider_version_id');
    }

    public function completedAt(): ?DateTimeImmutable
    {
        $value = $this->nullableString('completed_at');

        return ($value === null || $value === '') ? null : Rfc3339::parse($value);
    }

    public function error(): ?ObjectError
    {
        $value = $this->raw['error'] ?? null;

        /** @var array<string, mixed>|null $value */
        return is_array($value) ? ObjectError::fromArray($value) : null;
    }

    /**
     * The response as it arrived, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function int(string $key): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
