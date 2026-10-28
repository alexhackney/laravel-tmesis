<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

/**
 * A told master's one target as the node resolved it at acceptance: the `target` of a
 * MasterInfo. The openapi component MasterTarget (tmesis `v0.8.0`, `docs/API.md`
 * 12a.8).
 *
 * Secret-free by construction, like PlanTargetStatus: `credentialProfile()` is a
 * profile NAME, never credential bytes, and a signed target's requests are never
 * here. At completion this target is the generation's plan revision 1, and the
 * upload's progress is that plan's transfers.
 */
final readonly class MasterTarget
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
     * Always `recording_master`.
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
     * The told object key, verbatim: the node never builds a name.
     */
    public function key(): string
    {
        return $this->string('key');
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
