<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Tests\Contract\ContractDir;

pest()->group('schemas-responses');

/**
 * Every DTO reachable from a response fixture, keyed by class name.
 *
 * Reached by walking the object graph the fixture produces rather than by listing
 * classes: a nested block only appears here if a real payload actually carries it,
 * which is the difference between "the class exists" and "the class decodes".
 *
 * @param  array<class-string, object>  $found
 * @return array<class-string, object>
 */
function collectDataObjects(object $instance, array $found = []): array
{
    $found[$instance::class] ??= $instance;

    foreach (accessorsOf($instance) as $method) {
        $value = $instance->{$method}();

        foreach (is_array($value) ? $value : [$value] as $item) {
            // Every item, not only the first of its class: the 480p ring is the one
            // carrying drops, and stopping at the 720p ring would report DropSnapshot
            // as unreachable. The graph is a tree, so this terminates.
            if (is_object($item) && str_starts_with($item::class, 'AlexHackney\Tmesis\Data\\')) {
                $found = collectDataObjects($item, $found);
            }
        }
    }

    return $found;
}

/**
 * Every DTO the nine response fixtures decode into, envelopes and nested blocks.
 *
 * @return array<class-string, object>
 */
function decodedResponseObjects(): array
{
    $found = [];

    foreach (wrappedOperations() as [$operationId, $dto]) {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/responses/'.$operationId.'.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $found = collectDataObjects($dto::fromArray($decoded), $found);
    }

    return $found;
}

it('tolerates an unknown field on every DTO a response fixture decodes into', function (): void {
    // A node newer than the pin sends a key this package has never heard of. Every
    // schema and component is additionalProperties:false, so the CONTRACT SUITE is
    // where that has to fail; a running consumer must keep decoding. See the v0.2
    // plan, D1 rule 2.
    foreach (decodedResponseObjects() as $class => $instance) {
        /** @var array<string, mixed> $payload */
        $payload = $instance->toArray();
        $payload['tmesis_field_from_a_newer_node'] = ['nested' => true];

        $tolerant = $class::fromArray($payload);

        expect($tolerant->toArray())
            ->toHaveKey('tmesis_field_from_a_newer_node', ['nested' => true], "{$class} dropped an unknown field from toArray().");

        foreach (accessorsOf($tolerant) as $method) {
            $tolerant->{$method}();
        }
    }
})->group('schemas-responses');

it('decodes all twenty-three snapshot components from a real response body', function (): void {
    $decoded = array_keys(decodedResponseObjects());

    $expected = [];

    foreach (['Node/Stats' => 'Node\Stats', 'Streams/Stats' => 'Streams\Stats'] as $dir => $namespace) {
        $files = glob(dirname(__DIR__, 3).'/src/Data/'.$dir.'/*.php') ?: [];

        foreach ($files as $file) {
            $expected[] = 'AlexHackney\Tmesis\Data\\'.$namespace.'\\'.basename($file, '.php');
        }
    }

    sort($expected);

    // 22 at rc7: PreviewSnapshot, reached through the getStreamStats fixture's preview
    // (21 at rc5, when SourceSnapshot joined through its source). 23 at v0.3.0:
    // CaptionSnapshot, through the fixture's captions in the object form.
    expect($expected)->toHaveCount(23)
        ->and(array_values(array_diff($expected, $decoded)))->toBe(
            [],
            'A snapshot class no response fixture reaches. Give it a fixture rather than a unit test: '
            .'the fixture is the only place the real shape exists.'
        );
});

it('reads the same contract root as the rest of the leg', function (): void {
    expect(ContractDir::root())->toBeString()->not->toBe('');
});
