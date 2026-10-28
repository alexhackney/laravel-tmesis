<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Tests\Contract;

use RuntimeException;

/**
 * Resolves where the contract artifacts are read from.
 *
 * Two roots, and every contract test runs against both:
 *
 *  - the bundled copies under `tests/Fixtures/contract/` (the default), and
 *  - a tmesis checkout, when `TMESIS_CONTRACT_DIR` points at one.
 *
 * The variable is read with getenv() rather than through config() or env(), and
 * deliberately has no phpunit `<env>` entry: tmesis CI sets it in the process
 * environment, and a value baked into phpunit.xml would silently win over it. The
 * name is coordinated across both repositories. Do not rename it.
 */
final class ContractDir
{
    public static function root(): string
    {
        $external = getenv('TMESIS_CONTRACT_DIR');

        if (is_string($external) && trim($external) !== '') {
            return rtrim(trim($external), '/');
        }

        return dirname(__DIR__).'/Fixtures/contract';
    }

    public static function isExternal(): bool
    {
        $external = getenv('TMESIS_CONTRACT_DIR');

        return is_string($external) && trim($external) !== '';
    }

    /**
     * What the run should print once per contract file, so its output says which root
     * it used.
     */
    public static function describe(): string
    {
        return self::isExternal()
            ? 'contract root: TMESIS_CONTRACT_DIR='.self::root()
            : 'contract root: bundled ('.self::root().')';
    }

    public static function openapi(): string
    {
        return self::root().'/docs/contract/openapi.json';
    }

    public static function schema(string $name): string
    {
        return self::root()."/docs/contract/schemas/{$name}.schema.json";
    }

    /**
     * Every schema name, without the `.schema.json` suffix, sorted.
     *
     * @return list<string>
     */
    public static function schemaFiles(): array
    {
        $files = glob(self::root().'/docs/contract/schemas/*.schema.json');

        if ($files === false) {
            throw new RuntimeException('No schema directory under ['.self::root().'].');
        }

        $names = array_map(
            static fn (string $path): string => basename($path, '.schema.json'),
            $files,
        );

        sort($names);

        return array_values($names);
    }

    /**
     * A generated delivery body, `docs/contract/examples/{name}.json`: one per
     * registered event plus `publish.verify`, written by the node from the same types
     * its emitter uses. The tests read these in place of hand-derived payloads.
     */
    public static function example(string $name): string
    {
        return self::root()."/docs/contract/examples/{$name}.json";
    }

    /**
     * Every example name, without the `.json` suffix, sorted.
     *
     * @return list<string>
     */
    public static function exampleFiles(): array
    {
        $files = glob(self::root().'/docs/contract/examples/*.json');

        if ($files === false || $files === []) {
            throw new RuntimeException('No examples directory under ['.self::root().'].');
        }

        $names = array_map(
            static fn (string $path): string => basename($path, '.json'),
            $files,
        );

        sort($names);

        return array_values($names);
    }

    /**
     * The event schema names: every schema minus the synchronous hook's request and
     * response, the keyterms hook's response (v0.8.0) and the four `fleet.*`
     * documents, none of which is a webhook delivery.
     *
     * @return list<string>
     */
    public static function eventSchemaFiles(): array
    {
        return array_values(array_filter(
            self::schemaFiles(),
            static fn (string $name): bool => ! in_array($name, [...self::HOOK_SCHEMAS, ...self::KEYTERMS_SCHEMAS], true)
                && ! str_starts_with($name, 'fleet.'),
        ));
    }

    /**
     * The keyterms hook's one schema (tmesis v0.8.0): the RESPONSE the package's route
     * answers. The request is a GET with no body, so it has no schema, and the response
     * has no generated example (test/vectors/keyterms.json holds the worked ones).
     */
    public const KEYTERMS_SCHEMAS = ['keyterms.hook.response'];

    /**
     * The synchronous hook's two schemas (and examples): the signed request the node
     * sends, and since v0.6.0 the response the control plane answers with.
     */
    public const HOOK_SCHEMAS = ['publish.verify', 'publish.verify.response'];

    /**
     * A role template, `docs/contract/templates/{file}` (v0.6.0, handover 3.3).
     */
    public static function template(string $file): string
    {
        return self::root()."/docs/contract/templates/{$file}";
    }

    /**
     * Every `config.<role>.yml` template file name, sorted.
     *
     * @return list<string>
     */
    public static function templateFiles(): array
    {
        $files = glob(self::root().'/docs/contract/templates/*.yml');

        if ($files === false || $files === []) {
            throw new RuntimeException('No templates directory under ['.self::root().'].');
        }

        $names = array_map(static fn (string $path): string => basename($path), $files);

        sort($names);

        return array_values($names);
    }

    /**
     * The templates' `MD5SUMS`, file name to md5, sorted by file name.
     *
     * @return array<string, string>
     */
    public static function templateSums(): array
    {
        $path = self::template('MD5SUMS');

        if (! is_file($path)) {
            throw new RuntimeException("The contract file [{$path}] does not exist.");
        }

        $sums = [];

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (preg_match('/^([0-9a-f]{32})\s+\*?(\S+)$/', trim($line), $row) === 1) {
                $sums[$row[2]] = $row[1];
            }
        }

        ksort($sums);

        return $sums;
    }

    public static function vectors(string $name): string
    {
        return self::root()."/test/vectors/{$name}.json";
    }

    /**
     * Read and decode a contract file.
     *
     * Throws a plain RuntimeException naming the path, so a wrong TMESIS_CONTRACT_DIR
     * fails loudly instead of skipping.
     *
     * @return array<string, mixed>
     */
    public static function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("The contract file [{$path}] does not exist.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("The contract file [{$path}] is not valid JSON.");
        }

        /** @var array<string, mixed> */
        return $decoded;
    }
}
