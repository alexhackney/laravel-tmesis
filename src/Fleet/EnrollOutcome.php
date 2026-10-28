<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Fleet;

use SensitiveParameter;
use SensitiveParameterValue;

/**
 * An accepted enrollment: the identity to answer with and the fresh fleet token,
 * wrapped so nothing but the answer's own encoding can read it.
 *
 * @internal
 */
final readonly class EnrollOutcome
{
    private SensitiveParameterValue $fleetToken;

    /**
     * @param  array<string, mixed>|null  $desiredState  the wire object, or null
     */
    public function __construct(
        public string $nodeId,
        public string $nodeName,
        #[SensitiveParameter] string $fleetToken,
        public ?array $desiredState,
        public bool $repeat,
    ) {
        $this->fleetToken = new SensitiveParameterValue($fleetToken);
    }

    public function fleetToken(): string
    {
        return $this->fleetToken->getValue();
    }

    /**
     * The `fleet.enroll.response` body.
     *
     * @return array<string, mixed>
     */
    public function toResponse(string $heartbeatUrl): array
    {
        return [
            'heartbeat_url' => $heartbeatUrl,
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'fleet_token' => $this->fleetToken(),
            'desired_state' => $this->desiredState,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['node_id' => $this->nodeId, 'node_name' => $this->nodeName, 'fleet_token' => '[redacted]', 'repeat' => $this->repeat];
    }
}
