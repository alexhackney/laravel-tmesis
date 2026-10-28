<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use AlexHackney\Tmesis\Data\Streams\StreamSpec;

/**
 * The ingest recording a node should run for a fleet stream: the API's model
 * (docs/API.md 9.4 and 15.3, tmesis R01), never an `s3://` URL.
 *
 * Checked through StreamSpec's own builder methods, so the refusals are the ones the
 * admin client already gives, in the node's own sentences: `retention_hours` 1 or more,
 * `destination` an absolute directory, `destination_ref` a name with no whitespace, and
 * never both. A document the node would reject as a whole revision is refused here,
 * at the host app's call site, instead of being reported back a heartbeat later.
 */
final readonly class DesiredRecording
{
    private function __construct(
        public bool $enabled,
        public int $retentionHours,
        public ?string $destination,
        public ?string $destinationRef,
    ) {}

    /**
     * @param  string|null  $destination  an absolute directory, or null for the node default
     * @param  string|null  $destinationRef  a storage.destinations name, or null for the node default
     */
    public static function make(bool $enabled, int $retentionHours = 72, ?string $destination = null, ?string $destinationRef = null): self
    {
        StreamSpec::make()
            ->ingestRetentionHours($retentionHours)
            ->ingestDestination($destination)
            ->ingestDestinationRef($destinationRef);

        return new self($enabled, $retentionHours, $destination, $destinationRef);
    }

    /**
     * The wire object. Both selectors are always present: null is how the fleet says
     * "the node default", and the node's validator requires the keys.
     *
     * @return array{enabled: bool, retention_hours: int, destination: string|null, destination_ref: string|null}
     */
    public function toWire(): array
    {
        return [
            'enabled' => $this->enabled,
            'retention_hours' => $this->retentionHours,
            'destination' => $this->destination,
            'destination_ref' => $this->destinationRef,
        ];
    }
}
