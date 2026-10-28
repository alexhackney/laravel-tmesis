<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * One target of a plan revision as the node resolved it at acceptance. The openapi
 * component PlanTargetStatus (tmesis `v0.5.0`, `docs/API.md` 12a.3).
 *
 * Secret-free by construction: `credentialProfile()` is a profile NAME, never
 * credential bytes, and a signed target's requests are never here.
 */
final readonly class PlanTargetStatus
{
    public const AUTHORIZATION_CONFIGURED = 'configured';

    public const AUTHORIZATION_SIGNED = 'signed';

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

    /**
     * One of `ArtifactStatus::KINDS`, the six job kinds included since `v0.6.0`.
     */
    public function artifact(): string
    {
        return $this->string('artifact');
    }

    /**
     * `configured` (a `destination_ref`) or `signed`.
     */
    public function authorization(): string
    {
        return $this->string('authorization');
    }

    public function destinationRef(): ?string
    {
        return $this->nullableString('destination_ref');
    }

    /**
     * Empty is AWS regional resolution.
     */
    public function endpoint(): string
    {
        return $this->string('endpoint');
    }

    public function region(): string
    {
        return $this->string('region');
    }

    public function bucket(): string
    {
        return $this->string('bucket');
    }

    /**
     * The profile NAME the target signs with; null for a signed target.
     */
    public function credentialProfile(): ?string
    {
        return $this->nullableString('credential_profile');
    }

    public function uploadConcurrency(): int
    {
        return $this->int('upload_concurrency');
    }

    /**
     * `auto`, `path` or `virtual`.
     */
    public function addressing(): string
    {
        return $this->string('addressing');
    }

    public function partSizeBytes(): int
    {
        return $this->int('part_size_bytes');
    }

    /**
     * The told object key of a one-file target (a recording, `job_audio`,
     * `job_probe`); null for a prefix target.
     */
    public function key(): ?string
    {
        return $this->nullableString('key');
    }

    /**
     * The told prefix of an HLS target (`hls_sealed`, `job_hls`) or a files target
     * (`job_mp4`, `job_thumbnails`, `job_transcript`), REPLACING (not extending) the
     * destination's live prefix; empty is the bucket root; null for a one-file target.
     */
    public function prefix(): ?string
    {
        return $this->nullableString('prefix');
    }

    /**
     * The master's remote name for an HLS target (`playlist.m3u8` by default for
     * both `hls_sealed` and `job_hls` since tmesis `v0.8.0`; `hls_sealed`'s was
     * `vod.m3u8` before); null for any other.
     */
    public function masterName(): ?string
    {
        return $this->nullableString('master_name');
    }

    public function counts(): TransferCounts
    {
        $value = $this->raw['counts'] ?? null;

        /** @var array<string, mixed> $counts */
        $counts = is_array($value) ? $value : [];

        return TransferCounts::fromArray($counts);
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
}
