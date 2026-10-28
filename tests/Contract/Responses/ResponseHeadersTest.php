<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

pest()->group('schemas-responses');

/**
 * Every REQUIRED response header in the document, and what this package does with it.
 *
 * Keyed `operationId status Header`. The walk below derives the set from the document
 * and holds it EQUAL to these keys, both directions, so a required header added
 * upstream is a red test naming it, and one removed upstream leaves an entry to
 * delete. Three at tmesis v0.3.0, five at v0.4.0, six at v0.5.0 (the transfer plan's
 * `Location`); the four WHEP ones are the preview
 * listener's, which the admin client never calls (decision D1, rc7), and are here
 * because the package documents them to the browser player that does. The three
 * node headers v0.4.0 made required on every admin response (`X-Request-Id`,
 * `X-Tmesis-Node`, `X-Tmesis-Version`) and `WWW-Authenticate`
 * on every admin 401 are declared ONCE as shared `components.headers` and referenced
 * per response; the walk below reads inline `required` only, so they are not in
 * this list, and the per-listener test further down holds them instead.
 * `Retry-After` is on the WHEP 409 and 503 only, never on an admin row (API.md
 * 6.2's "503: Retry-After set" was false on admin).
 *
 * @var array<string, string>
 */
const REQUIRED_RESPONSE_HEADERS = [
    'createStream 201 Location' => 'not read: the id is the caller\'s own, and the record in the body carries it; '
        .'the leg holds the value to the path getStream() reads, so following it and calling getStream() agree',
    'replaceTransferPlan 201 Location' => 'not read: PlanStatus::$created carries the 201, and the value is the '
        .'transfer-plan path getTransferPlan() reads for the same stream and generation, which the leg holds',
    'replaceJobTransferPlan 201 Location' => 'not read: PlanStatus::$created carries the 201, and the value is the '
        .'job transfer-plan path getJobTransferPlan() reads for the same job and generation (v0.6.0)',
    'createTranscodeJob 202 Location' => 'not read: the JobAccepted in the body carries the id, and the value is the '
        .'job path getTranscodeJob() reads for it (tmesis v0.6.0 tag); the leg holds the value to that path',
    'createMaster 202 Location' => 'not read: ArtifactStatus::$created carries the 202, and the value is the '
        .'artifact path getArtifact() reads with the generation id the body already carries (tmesis v0.8.0)',
    'whepJoin 201 Location' => 'off-listener: the WHEP session resource a player DELETEs to leave; never on the admin client',
    'whepJoin 201 X-Tmesis-Stream-State' => 'off-listener: live or held, a snapshot at answer time, documented on '
        .'PreviewMonitorRecord and Streams for the player that reads it',
    'whepJoin 409 Retry-After' => 'off-listener: the player that got not_live or a monitor cap waits this long before '
        .'binding again; the admin client never sees it (v0.4.0)',
    'whepJoin 503 Retry-After' => 'off-listener: the preview listener is not ready; same player rule (v0.4.0)',
];

/**
 * The required response headers the document declares, across every operation.
 *
 * `pointer` is the header's schema inside the document, so a value validates against
 * the document itself rather than a copy of the schema taken here.
 *
 * @return array<string, array{operation: string, status: string, header: string, schema: array<string, mixed>, pointer: string, listener: ?string}>
 */
function requiredResponseHeaders(): array
{
    /** @var array<string, array<string, mixed>> $paths */
    $paths = ContractDir::readJson(ContractDir::openapi())['paths'];

    $out = [];

    // A JSON pointer token (RFC 6901), then percent-encoded for the URI fragment:
    // opis reads a bare `{id}` in a $ref as a URI template and resolves nothing.
    $escape = static fn (string $token): string => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $token));

    foreach ($paths as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                continue;
            }

            foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
                foreach ((array) ($response['headers'] ?? []) as $name => $header) {
                    if (($header['required'] ?? false) !== true) {
                        continue;
                    }

                    $listener = $operation['x-tmesis-listener'] ?? null;

                    $out["{$operation['operationId']} {$status} {$name}"] = [
                        'operation' => $operation['operationId'],
                        'status' => (string) $status,
                        'header' => (string) $name,
                        'schema' => (array) ($header['schema'] ?? []),
                        'pointer' => '/paths/'.$escape((string) $path).'/'.$method.'/responses/'.$status.'/headers/'.$escape((string) $name).'/schema',
                        'listener' => is_string($listener) ? $listener : null,
                    ];
                }
            }
        }
    }

    ksort($out);

    return $out;
}

it('accounts for every required response header in the document, and for no other', function (): void {
    $derived = array_keys(requiredResponseHeaders());
    $declared = array_keys(REQUIRED_RESPONSE_HEADERS);
    sort($declared);

    expect(array_values(array_diff($derived, $declared)))->toBe([], 'Required response headers the package does not account for')
        ->and(array_values(array_diff($declared, $derived)))->toBe([], 'Accounted-for headers the document no longer requires');

    foreach (REQUIRED_RESPONSE_HEADERS as $key => $reason) {
        expect(strlen($reason))->toBeGreaterThan(20, "The entry for {$key} does not say what the package does with it.");
    }

    // Visible in the diff of this file when upstream moves, like the routes leg's
    // counts: three on admin operations, four on the preview listener (v0.4.0 added
    // the two Retry-After rows; three in all at v0.3.0; v0.5.0 the transfer plan's
    // Location; v0.6.0 the job transfer plan's Location; v0.8.0 the master's Location;
    // v0.9.0 none: the three jobs pause routes reference only the shared headers).
    $admin = array_filter(requiredResponseHeaders(), static fn (array $h): bool => $h['listener'] === null);

    expect(requiredResponseHeaders())->toHaveCount(9)
        ->and($admin)->toHaveCount(5);
});

it('holds the shared node headers on every admin response and on no WHEP row (v0.4.0)', function (): void {
    /** @var array<string, array<string, mixed>> $paths */
    $paths = ContractDir::readJson(ContractDir::openapi())['paths'];

    $shared = ['X-Request-Id', 'X-Tmesis-Node', 'X-Tmesis-Version'];

    $adminResponses = 0;
    $whepResponses = 0;
    $unauthorized = 0;

    foreach ($paths as $operations) {
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                continue;
            }

            $whep = isset($operation['x-tmesis-listener']);

            foreach ((array) ($operation['responses'] ?? []) as $status => $response) {
                $referenced = [];

                foreach ((array) ($response['headers'] ?? []) as $name => $header) {
                    if (is_array($header) && isset($header['$ref'])) {
                        $referenced[] = $name;
                    }
                }

                $key = "{$operation['operationId']} {$status}";

                if ($whep) {
                    // The preview listener is a different server with no admin
                    // token, and the node names none of the three there.
                    $whepResponses++;
                    expect(array_intersect($shared, $referenced))->toBe([], "[{$key}] is a WHEP row carrying a node header.");

                    continue;
                }

                $adminResponses++;
                expect(array_values(array_diff($shared, $referenced)))->toBe([], "[{$key}] lacks a shared node header.");

                if ((string) $status === '401') {
                    $unauthorized++;
                    expect(in_array('WWW-Authenticate', $referenced, true))->toBeTrue("[{$key}] has no WWW-Authenticate.");
                }
            }
        }
    }

    // Every admin operation declares at least a success and a default row, the
    // public health probe has no 401, and the four WHEP routes are the only rows
    // outside the rule; the counts keep the walk honest rather than vacuous. 41
    // 401 rows at v0.5.0 (the ten artifact operations each declare one); 58 since
    // v0.6.0, one on each of the 17 new operations (the job list, the ten job
    // artifact routes, four preview grant routes, two marker routes). 59 since
    // v0.7.0: leaveFleet's, which is local-only but on the admin listener with the
    // admin token like any other row, so the header rule holds it too. 63 since v0.8.0:
    // createMaster, purgeArtifact, purgeJobArtifact and the local-only armSealingDrain.
    // 66 since v0.9.0: the three jobs pause routes, each declaring a 401 that
    // references WWW-Authenticate and the three node headers like every admin row.
    expect($adminResponses)->toBeGreaterThan(100)
        ->and($whepResponses)->toBeGreaterThan(8)
        ->and($unauthorized)->toBe(66);
});

it('declares every required header with a string schema the document resolves', function (): void {
    $validator = SchemaValidator::forOpenApi();

    foreach (requiredResponseHeaders() as $key => $header) {
        expect($header['schema']['type'] ?? null)->toBe('string', "[{$key}] has no string schema.")
            ->and($validator->isValid(42, $header['pointer']))->toBeFalse("[{$key}] accepts a number, so its pointer resolved to nothing.");
    }
});

it('names the stream state header values the package documents, live and held', function (): void {
    // PreviewMonitorRecord and Streams both say `live|held`; a third value upstream
    // fails here and points at those two docblocks.
    expect(requiredResponseHeaders()['whepJoin 201 X-Tmesis-Stream-State']['schema']['enum'] ?? null)
        ->toBe(['live', 'held']);
});

it('reads a createStream 201 carrying its Location, and the Location is the path getStream reads', function (): void {
    // The one required header on an admin operation. The client does not read it,
    // so what is held is that a 201 carrying it decodes, that the value validates
    // against the header's schema, and that it names the same resource the client
    // builds for the same id.
    $header = requiredResponseHeaders()['createStream 201 Location'];
    $location = '/api/v1/streams/show-2041-main';

    SchemaValidator::forOpenApi()->assertValid($location, $header['pointer'], 'The createStream Location');

    Http::fake([
        '*' => Http::sequence()
            ->push($this->fixtureJson('responses/createStream.json'), 201, [$header['header'] => $location])
            ->push($this->fixtureJson('responses/getStream.json')),
    ]);

    [$record, $created] = Tmesis::node()->streams()->createStreamWithOutcome('show-2041-main');

    expect($created)->toBeTrue()
        ->and($record->id())->toBe('show-2041-main');

    Tmesis::node()->getStream('show-2041-main');

    $recorded = Http::recorded();

    /** @var Request $read */
    $read = $recorded[1][0];

    expect($recorded[0][1]->header($header['header']))->toBe($location)
        ->and(parse_url($read->url(), PHP_URL_PATH))->toBe($location);
});

it('reads a replaceTransferPlan 201 carrying its Location, and the Location is the path getTransferPlan reads', function (): void {
    // The second required header on an admin operation (tmesis v0.5.0). As with
    // createStream: the 201 decodes with the header present, the value validates
    // against the header's schema, and it names the resource the client reads back.
    $header = requiredResponseHeaders()['replaceTransferPlan 201 Location'];
    $location = '/api/v1/streams/show-2041-main/artifacts/01J8ZCK5R7V2QNX8RW4TKB6PD2/transfer-plan';

    SchemaValidator::forOpenApi()->assertValid($location, $header['pointer'], 'The replaceTransferPlan Location');

    Http::fake([
        '*' => Http::sequence()
            ->push($this->fixtureJson('responses/replaceTransferPlan.json'), 201, [$header['header'] => $location])
            ->push($this->fixtureJson('responses/getTransferPlan.json')),
    ]);

    $plan = Tmesis::node()->artifacts()->replaceTransferPlan(
        'show-2041-main',
        '01J8ZCK5R7V2QNX8RW4TKB6PD2',
        PlanRequest::make(str_repeat('ab', 32), 0),
    );

    expect($plan->created)->toBeTrue()
        ->and($plan->revision())->toBe(1);

    Tmesis::node()->artifacts()->getTransferPlan('show-2041-main', '01J8ZCK5R7V2QNX8RW4TKB6PD2');

    $recorded = Http::recorded();

    /** @var Request $read */
    $read = $recorded[1][0];

    expect($recorded[0][1]->header($header['header']))->toBe($location)
        ->and(parse_url($read->url(), PHP_URL_PATH))->toBe($location);
});

it('reads a replaceJobTransferPlan 201 carrying its Location, and the Location is the path getJobTransferPlan reads', function (): void {
    // The job-scoped twin of the test above (tmesis v0.6.0): the same body and the
    // same header, under /api/v1/transcode/jobs/{id}/artifacts.
    $header = requiredResponseHeaders()['replaceJobTransferPlan 201 Location'];
    $location = '/api/v1/transcode/jobs/job_01J8ZCA2R7F3H9NQ4KTX8VD5YB/artifacts/01J8ZCK5R7V2QNX8RW4TKB6PD2/transfer-plan';

    SchemaValidator::forOpenApi()->assertValid($location, $header['pointer'], 'The replaceJobTransferPlan Location');

    Http::fake([
        '*' => Http::sequence()
            ->push($this->fixtureJson('responses/replaceJobTransferPlan.json'), 201, [$header['header'] => $location])
            ->push($this->fixtureJson('responses/getJobTransferPlan.json')),
    ]);

    $plan = Tmesis::node()->jobArtifacts()->replaceJobTransferPlan(
        'job_01J8ZCA2R7F3H9NQ4KTX8VD5YB',
        '01J8ZCK5R7V2QNX8RW4TKB6PD2',
        PlanRequest::make(str_repeat('ab', 32), 0),
    );

    expect($plan->created)->toBeTrue();

    Tmesis::node()->jobArtifacts()->getJobTransferPlan('job_01J8ZCA2R7F3H9NQ4KTX8VD5YB', '01J8ZCK5R7V2QNX8RW4TKB6PD2');

    $recorded = Http::recorded();

    /** @var Request $read */
    $read = $recorded[1][0];

    expect($recorded[0][1]->header($header['header']))->toBe($location)
        ->and(parse_url($read->url(), PHP_URL_PATH))->toBe($location);
});

it('reads a createMaster 202 carrying its Location, and the Location is the path getArtifact reads', function (): void {
    // tmesis v0.8.0: the told master's 202 names the new generation's artifact path,
    // and the body already carries that generation's id.
    $header = requiredResponseHeaders()['createMaster 202 Location'];
    $location = '/api/v1/streams/show-2041-main/artifacts/01J8ZCQ4M2T7VNX3RW8KPB5DH6';

    SchemaValidator::forOpenApi()->assertValid($location, $header['pointer'], 'The createMaster Location');

    Http::fake([
        '*' => Http::sequence()
            ->push($this->fixtureJson('responses/createMaster.json'), 202, [$header['header'] => $location])
            ->push($this->fixtureJson('responses/createMaster.json'), 200)
            ->push($this->fixtureJson('responses/createMaster.json')),
    ]);

    $request = MasterRequest::make(
        ['01J8ZCK5R7V2QNX8RW4TKB6PD2', '01J8ZCM1T8V3QNX7RW2KPB4DF9'],
        PlanTarget::recordingMaster('tsp-media', 'shows/2041/show-2041-main-master.mp4'),
    );

    $master = Tmesis::node()->artifacts()->createMaster('show-2041-main', $request);
    $retry = Tmesis::node()->artifacts()->createMaster('show-2041-main', $request);

    expect($master->created)->toBeTrue()
        ->and($retry->created)->toBeFalse()
        ->and($master->id())->toBe('01J8ZCQ4M2T7VNX3RW8KPB5DH6');

    Tmesis::node()->artifacts()->getArtifact('show-2041-main', $master->id());

    $recorded = Http::recorded();

    /** @var Request $read */
    $read = $recorded[2][0];

    expect($recorded[0][1]->header($header['header']))->toBe($location)
        ->and(parse_url($read->url(), PHP_URL_PATH))->toBe($location)
        // The retry is the same request: one request id, minted once.
        ->and($recorded[0][0]->body())->toBe($recorded[1][0]->body());
});
