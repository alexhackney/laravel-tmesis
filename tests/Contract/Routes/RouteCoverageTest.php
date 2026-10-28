<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Resources\Artifacts;
use AlexHackney\Tmesis\Resources\JobArtifacts;
use AlexHackney\Tmesis\Resources\Markers;
use AlexHackney\Tmesis\Resources\Node;
use AlexHackney\Tmesis\Resources\PreviewGrants;
use AlexHackney\Tmesis\Resources\Relays;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Resources\TranscodeJobs;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

pest()->group('routes');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/**
 * Every operation in the openapi document, with its method, path, milestone and
 * listener.
 *
 * `x-tmesis-listener` (since rc7) names a listener other than the admin API that
 * serves the route: `preview` for the four WHEP operations, which the preview
 * listener answers with no bearer token and an SDP body.
 *
 * `x-tmesis-local-only` (since v0.7.0) marks a route only the box's own CLI calls:
 * `leaveFleet`, which `tmesis leave` asks of the node it runs beside. It sits on the
 * admin listener with the admin token like any other row, and the extension is what
 * tells a control plane's client not to wrap it.
 *
 * @return list<array{id: string, method: string, path: string, milestone: ?string, listener: ?string, localOnly: bool}>
 */
function contractOperations(): array
{
    /** @var array<string, array<string, mixed>> $paths */
    $paths = ContractDir::readJson(ContractDir::openapi())['paths'];

    $out = [];

    foreach ($paths as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                continue;
            }

            $milestone = $operation['x-tmesis-milestone'] ?? null;
            $listener = $operation['x-tmesis-listener'] ?? null;

            $out[] = [
                'id' => $operation['operationId'],
                'method' => strtoupper((string) $method),
                'path' => (string) $path,
                'milestone' => is_string($milestone) && $milestone !== '' ? $milestone : null,
                'listener' => is_string($listener) && $listener !== '' ? $listener : null,
                'localOnly' => ($operation['x-tmesis-local-only'] ?? false) === true,
            ];
        }
    }

    usort($out, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

    return $out;
}

/**
 * Operation ids with no `x-tmesis-milestone`, no `x-tmesis-listener` and no
 * `x-tmesis-local-only`: the routes on the admin API this build must wrap.
 *
 * @return list<string>
 */
function implementedOperations(): array
{
    $out = [];

    foreach (contractOperations() as $operation) {
        if ($operation['milestone'] === null && $operation['listener'] === null && ! $operation['localOnly']) {
            $out[] = $operation['id'];
        }
    }

    return $out;
}

/**
 * Operation ids with an `x-tmesis-listener`: routes another listener serves, which
 * the admin client must NOT wrap (decision D1, rc7).
 *
 * The four WHEP operations at rc7. They are served on the PREVIEW listener
 * (`preview.listen`, default `:8889`), carry no bearer token, take an SDP offer and
 * answer `application/sdp`; the client is a WHEP player in a browser. A method here
 * would send an SDP with a bearer token to port 7000.
 *
 * @return list<string>
 */
function offListenerOperations(): array
{
    $out = [];

    foreach (contractOperations() as $operation) {
        if ($operation['listener'] !== null) {
            $out[] = $operation['id'];
        }
    }

    return $out;
}

/**
 * Operation ids marked `x-tmesis-local-only`: routes only the box's own CLI calls,
 * which the admin client must NOT wrap (tmesis v0.7.0, plan v0.7.0 WP7).
 *
 * `leaveFleet` at v0.7.0. A control plane never asks a node to leave over this route:
 * it learns of the leave from the node's DELETE on its enroll URL, which the fleet
 * receiver answers (`AlexHackney\Tmesis\Fleet\Http\EnrollController`). `armSealingDrain` at
 * v0.8.0 (`POST /api/v1/system/drain/seal`, `tmesis drain --seal`): it arms the NEXT
 * drain to seal every window, for a decommission; a control plane has no reason to
 * call it, and it is listed so a drain that still seals has an explanation.
 *
 * @return list<string>
 */
function localOnlyOperations(): array
{
    $out = [];

    foreach (contractOperations() as $operation) {
        if ($operation['localOnly']) {
            $out[] = $operation['id'];
        }
    }

    return $out;
}

/**
 * Operation ids with a milestone: the routes this build must NOT wrap.
 *
 * DERIVED, never written out. A route added upstream lands in one of these four
 * lists on its own, and a route whose milestone is removed upstream starts failing
 * the coverage test until it is wrapped, which is the whole point of the leg.
 *
 * @return list<string>
 */
function milestonedOperations(): array
{
    $out = [];

    foreach (contractOperations() as $operation) {
        if ($operation['milestone'] !== null) {
            $out[] = $operation['id'];
        }
    }

    return $out;
}

/**
 * Every public method on the four resource classes.
 *
 * @return list<string>
 */
function resourceMethods(): array
{
    $out = [];

    foreach ([Node::class, Streams::class, Relays::class, Artifacts::class, PreviewGrants::class, Markers::class, TranscodeJobs::class, JobArtifacts::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class || $method->getName() === '__construct') {
                continue;
            }

            $out[] = $method->getName();
        }
    }

    sort($out);

    return $out;
}

it('wraps every operation the contract does not gate behind a milestone', function (): void {
    $unwrapped = array_values(array_diff(implementedOperations(), resourceMethods()));

    expect($unwrapped)->toBe([], implode('', [
        'These operations carry no x-tmesis-milestone and no resource method wraps them: ',
        implode(', ', $unwrapped),
        '. Either the node shipped a route this package has not caught up with, or a ',
        'milestone was removed upstream.',
    ]));
});

it('wraps none of the operations the contract gates behind a milestone', function (): void {
    $wrapped = array_values(array_intersect(milestonedOperations(), resourceMethods()));

    expect($wrapped)->toBe([], 'These operations answer only 501 at this tag and must not be wrapped: '.implode(', ', $wrapped));
});

it('wraps none of the off-listener operations on the admin client', function (): void {
    $wrapped = array_values(array_intersect(offListenerOperations(), resourceMethods()));

    expect($wrapped)->toBe([], implode('', [
        'These operations are served by another listener and must not be wrapped on the admin client: ',
        implode(', ', $wrapped),
        '. A method here would send an SDP offer with a bearer token to the admin port.',
    ]));
});

it('wraps none of the local-only operations', function (): void {
    $wrapped = array_values(array_intersect(localOnlyOperations(), resourceMethods()));

    expect($wrapped)->toBe([], implode('', [
        'These operations are called only by the box\'s own CLI and must not be wrapped: ',
        implode(', ', $wrapped),
        '. A control plane that asked one would be driving the node from outside a route it does not own.',
    ]));
});

it('counts 56 wrapped, 4 off-listener, 2 local-only and 9 milestoned operations at this tag', function (): void {
    // The numbers are here so a change upstream is visible in the diff of this file
    // rather than only in a green run: the three lists above are derived, and a route
    // moving from one to another keeps them all satisfied.
    // 20 since v0.3.0: getStreamTranscript joined, the milestoned twelve unchanged.
    // Still 20 with the public health read: getPublicHealth is a second method on getHealth,
    // not a twenty-first operation. 30 since v0.5.0: the ten artifact operations,
    // all built at that tag, the milestoned twelve unchanged. 50 since v0.6.0: the
    // seventeen new operations (four preview grants, two markers, listTranscodeJobs,
    // the ten job-scoped artifact routes) plus the three transcode job routes that
    // left M3 (create, get, cancel), so 12 milestoned became 9; the four WHEP routes
    // are still off-listener. 63 in all. 64 since v0.7.0: leaveFleet, the one
    // local-only route, so the wrapped fifty are unchanged. 68 since v0.8.0: createMaster,
    // purgeArtifact and purgeJobArtifact, wrapped (53), and armSealingDrain, the
    // decommission's loopback route, the second local-only one. (The handover's "62 to
    // 66" counts without the two WHEP OPTIONS preflights; the document has 68
    // operationIds.) 71 since v0.9.0: setTranscodePause, getTranscodePause and
    // clearTranscodePause, the told jobs pause on one new path, none local-only, all
    // wrapped (56).
    expect(implementedOperations())->toHaveCount(56)
        ->and(offListenerOperations())->toHaveCount(4)
        ->and(localOnlyOperations())->toBe(['armSealingDrain', 'leaveFleet'])
        ->and(milestonedOperations())->toHaveCount(9)
        ->and(contractOperations())->toHaveCount(71);

    // The three unwrapped classes are disjoint: an off-listener route is served today,
    // elsewhere; a local-only route is served today, to the box itself.
    foreach (contractOperations() as $operation) {
        $classes = ($operation['listener'] !== null ? 1 : 0)
            + ($operation['milestone'] !== null ? 1 : 0)
            + ($operation['localOnly'] ? 1 : 0);

        expect($classes)->toBeLessThanOrEqual(1, "[{$operation['id']}] carries more than one of listener, milestone and local-only.");
    }
});

it('declares every resource method that is not an operation id', function (): void {
    // A short, written list. Anything else is a typo in a method name, which would
    // otherwise pass the coverage test above by being an operation nobody wraps.
    // getStreamTranscriptText is getStreamTranscript's vtt and srt renderings: one
    // operation, two return types, so two methods (plan R3). getPublicHealth is
    // getHealth asked with no bearer, held to the document's security by
    // the next test. The four all*() methods (v0.5.0) are the cursor walkers over the
    // four paged artifact reads: each one is its operation, asked page after page.
    // v0.6.0 adds the same five walkers over the paged job reads (LTWB).
    $declared = [
        'createStreamWithOutcome', 'getStreamTranscriptText', 'getPublicHealth',
        'allArtifacts', 'allArtifactInventory', 'allTransfers', 'allTransferParts',
        'allTranscodeJobs', 'allJobArtifacts', 'allJobArtifactInventory', 'allJobTransfers', 'allJobTransferParts',
    ];

    $extra = array_values(array_diff(resourceMethods(), implementedOperations(), $declared));

    expect($extra)->toBe([], 'Resource methods that are neither an operationId nor declared: '.implode(', ', $extra));
});

it('sends no bearer only where the operation allows the empty security requirement', function (): void {
    // getPublicHealth() is the one tokenless method on the admin client. It is legal
    // because getHealth's `security` lists `{}` beside `adminToken` (tmesis v0.2.0):
    // the node answers HealthPublic to nobody. Derived both ways: an admin operation
    // that grows `{}` upstream is named here, and one that loses it fails the method.
    /** @var array<string, array<string, mixed>> $paths */
    $paths = ContractDir::readJson(ContractDir::openapi())['paths'];

    $anonymous = [];

    foreach ($paths as $operations) {
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! is_string($operation['operationId'] ?? null) || isset($operation['x-tmesis-listener'])) {
                continue;
            }

            foreach ((array) ($operation['security'] ?? []) as $requirement) {
                if ($requirement === [] || $requirement === (object) []) {
                    $anonymous[] = $operation['operationId'];
                }
            }
        }
    }

    // The tokenless method on the admin client, and the operation it asks.
    $tokenless = ['getPublicHealth' => 'getHealth'];

    expect($anonymous)->toBe(array_values($tokenless), 'Admin operations that allow no bearer: '.implode(', ', $anonymous));
});

it('surfaces a milestoned route as NotImplementedException carrying its milestone', function (): void {
    $registry = app(NodeRegistry::class);
    $transport = new Transport($registry, $registry->get(TestCase::NODE));

    $milestoneByPath = [];

    foreach (contractOperations() as $operation) {
        if ($operation['milestone'] !== null) {
            $milestoneByPath[filledPath($operation['path'])] = $operation['milestone'];
        }
    }

    // ONE fake, answering from the path. Http::fake() called in a loop ADDS stubs
    // rather than replacing them, so a `*` registered on the first pass would answer
    // every later one and the whole leg would assert the first milestone thirteen
    // times.
    Http::fake(function (Request $request) use ($milestoneByPath): PromiseInterface {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $milestone = $milestoneByPath[$path] ?? null;

        return Http::response([
            'error' => [
                'code' => 'not_implemented',
                'message' => 'this build does not implement it; it lands at '.($milestone ?? 'nowhere'),
                'details' => null,
                'request_id' => 'req_01J8ZC',
                'milestone' => $milestone,
            ],
        ], 501);
    });

    foreach (contractOperations() as $operation) {
        if ($operation['milestone'] === null) {
            continue;
        }

        $path = filledPath($operation['path']);

        $call = match ($operation['method']) {
            'POST' => fn (): mixed => $transport->post($path),
            'DELETE' => fn (): mixed => $transport->delete($path),
            'PATCH' => fn (): mixed => $transport->patch($path, []),
            default => fn (): mixed => $transport->get($path),
        };

        try {
            $call();

            $this->fail("[{$operation['id']}] answered 501 and no exception was thrown.");
        } catch (NotImplementedException $e) {
            expect($e->milestone())->toBe(
                $operation['milestone'],
                "[{$operation['id']}] lost its milestone on the way through the client."
            );
        }
    }
});

/**
 * A contract path with its `{id}` placeholders filled, so a call is shaped like a
 * real one.
 */
function filledPath(string $path): string
{
    return preg_replace('/\{[a-zA-Z_]+\}/', 'show-2041-main', $path) ?? $path;
}
