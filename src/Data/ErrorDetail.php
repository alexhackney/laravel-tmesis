<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data;

/**
 * One field-level problem inside a 422 body.
 *
 * The openapi component `ErrorDetail`.
 */
final readonly class ErrorDetail
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The dotted path of the offending field.
     */
    public function path(): string
    {
        return (string) ($this->raw['path'] ?? '');
    }

    /**
     * What is wrong with it. The literal string `unknown field` is how the node
     * reports a field it does not know; there is no `unknown_field` error code.
     */
    public function message(): string
    {
        return (string) ($this->raw['message'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
