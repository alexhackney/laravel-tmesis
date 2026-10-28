<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The whole error envelope: the openapi component `ErrorResponse`.
 */
final readonly class ErrorResponse
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(private array $raw, private ErrorBody $error) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $error = $data['error'] ?? [];

        /** @var array<string, mixed> $error */
        $error = is_array($error) ? $error : [];

        return new self($data, ErrorBody::fromArray($error));
    }

    /**
     * Decode a response body that may not be the envelope at all.
     *
     * A proxy's HTML error page, an empty body, or a body from something that is not
     * the node all return null, and the caller maps on status alone. Nothing here
     * throws: a failure to decode an error must never replace the error.
     */
    public static function tryFromResponse(Response $response): ?self
    {
        try {
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['error']) || ! is_array($decoded['error'])) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    public function error(): ErrorBody
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
