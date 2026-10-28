<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Relays;

use AlexHackney\Tmesis\Support\RelayFeed;
use AlexHackney\Tmesis\Support\RelayUrl;

/**
 * One destination to forward to: the POST body of a relay, and an entry in a
 * StreamSpec's relay set.
 *
 * **The URL is a CREDENTIAL.** An SRT passphrase or an RTMP stream key rides in it,
 * and this is the one object in the package that holds an unredacted one, because the
 * node needs it to dial. So:
 *
 *  - `redacted()` is what goes in a log line, an exception, or a recorded call.
 *  - There is no `__toString`, and `__debugInfo` redacts (a `var_dump()` shows private
 *    properties without it), so an interpolation, a `var_dump()` or a `print_r()`
 *    cannot leak it by accident. Symfony VarDumper's `dump()`/`dd()`, `var_export()`,
 *    an `(array)` cast and `serialize()` DO show the real URL: the property is a
 *    plain string (unlike SignedRequest's, which is wrapped).
 *  - `toArray()` is the request body and carries the real value. Nothing but the
 *    outbound request may call it.
 *
 * `enabled` is null when it was not set, which is not the same as false: absent means
 * "start it", which is what POSTing a destination asks for, and an explicit false adds
 * it parked.
 *
 * `feed` (tmesis `v0.4.0`, 4.2 / EDGE-5) is what the destination is sent: `auto`
 * (the node's default when omitted), `ingest`, or `rung:<name>`; `RelayFeed` has the
 * grammar and the three constructors. Null sends nothing and the node applies
 * `auto`. The grammar is checked here; whether the rung exists is the node's `422`.
 */
final readonly class RelayInput
{
    private function __construct(
        private string $url,
        private ?string $id,
        private ?bool $enabled,
        private ?string $feed,
    ) {}

    /**
     * @param  string  $url  srt://, rtmp:// or rtmps://
     * @param  string|null  $id  the node mints a ULID when this is null; supplying one
     *                           makes a re-POST idempotent. `stop` is refused: it is
     *                           the stop route's path segment
     * @param  bool|null  $enabled  null adds it started, false adds it parked
     * @param  string|null  $feed  `auto`, `ingest` or `rung:<name>` (RelayFeed); null
     *                             sends nothing, which the node reads as `auto`
     */
    public static function make(string $url, ?string $id = null, ?bool $enabled = null, ?string $feed = null): self
    {
        if ($feed !== null) {
            RelayFeed::guard($feed);
        }

        return new self($url, $id, $enabled, $feed);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function enabled(): ?bool
    {
        return $this->enabled;
    }

    /**
     * The feed as it will be sent, or null when the node's default (`auto`) applies.
     */
    public function feed(): ?string
    {
        return $this->feed;
    }

    /**
     * Scheme, host and port. The only form of this destination that may be logged.
     */
    public function redacted(): string
    {
        return RelayUrl::redact($this->url);
    }

    /**
     * The request body, with the URL in full.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = ['url' => $this->url];

        if ($this->id !== null) {
            $body['id'] = $this->id;
        }

        if ($this->enabled !== null) {
            $body['enabled'] = $this->enabled;
        }

        if ($this->feed !== null) {
            $body['feed'] = $this->feed;
        }

        return $body;
    }

    /**
     * What `var_dump()` and `print_r()` show: the redacted URL only. NOT what
     * Symfony VarDumper's `dump()`/`dd()` shows: it casts to an array and prints the
     * private properties, the real URL included, next to this. `var_export()`, an
     * `(array)` cast and `serialize()` print the real URL too.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'url' => $this->redacted(),
            'id' => $this->id,
            'enabled' => $this->enabled,
            'feed' => $this->feed,
        ];
    }
}
