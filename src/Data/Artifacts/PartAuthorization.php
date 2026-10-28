<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Artifacts;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The signed request for one multipart part. The openapi component PartAuthorization
 * (tmesis `v0.5.0`, `docs/API.md` 12a.4). The request inside is a credential; see
 * SignedRequest.
 */
final readonly class PartAuthorization
{
    private function __construct(
        private int $partNumber,
        private SignedRequest $request,
    ) {}

    public static function make(int $partNumber, SignedRequest $request): self
    {
        if ($partNumber < 1) {
            throw InvalidConfigurationException::invalid('parts.part_number', 'part numbers start at 1');
        }

        return new self($partNumber, $request);
    }

    public function partNumber(): int
    {
        return $this->partNumber;
    }

    public function request(): SignedRequest
    {
        return $this->request;
    }

    /**
     * The request body, credentials included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'part_number' => $this->partNumber,
            'request' => $this->request->toArray(),
        ];
    }
}
