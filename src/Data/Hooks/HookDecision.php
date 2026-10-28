<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Hooks;

use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Support\RelayUrl;

/**
 * The answer to a `publish.verify` hook.
 *
 * `allow` is ALWAYS present in the rendered body. An omitted `allow` is a deny at the
 * node, so a decision object that could serialise without it would turn a bug into a
 * rejected show. `reason` and `patch` are omitted when null.
 *
 * The patch IS a stream PATCH body: the node decodes it into its `StreamSpec` with
 * unknown fields refused (`internal/node/gateseam.go`, `hookPatchSpec`), so the
 * builder that writes `updateStream()` bodies writes this one too. It can carry relay
 * URLs, which are credentials: log toRedactedArray(), never toArray().
 *
 * Since tmesis `v0.6.0` the body is contract: `publish.verify.response.schema.json`
 * (its `$defs` name is `HookResponse`; this class keeps the name it shipped with,
 * `InventoryTest`'s `DEF_CLASS_DEVIATIONS`), the generated example
 * `publish.verify.response.json`, and the worked responses in `test/vectors/hook.json`,
 * which the `vectors-hook` leg reproduces through this class. The runtime never
 * validates on the 500 ms path; the `hook-endpoint` leg proves every shape instead.
 *
 * @see HookResponse in docs/contract/schemas/publish.verify.response.schema.json at tmesis
 *      v0.6.0. The class keeps the name it shipped with.
 */
final readonly class HookDecision
{
    /**
     * The contract schema this body validates against.
     */
    public const RESPONSE_SCHEMA = 'publish.verify.response';

    /**
     * @param  array<string, mixed>|null  $patch
     */
    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?array $patch = null,
    ) {}

    /**
     * A StreamSpec patch is rendered through toRequestBody(), so `labels([])` goes out
     * as `{}` exactly as it does on `updateStream()`. An array passes through
     * verbatim, for a field the builder does not know yet.
     *
     * @param  StreamSpec|array<string, mixed>|null  $patch
     */
    public static function allow(bool $allow = true, ?string $reason = null, StreamSpec|array|null $patch = null): self
    {
        return new self($allow, $reason, $patch instanceof StreamSpec ? $patch->toRequestBody() : $patch);
    }

    public static function deny(?string $reason = null): self
    {
        return new self(false, $reason);
    }

    /**
     * The body the node is answered with.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['allow' => $this->allowed];

        if ($this->reason !== null) {
            $body['reason'] = $this->reason;
        }

        if ($this->patch !== null) {
            $body['patch'] = $this->patch;
        }

        return $body;
    }

    /**
     * The same body with every `patch.relays[*].url` redacted to scheme, host and
     * port: what a log line, an event listener or an exception may carry.
     *
     * @return array<string, mixed>
     */
    public function toRedactedArray(): array
    {
        $body = $this->toArray();

        if (isset($body['patch']['relays']) && is_array($body['patch']['relays'])) {
            $body['patch']['relays'] = array_map(
                static function (mixed $relay): mixed {
                    if (is_array($relay) && isset($relay['url']) && is_string($relay['url'])) {
                        $relay['url'] = RelayUrl::redact($relay['url']);
                    }

                    return $relay;
                },
                $body['patch']['relays'],
            );
        }

        return $body;
    }

    /**
     * var_dump() and print_r() show the redacted body, so a debugging dump of an
     * event or a decision cannot print a relay credential.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toRedactedArray();
    }
}
