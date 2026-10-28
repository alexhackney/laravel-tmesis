<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayListResponse;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Data\Relays\RelayRecord;
use AlexHackney\Tmesis\Data\Relays\RelayStopResponse;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * Relays: this stream's forward destinations. Method names are the openapi
 * operationIds.
 *
 * **A destination URL is a credential.** It goes OUT in full because the node has to
 * dial it, and it comes back redacted to scheme, host and port. Nothing on this path
 * logs, records or persists an unredacted one: RelayRecord redacts again on the way
 * in, RelayInput exposes only `redacted()` for anything human-readable, and the
 * exception thrown for an unusable one names the destination and not the URL.
 *
 * Request shapes come from `internal/api/relays.go`: `openapi.json` carries no
 * request bodies at `v0.1.0-rc2` (board gap G9).
 */
class Relays
{
    /** The path segment of the scoped stop route, so it cannot be a destination id. */
    public const RESERVED_ID = 'stop';

    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /api/v1/streams/:id/relays.
     */
    public function listRelays(string $streamId): RelayListResponse
    {
        return RelayListResponse::fromArray(
            $this->transport->get($this->transport->path('/api/v1/streams/%s/relays', $streamId))
        );
    }

    /**
     * GET /api/v1/streams/:id/relays/:rid.
     */
    public function getRelay(string $streamId, string $relayId): RelayRecord
    {
        return RelayRecord::fromArray(
            $this->transport->get($this->transport->path('/api/v1/streams/%s/relays/%s', $streamId, $relayId))
        );
    }

    /**
     * POST /api/v1/streams/:id/relays: add a destination. It starts immediately
     * unless the input parks it.
     *
     * Supplying an id makes a re-POST idempotent: the node mints a ULID when there is
     * none. The record that comes back carries the REDACTED url, which is the only
     * form of it this package will hold.
     */
    public function createRelay(string $streamId, RelayInput $relay): RelayRecord
    {
        self::guardRelayId($relay->id());

        return RelayRecord::fromArray($this->transport->post(
            $this->transport->path('/api/v1/streams/%s/relays', $streamId),
            $relay->toArray(),
        )->body);
    }

    /**
     * PATCH /api/v1/streams/:id/relays/:rid: change a destination's URL, or park and
     * unpark it.
     *
     * A partial document, like every PATCH here: an omitted field is not mentioned.
     */
    public function updateRelay(string $streamId, string $relayId, RelayPatch $patch): RelayRecord
    {
        self::guardRelayPatch($patch);

        return RelayRecord::fromArray($this->transport->patch(
            $this->transport->path('/api/v1/streams/%s/relays/%s', $streamId, $relayId),
            $patch->toArray(),
        )->body);
    }

    /**
     * DELETE /api/v1/streams/:id/relays/:rid: stop and remove one destination.
     *
     * It answers a RelayStopResponse (`{stopped: 1}`) and NOT the record that was
     * removed, so both stop paths hand a caller the same body shape. The v0.2 build
     * plan's table said RelayRecord; the openapi document and the shipped handler
     * both say RelayStopResponse, and the artifact wins.
     *
     * A destination the node's own config file owns (`source: static`) cannot be
     * deleted through the API and answers 409 relay_immutable.
     */
    public function deleteRelay(string $streamId, string $relayId): RelayStopResponse
    {
        return RelayStopResponse::fromArray($this->transport->delete(
            $this->transport->path('/api/v1/streams/%s/relays/%s', $streamId, $relayId)
        )->body);
    }

    /**
     * POST /api/v1/streams/:id/relays/stop: stop every destination on this stream.
     *
     * Stops them, it does not delete them: they stay on the record and start again
     * with the next session. `stopped()` is how many were actually running, and zero
     * is a success.
     */
    public function stopRelays(string $streamId): RelayStopResponse
    {
        return RelayStopResponse::fromArray(
            $this->transport->post($this->transport->path('/api/v1/streams/%s/relays/stop', $streamId))->body
        );
    }

    /**
     * The reserved id, refused before the request rather than at the node.
     *
     * The node's own 422 for it is the only way to learn that such a destination
     * would be unaddressable forever: the mux gives .../relays/stop precedence over
     * .../relays/{rid}. Shared with FakeRelaysResource and with StreamSpec, so a
     * destination that the node would refuse is refused on every path that can carry
     * one.
     */
    public static function guardRelayId(?string $relayId): void
    {
        if ($relayId !== self::RESERVED_ID) {
            return;
        }

        throw InvalidConfigurationException::invalid(
            'relay.id',
            '"stop" is reserved: it is the stop route\'s path segment, so a destination with that id '
            .'could never be read, patched or deleted'
        );
    }

    /**
     * A patch that mentions nothing, refused before the request.
     *
     * Shared with FakeRelaysResource so a test that passes against the fake is not
     * one the node answers with a 422.
     */
    public static function guardRelayPatch(RelayPatch $patch): void
    {
        if (! $patch->mentionsNothing()) {
            return;
        }

        throw InvalidConfigurationException::invalid(
            'patch',
            'it mentions neither url nor enabled, and a patch that changes nothing is a 422 at the node'
        );
    }
}
