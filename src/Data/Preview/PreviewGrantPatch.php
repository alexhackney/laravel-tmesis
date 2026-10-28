<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Preview;

/**
 * The body of `updatePreviewGrant`: the grant's new life from NOW. The openapi
 * component PreviewGrantPatch (tmesis v0.6.0, `docs/API.md` 9.20a).
 *
 * `expires_at` becomes now plus this, whether that is later or earlier than before;
 * monitors the grant covers keep running. This is the panel's reload: extend the
 * grant it opened rather than asking for a longer one up front. An expired or revoked
 * grant is `404` and is never revived: create a new one.
 */
final readonly class PreviewGrantPatch
{
    private function __construct(private int $ttlSeconds) {}

    /**
     * @param  int  $ttlSeconds  60 to 86400, from now
     */
    public static function make(int $ttlSeconds): self
    {
        return new self(PreviewGrantInput::guardTtl($ttlSeconds));
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * @return array{ttl_seconds: int}
     */
    public function toArray(): array
    {
        return ['ttl_seconds' => $this->ttlSeconds];
    }
}
