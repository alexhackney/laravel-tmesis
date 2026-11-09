<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;

/**
 * The cause of the most recent failed transfer attempt. The openapi component
 * LastAttempt (tmesis `v0.10.0`, `docs/API.md` 12a.4), `last_attempt` on a transfer
 * object, a part and `transfer.failed` (where the event schema calls it
 * `TransferAttempt`).
 *
 * `code()` is the thing to key on; the set MAY GROW, so treat an unknown code as text.
 * `message()` is one sentence naming the endpoint HOST only (never a credential, a
 * signed URL, a query, userinfo or a provider body) and may change between builds. A
 * `last_attempt` of null is normal: a completed object has none, a part's clears when
 * it completes, `transfer.failed` carries null when the failure rests on no attempt,
 * and every row written before the node's journal migration 0034 has none.
 */
final readonly class LastAttempt
{
    public const CODE_DNS_NOT_FOUND = 'dns_not_found';

    public const CODE_DNS_ERROR = 'dns_error';

    public const CODE_CONNECT_REFUSED = 'connect_refused';

    public const CODE_CONNECT_TIMEOUT = 'connect_timeout';

    public const CODE_TLS_ERROR = 'tls_error';

    public const CODE_TIMEOUT = 'timeout';

    /**
     * A request nothing answered.
     */
    public const CODE_TRANSPORT = 'transport';

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
     * One of the CODE_* constants, else the provider's own code or `http_<status>`.
     */
    public function code(): string
    {
        return $this->string('code');
    }

    /**
     * One sentence naming the endpoint host. Safe to log; do not branch on it.
     */
    public function message(): string
    {
        return $this->string('message');
    }

    /**
     * When the attempt failed, as the node sent it.
     */
    public function atRaw(): string
    {
        return $this->string('at');
    }

    /**
     * When the attempt failed; null when the node's timestamp does not parse (the
     * schema does not pin the format).
     */
    public function at(): ?DateTimeImmutable
    {
        $value = $this->atRaw();

        if ($value === '') {
            return null;
        }

        try {
            return Rfc3339::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
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
}
