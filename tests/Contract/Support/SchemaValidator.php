<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Contract\Support;

use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

/**
 * Validates a document against a contract schema, loaded from the resolved root.
 *
 * Documents are decoded with `json_decode($json, false)` for validation, so an empty
 * `labels` map stays an object rather than becoming an empty array and failing a
 * `type: object` check.
 */
final class SchemaValidator
{
    private function __construct(private readonly Validator $validator, private readonly string $uri) {}

    public static function forSchema(string $name): self
    {
        $path = ContractDir::schema($name);

        if (! is_file($path)) {
            throw new RuntimeException("The schema file [{$path}] does not exist.");
        }

        return self::load($path, "file://schema/{$name}");
    }

    public static function forOpenApi(): self
    {
        $path = ContractDir::openapi();

        if (! is_file($path)) {
            throw new RuntimeException("The openapi document [{$path}] does not exist.");
        }

        return self::load($path, 'file://contract/openapi.json');
    }

    private static function load(string $path, string $uri): self
    {
        $decoded = json_decode((string) file_get_contents($path), false);

        if (! $decoded instanceof \stdClass) {
            throw new RuntimeException("The schema file [{$path}] is not a JSON object.");
        }

        $validator = new Validator;
        // Registered under a file:// URI so `$ref: "#/$defs/..."` and
        // `#/components/schemas/...` pointers resolve inside the document.
        $validator->resolver()?->registerRaw($decoded, $uri);

        return new self($validator, $uri);
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Assert a document validates, rendering the first error path and keyword.
     */
    public function assertValid(mixed $document, ?string $pointer = null, string $context = ''): void
    {
        $schema = $pointer === null
            ? (object) ['$ref' => $this->uri]
            : (object) ['$ref' => $this->uri.'#'.$pointer];

        $result = $this->validator->validate($document, $schema);

        if ($result->isValid()) {
            PHPUnit::assertTrue(true);

            return;
        }

        $error = $result->error();
        $formatted = $error === null ? [] : (new ErrorFormatter)->format($error);
        $first = array_key_first($formatted);

        PHPUnit::fail(sprintf(
            '%s did not validate: %s -> %s',
            $context !== '' ? $context : 'The document',
            $first === null ? '(root)' : $first,
            $first === null ? 'unknown' : implode('; ', (array) $formatted[$first]),
        ));
    }

    public function isValid(mixed $document, ?string $pointer = null): bool
    {
        $schema = $pointer === null
            ? (object) ['$ref' => $this->uri]
            : (object) ['$ref' => $this->uri.'#'.$pointer];

        return $this->validator->validate($document, $schema)->isValid();
    }
}
