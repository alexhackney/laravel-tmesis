<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Support;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Throwable;

/**
 * The generated schemas, as they ship inside this package.
 *
 * `tests/Fixtures/contract/` is deliberately NOT export-ignored: the artifacts reach a
 * consumer's vendor directory so `tmesis:doctor` can check they are present and so
 * `contract.validate_incoming` can validate a body against the schema this build was
 * pinned to. See `.gitattributes`.
 *
 * Validation needs `opis/json-schema`, which is a dev dependency on purpose (v0.2
 * decision D1): a validator has no business in every consumer's production tree. The
 * switch that turns validation on therefore REQUIRES it explicitly and says so, rather
 * than silently doing nothing when it is absent.
 */
final class ContractSchemas
{
    public static function root(): string
    {
        return dirname(__DIR__, 2).'/tests/Fixtures/contract';
    }

    /**
     * The bundled schema file for a name.
     *
     * The name is VALIDATED rather than interpolated: the one caller that does not
     * pass a constant is the webhook receiver, which reads it from `X-Tmesis-Event`,
     * and that header is outside the signature (the signature covers the timestamp
     * and the raw body, `docs/WEBHOOKS.md`). A replay inside the 300 second window
     * can therefore rewrite it, and `../` in a path this method builds would let a
     * rewritten header decide which file gets opened and parsed.
     *
     * The catalog's own shape is the whitelist: dot separated lowercase segments,
     * which every generated name matches (`stream.publish_rejected`,
     * `fleet.enroll.request`).
     */
    public static function path(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+\z/', $name) !== 1) {
            throw InvalidConfigurationException::invalid(
                'schema',
                "[{$name}] is not a contract schema name"
            );
        }

        return self::root()."/docs/contract/schemas/{$name}.schema.json";
    }

    /**
     * Whether a validator is installed at all.
     */
    public static function validatorInstalled(): bool
    {
        return class_exists(Validator::class);
    }

    /**
     * Validate a body against a bundled schema.
     *
     * Pass the RAW JSON whenever it is to hand, as both receivers do. A decoded PHP
     * array cannot tell an empty object from an empty list, so `"labels": {}` comes
     * back from a re-encode as `[]` and fails a `type: object` check on a body that
     * was valid on the wire. The array form is kept for callers that built the body
     * themselves, and carries that limitation.
     *
     * Returns null when it validates, and the first error path and keyword otherwise.
     * It NEVER throws on an invalid body: the caller is a receiver in the middle of
     * answering a node, and what to do about a body that failed its schema is the
     * caller's decision, not this one's.
     *
     * @param  array<string, mixed>|string  $payload  the raw JSON, or a decoded body
     */
    public static function validate(string $name, array|string $payload): ?string
    {
        if (! self::validatorInstalled()) {
            throw InvalidConfigurationException::invalid(
                'tmesis.contract.validate_incoming',
                'it is on but opis/json-schema is not installed. Run composer require --dev opis/json-schema, '
                .'or turn the switch off'
            );
        }

        $path = self::path($name);

        if (! is_file($path)) {
            throw InvalidConfigurationException::invalid(
                'tmesis.contract.validate_incoming',
                "it is on but the bundled schema [{$path}] is missing"
            );
        }

        try {
            $schema = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

            // Decoded to objects, not arrays: an empty `labels` map must stay an
            // object or it fails a type: object check as an empty array. Only the raw
            // JSON keeps that; a decoded array has already lost it.
            $json = is_string($payload) ? $payload : (string) json_encode($payload);
            $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return 'the body could not be re-encoded for validation: '.$e->getMessage();
        }

        $validator = new Validator;
        $validator->resolver()?->registerRaw($schema, "file://tmesis/{$name}");

        $result = $validator->validate($document, (object) ['$ref' => "file://tmesis/{$name}"]);

        if ($result->isValid()) {
            return null;
        }

        $error = $result->error();
        $formatted = $error === null ? [] : (new ErrorFormatter)->format($error);
        $first = array_key_first($formatted);

        return sprintf(
            '%s -> %s',
            $first === null ? '(root)' : $first,
            $first === null ? 'unknown' : implode('; ', (array) $formatted[$first]),
        );
    }
}
