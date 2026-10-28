<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Keys\StreamId;

/**
 * One fleet-owned stream in a desired state (docs/API.md 15.3).
 *
 * These are the fleet's fields on the node's row (tmesis D5): `min_key_epoch`, `labels`,
 * the ingest recording and the fleet relays. Everything else on the row (ladder,
 * segment seconds, captions, preview) stays the admin API's, and an API write to a
 * fleet field is the node's `409 fleet_owned`.
 *
 * The relays are a FULL replacement of this stream's fleet relays: an empty list
 * removes them, it does not mean unchanged.
 */
final readonly class DesiredStream
{
    /**
     * @param  array<string, string>  $labels
     * @param  list<DesiredRelay>  $relays  sorted by id
     */
    private function __construct(
        public string $id,
        public int $minKeyEpoch,
        public array $labels,
        public DesiredRecording $recording,
        public array $relays,
    ) {}

    /**
     * @param  array<string, string>  $labels  the API bounds apply (16 keys, 64-byte keys, 256-byte values)
     * @param  list<DesiredRelay>  $relays
     */
    public static function make(string $id, DesiredRecording $recording, int $minKeyEpoch = 0, array $labels = [], array $relays = []): self
    {
        if (! StreamId::isValid($id)) {
            throw InvalidConfigurationException::invalid('streams[].id', "[{$id}] is not a stream id (^[a-z0-9][a-z0-9_-]{0,127}$)");
        }

        StreamSpec::make()->minKeyEpoch($minKeyEpoch)->labels($labels);

        $byId = [];

        foreach ($relays as $relay) {
            if (isset($byId[$relay->id])) {
                throw InvalidConfigurationException::invalid("streams.{$id}.relays", "the relay id [{$relay->id}] appears twice");
            }

            $byId[$relay->id] = $relay;
        }

        ksort($byId, SORT_STRING);
        ksort($labels, SORT_STRING);

        return new self($id, $minKeyEpoch, $labels, $recording, array_values($byId));
    }

    /**
     * The wire object. `labels` is an OBJECT even when empty: a PHP empty array
     * encodes as a JSON list, which the node's validator refuses.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'min_key_epoch' => $this->minKeyEpoch,
            'labels' => (object) $this->labels,
            'recording' => $this->recording->toWire(),
            'relays' => array_map(fn (DesiredRelay $relay): array => $relay->toWire(), $this->relays),
        ];
    }
}
