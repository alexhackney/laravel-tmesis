<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayListResponse;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Data\Relays\RelayRecord;
use AlexHackney\Tmesis\Data\Relays\RelayStopResponse;
use AlexHackney\Tmesis\Resources\Relays;

/**
 * Relay reads and writes, answered from FakeTmesis's canned data.
 *
 * A recorded call carries the stream and relay ids and, where a destination was sent,
 * its REDACTION. Never a URL: the call log cannot hold a credential, and a consumer
 * asserting on a recorded createRelay is asserting on scheme, host and port.
 */
class FakeRelaysResource extends Relays
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function listRelays(string $streamId): RelayListResponse
    {
        $this->fake->record('listRelays', $this->node, ['streamId' => $streamId]);

        return $this->fake->relayList($streamId);
    }

    public function getRelay(string $streamId, string $relayId): RelayRecord
    {
        $this->fake->record('getRelay', $this->node, ['streamId' => $streamId, 'relayId' => $relayId]);

        return $this->fake->relay($streamId, $relayId);
    }

    public function createRelay(string $streamId, RelayInput $relay): RelayRecord
    {
        // Before the record, and the same guards the real resource runs: the fake
        // refuses exactly what the node refuses.
        self::guardRelayId($relay->id());

        $this->fake->record('createRelay', $this->node, [
            'streamId' => $streamId,
            'relayId' => $relay->id(),
            'url' => $relay->redacted(),
            'enabled' => $relay->enabled(),
        ]);

        return $this->fake->createdRelay($streamId, $relay);
    }

    public function updateRelay(string $streamId, string $relayId, RelayPatch $patch): RelayRecord
    {
        self::guardRelayPatch($patch);

        $this->fake->record('updateRelay', $this->node, [
            'streamId' => $streamId,
            'relayId' => $relayId,
            'url' => $patch->redacted(),
            'enabled' => $patch->enabled(),
        ]);

        return $this->fake->patchedRelay($streamId, $relayId, $patch);
    }

    public function deleteRelay(string $streamId, string $relayId): RelayStopResponse
    {
        $this->fake->record('deleteRelay', $this->node, ['streamId' => $streamId, 'relayId' => $relayId]);

        return $this->fake->deletedRelay($streamId, $relayId);
    }

    public function stopRelays(string $streamId): RelayStopResponse
    {
        $this->fake->record('stopRelays', $this->node, ['streamId' => $streamId]);

        return $this->fake->stoppedRelays($streamId);
    }
}
