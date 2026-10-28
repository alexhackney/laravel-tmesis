<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\HealthPublic;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Illuminate\Support\Facades\Http;

pest()->group('schemas-responses');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/**
 * The `$ref` component name for an operation's SUCCESS response, or null when the
 * operation has no response schema in the contract.
 *
 * 200, then 201, then 202: three writes never answer 200 on their success path.
 * `deleteStream` is a 202 while the seal runs, and `kick` and `takeover` are 202
 * because the session ends asynchronously.
 *
 * A `oneOf` answers more than one shape on the same status (getHealth since tmesis
 * v0.2.0: HealthFull to a bearer, HealthPublic to nobody). The leg validates the
 * branch the package DECODES, which is the branch named like the DTO the operation
 * maps to in wrappedOperations(); for getHealth that is HealthFull, the bearer's
 * answer. The other branch, HealthPublic, is what getPublicHealth() decodes
 * and is held by its own test below. A second oneOf resolves the same way with no
 * second case here. (A oneOf over a D5-renamed component would need the deviation map;
 * none exists today.)
 */
function componentFor(string $operationId): ?string
{
    /** @var array<string, mixed> $document */
    $document = ContractDir::readJson(ContractDir::openapi());

    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'];

    foreach ($paths as $operations) {
        foreach ($operations as $method => $operation) {
            if (! is_array($operation) || ($operation['operationId'] ?? null) !== $operationId) {
                continue;
            }

            foreach (['200', '201', '202'] as $status) {
                $schema = $operation['responses'][$status]['content']['application/json']['schema'] ?? null;

                if (! is_array($schema)) {
                    continue;
                }

                $ref = $schema['$ref'] ?? null;

                if (is_string($ref)) {
                    return basename($ref);
                }

                $branches = $schema['oneOf'] ?? null;

                if (! is_array($branches)) {
                    continue;
                }

                $dto = wrappedOperations()[$operationId][1] ?? null;
                $decoded = $dto === null ? null : substr((string) strrchr($dto, '\\'), 1);

                foreach ($branches as $branch) {
                    $ref = is_array($branch) ? ($branch['$ref'] ?? null) : null;

                    if (is_string($ref) && basename($ref) === $decoded) {
                        return $decoded;
                    }
                }
            }

            return null;
        }
    }

    return null;
}

/**
 * Run every accessor on a DTO and on every DTO it hands back.
 *
 * The envelope alone is not enough now that the nested blocks are typed: a typo in a
 * key name inside one of the 20 snapshot classes is invisible until something calls
 * the accessor holding it, and the fixture is the only place a real shape exists to
 * call it against.
 */
function runEveryAccessor(object $instance): void
{
    foreach (accessorsOf($instance) as $method) {
        $value = $instance->{$method}();

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_object($item) && str_starts_with($item::class, 'AlexHackney\\Tmesis\\Data\\')) {
                runEveryAccessor($item);
            }
        }
    }
}

it('validates the response fixture against its openapi component', function (string $operationId, string $dto): void {
    $component = componentFor($operationId);

    expect($component)->not->toBeNull(
        "No 200 response component for {$operationId}. Every operation this package wraps "
        .'must have one at v0.1.0-rc2; board gap G10 closed on 2026-09-07.'
    );

    $path = dirname(__DIR__, 2).'/Fixtures/responses/'.$operationId.'.json';
    expect(is_file($path))->toBeTrue("Missing response fixture for {$operationId}.");

    $json = (string) file_get_contents($path);
    $validator = SchemaValidator::forOpenApi();
    $pointer = '/components/schemas/'.$component;

    $validator->assertValid(
        json_decode($json, false),
        $pointer,
        "The {$operationId} fixture"
    );

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $instance = $dto::fromArray($decoded);

    $validator->assertValid(
        json_decode((string) json_encode($instance->toArray()), false),
        $pointer,
        "The {$operationId} round trip"
    );

    runEveryAccessor($instance);
})->with(wrappedOperations());

it('has a fixture for every operation the package wraps', function (): void {
    $files = glob(dirname(__DIR__, 2).'/Fixtures/responses/*.json') ?: [];
    $names = array_map(static fn (string $path): string => basename($path, '.json'), $files);
    sort($names);

    $expected = array_keys(wrappedOperations());
    sort($expected);

    expect($names)->toBe($expected);
});

it('decodes every fixture into its DTO and runs every accessor', function (string $operationId, string $dto): void {
    $path = dirname(__DIR__, 2).'/Fixtures/responses/'.$operationId.'.json';

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $instance = $dto::fromArray($decoded);

    runEveryAccessor($instance);

    expect($instance)->toBeInstanceOf($dto);
})->with(wrappedOperations());

it('declares the transcript 200 in three media types, the two text renderings as strings', function (): void {
    // Since tmesis v0.3.0 (API.md 9.5b). The application/json branch is the one the
    // fixture above validates against; vtt and srt are opaque documents the package
    // hands back verbatim from getStreamTranscriptText(), so all that can be held to
    // the contract is that the document still declares them, as plain strings.
    /** @var array<string, array<string, mixed>> $paths */
    $paths = ContractDir::readJson(ContractDir::openapi())['paths'];

    $content = $paths['/api/v1/streams/{id}/transcript']['get']['responses']['200']['content'] ?? null;

    expect($content)->toBeArray()
        ->and(array_keys($content))->toEqualCanonicalizing(['application/json', 'text/vtt', 'application/x-subrip'])
        ->and($content['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/TranscriptResponse']);

    foreach (Streams::TRANSCRIPT_TEXT_FORMATS as $format => $mediaType) {
        expect($content[$mediaType]['schema'] ?? null)->toBe(['type' => 'string'], "The {$format} rendering ({$mediaType}) is not declared as a string.");
    }
});

it('decodes both branches of the getHealth oneOf, the bearer one and the tokenless one', function (): void {
    // getHealth answers `oneOf [HealthFull, HealthPublic]` on its 200. The package
    // decodes each: getHealth() the first (the fixture test above), getPublicHealth()
    // the second. A third branch upstream fails here by name.
    /** @var array<string, array<string, mixed>> $paths */
    $paths = ContractDir::readJson(ContractDir::openapi())['paths'];

    $branches = array_map(
        static fn (array $branch): string => basename((string) $branch['$ref']),
        $paths['/api/v1/health']['get']['responses']['200']['content']['application/json']['schema']['oneOf'],
    );

    expect($branches)->toEqualCanonicalizing([
        substr((string) strrchr(HealthFull::class, '\\'), 1),
        substr((string) strrchr(HealthPublic::class, '\\'), 1),
    ]);

    // The tokenless body, DERIVED: the full fixture cut to the properties the
    // HealthPublic component declares, which is what the node answers to nobody.
    /** @var array<string, mixed> $component */
    $component = ContractDir::readJson(ContractDir::openapi())['components']['schemas']['HealthPublic'];
    $body = array_intersect_key(
        $this->fixtureJson('responses/getHealth.json'),
        (array) $component['properties'],
    );

    $validator = SchemaValidator::forOpenApi();
    $validator->assertValid(json_decode((string) json_encode($body), false), '/components/schemas/HealthPublic', 'The derived HealthPublic body');

    Http::fake(['*' => Http::response($body)]);

    $instance = Tmesis::node()->getPublicHealth();

    $validator->assertValid(
        json_decode((string) json_encode($instance->toArray()), false),
        '/components/schemas/HealthPublic',
        'The getPublicHealth round trip',
    );

    runEveryAccessor($instance);

    // HealthPublic is closed: the full body is NOT a HealthPublic, so the branch the
    // tokenless read decodes is a real distinction, not a subset check that anything
    // passes.
    expect($validator->isValid(json_decode((string) json_encode($this->fixtureJson('responses/getHealth.json')), false), '/components/schemas/HealthPublic'))
        ->toBeFalse();
});
