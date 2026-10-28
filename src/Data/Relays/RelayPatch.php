<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Relays;

use AlexHackney\Tmesis\Support\RelayFeed;
use AlexHackney\Tmesis\Support\RelayUrl;

/**
 * A partial change to one destination: the PATCH body.
 *
 * All three fields are optional and an omitted one is not mentioned, so
 * `RelayPatch::make(enabled: false)` parks a destination without touching its URL.
 * A patch that mentions nothing is refused here rather than sent, because the node
 * would answer 422 and the round trip says nothing the caller did not already know.
 *
 * `feed` (tmesis `v0.4.0`) is a new feed as on create (`RelayFeed`). On a RUNNING
 * show the destination is REPLACED, `relay.stopped` then `relay.started`, because a
 * different feed is a different stream to the same place; a receiver that keys "the
 * relay died" on `relay.stopped` alone will misread a feed change.
 *
 * The URL is a CREDENTIAL. Everything RelayInput says about that applies here.
 */
final readonly class RelayPatch
{
    /**
     * @param  array<string, mixed>  $fields
     */
    private function __construct(private array $fields) {}

    public static function make(?string $url = null, ?bool $enabled = null, ?string $feed = null): self
    {
        $fields = [];

        if ($url !== null) {
            $fields['url'] = $url;
        }

        if ($enabled !== null) {
            $fields['enabled'] = $enabled;
        }

        if ($feed !== null) {
            RelayFeed::guard($feed);
            $fields['feed'] = $feed;
        }

        return new self($fields);
    }

    /**
     * The new feed, or null when the patch does not change it.
     */
    public function feed(): ?string
    {
        $value = $this->fields['feed'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function mentionsNothing(): bool
    {
        return $this->fields === [];
    }

    public function enabled(): ?bool
    {
        $value = $this->fields['enabled'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * Scheme, host and port of the new destination, or null when the patch does not
     * change it. The only form of it that may be logged.
     */
    public function redacted(): ?string
    {
        $url = $this->fields['url'] ?? null;

        return is_string($url) ? RelayUrl::redact($url) : null;
    }

    /**
     * The request body, with the URL in full.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
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
        $fields = $this->fields;

        if (array_key_exists('url', $fields)) {
            $fields['url'] = $this->redacted();
        }

        return $fields;
    }
}
