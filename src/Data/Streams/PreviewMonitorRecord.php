<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

/**
 * Where a producer's monitor connects for the low-delay preview: the
 * `preview_monitor` block that `GET /streams/{id}?include=preview` adds to the
 * record (API.md 9.5). Absent without the token, and never on the list route.
 *
 * The preview is served on the node's PREVIEW listener (`preview.listen`, default
 * `:8889`), not the admin API, and is open: no bearer token, the firewall scopes the
 * listener to producer networks. The client is a WHEP player in a browser; this
 * package never speaks WHEP.
 *
 * Every WHEP `201` carries `X-Tmesis-Stream-State: live|held`, a snapshot at answer
 * time, exposed cross-origin: a HELD session answers `201` with `held` and no frames
 * until the rejoin, and only a hold entered before the tap's first init is
 * `409 not_live` (API.md 9.20; tmesis D1, 2026-09-18). The player reads the header
 * to tell "waiting for the publisher" from "live", so a panel across a hiccup binds
 * and waits rather than polling.
 *
 * @see PreviewMonitorRecord in docs/contract/openapi.json at tmesis v0.2.0.
 */
final readonly class PreviewMonitorRecord
{
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

    /**
     * The WHEP endpoint's path on the preview listener, `/whep/v1/streams/<id>`.
     * Always reported.
     */
    public function whepPath(): string
    {
        return $this->string('whep_path');
    }

    /**
     * That path completed with the node's `preview.public_url`. Null until that key
     * is set: the box never derives a public base of its own.
     */
    public function whepUrl(): ?string
    {
        return $this->nullableString('whep_url');
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
}
