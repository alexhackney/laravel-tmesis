<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\EnrollRequest;
use AlexHackney\Tmesis\Data\Fleet\HeartbeatRequest;
use AlexHackney\Tmesis\Exceptions\FleetPayloadException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredJobsPause;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredRelay;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

pest()->group('schemas-fleet');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/**
 * The worked fleet exchange, every document with the schema it names.
 *
 * Fourteen since tmesis v0.7.0 (four at v0.2.0): one enrollment and its D12 repeat,
 * a heartbeat moving the node 42 to 43, revision 44 refused and reported, a digest
 * drift converged by resending 43, and a batch of commands acked. The fleet-receiver
 * leg drives the same sequence through the package's routes; this one holds each
 * document to its schema, and the package's own parsers and renderers to the bytes.
 *
 * Decoded to OBJECTS, not arrays. `commands[].args` and `labels` can be empty maps, and
 * an assoc decode would re-encode them as `[]` and fail a `type: object` check for a
 * reason that has nothing to do with the contract.
 *
 * @return array<string, array{0: string, 1: string, 2: stdClass}>
 */
function fleetDocuments(): array
{
    $vector = json_decode((string) file_get_contents(ContractDir::vectors('fleet')), false, 512, JSON_THROW_ON_ERROR);

    $out = [];

    foreach ($vector->documents as $document) {
        $out[$document->name] = [(string) $document->name, (string) $document->schema, $document->body];
    }

    return $out;
}

/**
 * One document's body by name, as a copy.
 */
function fleetBody(string $name): stdClass
{
    return fleetCopy(fleetDocuments()[$name][2]);
}

/**
 * A deep copy, so a mutation test cannot leak into the dataset the others read.
 */
function fleetCopy(stdClass $body): stdClass
{
    return json_decode((string) json_encode($body), false, 512, JSON_THROW_ON_ERROR);
}

/**
 * The package's own build of the vector's revision 43 content: one stream on a named
 * destination with one fleet relay, and one revocation.
 */
function fleetRevision43(): DesiredDocument
{
    return DesiredDocument::make()
        ->stream(DesiredStream::make(
            'show-2041-main',
            DesiredRecording::make(enabled: true, retentionHours: 72, destinationRef: 'tsp-archive'),
            minKeyEpoch: 7,
            labels: ['site' => 'csn', 'show_id' => '2041'],
            relays: [DesiredRelay::make('fleet-origin-atl', 'srt://origin-atl-01.internal:8890')],
        ))
        ->revoke('show-2039-rehearsal');
}

/**
 * A heartbeat answer around one rendered desired state, as the receiver sends it.
 *
 * @param  array<string, mixed>  $desired
 */
function fleetResponseAround(array $desired): stdClass
{
    return json_decode((string) json_encode(['ack_event_ids' => [], 'desired_state' => $desired, 'commands' => []]), false, 512, JSON_THROW_ON_ERROR);
}

it('validates every fleet document against the schema it names', function (string $name, string $schema, stdClass $body): void {
    SchemaValidator::forSchema($schema)->assertValid($body, context: "The fleet document [{$name}]");
})->with(fleetDocuments());

it('carries the fourteen documents of one worked exchange, in order', function (): void {
    expect(array_keys(fleetDocuments()))->toBe([
        'enroll.request',
        'enroll.response',
        'enroll.request.repeat',
        'enroll.response.repeat',
        'heartbeat.request.applied-42',
        'heartbeat.response.revision-43',
        'heartbeat.request.applied-43',
        'heartbeat.response.revision-44-invalid',
        'heartbeat.request.rejected-44',
        'heartbeat.request.digest-drift',
        'heartbeat.response.revision-43-resent',
        'heartbeat.request.converged',
        'heartbeat.response.commands',
        'heartbeat.request.commands-acked',
    ]);
});

it('parses every node-sent document through the package\'s strict parsers', function (): void {
    $parsed = 0;

    foreach (fleetDocuments() as [$name, $schema, $body]) {
        $raw = (string) json_encode($body);

        if ($schema === 'fleet.enroll.request') {
            expect(EnrollRequest::fromRaw($raw)->node)->toBe('edge-venue-07', $name);
            $parsed++;
        }

        if ($schema === 'fleet.heartbeat.request') {
            expect(HeartbeatRequest::fromRaw($raw)->node)->toBe('edge-venue-07', $name);
            $parsed++;
        }
    }

    // Two enrollment requests and six heartbeats.
    expect($parsed)->toBe(8);

    $rejected = HeartbeatRequest::fromRaw((string) json_encode(fleetBody('heartbeat.request.rejected-44')));

    expect($rejected->desiredRevisionApplied)->toBe(43)
        ->and($rejected->rejected?->revision)->toBe(44)
        ->and($rejected->rejected?->reason)->toStartWith('streams[0].recording:');
});

it('reproduces every revocations digest the node reports from the list the control plane sent', function (): void {
    // Tmesis WP5: lowercase hex SHA-256 over the list sorted, deduplicated and joined
    // with "\n". The empty list is SHA-256 of zero bytes.
    $empty = RevocationsDigest::of([]);
    $one = RevocationsDigest::of(fleetBody('heartbeat.response.revision-43')->desired_state->revocations);

    expect($empty)->toBe(RevocationsDigest::EMPTY)
        ->and(fleetBody('heartbeat.request.applied-42')->revocations_digest)->toBe($empty)
        ->and(fleetBody('heartbeat.request.applied-43')->revocations_digest)->toBe($one)
        ->and(fleetBody('heartbeat.request.rejected-44')->revocations_digest)->toBe($one)
        // The drift: an admin API push emptied the list, the revision stayed 43.
        ->and(fleetBody('heartbeat.request.digest-drift')->revocations_digest)->toBe($empty)
        ->and(RevocationsDigest::of(fleetBody('heartbeat.response.revision-43-resent')->desired_state->revocations))->toBe($one)
        ->and(fleetBody('heartbeat.request.converged')->revocations_digest)->toBe($one)
        ->and(fleetBody('heartbeat.request.commands-acked')->revocations_digest)->toBe($one)
        // And the package's own document hashes the same list the same way.
        ->and(fleetRevision43()->revocationsDigest())->toBe($one);
});

it('renders the vector\'s revision 43 desired state byte for byte from the package\'s builders', function (): void {
    // The receiver's output is the half a node reads, so the package's renderers are
    // held to the hand-written document, not to a restatement of the schema. Labels
    // are built out of order on purpose: the vector's order is key order.
    // Since v0.9.0 the package always sends `jobs_pause` (null when none, handover
    // 3.5) and no vector desired state carries the key; absent and null both mean "no
    // fleet pause" at the node, so the vector is compared with the null appended.
    $rendered = json_encode(fleetRevision43()->toWire(43), JSON_UNESCAPED_SLASHES);

    foreach (['heartbeat.response.revision-43', 'heartbeat.response.revision-43-resent'] as $name) {
        $desired = fleetBody($name)->desired_state;

        expect(property_exists($desired, 'jobs_pause'))->toBeFalse("The vector's [{$name}] now carries jobs_pause: render it from the builders.");

        $desired->jobs_pause = null;

        expect($rendered)->toBe(json_encode($desired, JSON_UNESCAPED_SLASHES), $name);
    }
});

it('renders a jobs pause the vector does not carry, and the answer stays on both schemas (v0.9.0)', function (): void {
    $document = fleetRevision43()->jobsPause(DesiredJobsPause::make('show: fight night 12', '2026-08-28T19:00:00Z'));
    $desired = json_decode((string) json_encode($document->toWire(44)), false);

    expect($desired->jobs_pause)->toEqual((object) ['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12']);

    SchemaValidator::forSchema('fleet.heartbeat.response')->assertValid(fleetResponseAround($document->toWire(44)), context: 'A heartbeat answer with a jobs pause');

    $enroll = fleetBody('enroll.response');
    $enroll->desired_state = $desired;

    SchemaValidator::forSchema('fleet.enroll.response')->assertValid($enroll, context: 'An enroll answer with a jobs pause');
});

it('rejects a desired jobs pause with a key the schema does not have, or no until key', function (Closure $mutate): void {
    $mutated = fleetBody('heartbeat.response.revision-43');
    $mutated->desired_state->jobs_pause = (object) ['until' => null, 'reason' => 'show'];
    $mutate($mutated->desired_state->jobs_pause);

    expect(SchemaValidator::forSchema('fleet.heartbeat.response')->isValid($mutated))->toBeFalse();
})->with([
    'an extra key' => [function (stdClass $p): void {
        $p->source = 'fleet';
    }],
    'no until key' => [function (stdClass $p): void {
        unset($p->until);
    }],
]);

it('decodes every vector heartbeat\'s jobs_pause as none, a v0.8.x beat without the key as none, and a malformed one never', function (): void {
    foreach (fleetDocuments() as [$name, $schema, $body]) {
        if ($schema !== 'fleet.heartbeat.request') {
            continue;
        }

        expect(property_exists($body, 'jobs_pause'))->toBeTrue($name)
            ->and(HeartbeatRequest::fromRaw((string) json_encode($body))->jobsPause)->toBeNull();
    }

    $mutated = fleetBody('heartbeat.request.applied-43');
    $mutated->jobs_pause = (object) ['until' => '2026-08-28T19:00:00Z', 'reason' => 'show: fight night 12', 'source' => 'fleet'];

    SchemaValidator::forSchema('fleet.heartbeat.request')->assertValid($mutated, context: 'A heartbeat with a fleet jobs pause');

    expect(HeartbeatRequest::fromRaw((string) json_encode($mutated))->jobsPause?->source)->toBe('fleet');

    // The schema requires the key since v0.9.0, but the package ships before the nodes
    // upgrade: a v0.8.x beat has no jobs_pause and is read as no pause.
    unset($mutated->jobs_pause);

    expect(SchemaValidator::forSchema('fleet.heartbeat.request')->isValid($mutated))->toBeFalse()
        ->and(HeartbeatRequest::fromRaw((string) json_encode($mutated))->jobsPause)->toBeNull();

    $mutated->jobs_pause = (object) ['until' => null, 'reason' => 'r', 'source' => 'operator'];

    expect(fn () => HeartbeatRequest::fromRaw((string) json_encode($mutated)))->toThrow(FleetPayloadException::class, '$.jobs_pause.source');
});

it('refuses revision 44\'s content at the host app\'s call site, before a node can', function (): void {
    // The vector's worked rejection: an s3:// URL in destination (R01). The node
    // refuses the revision; the package refuses to build it.
    $recording = fleetBody('heartbeat.response.revision-44-invalid')->desired_state->streams[0]->recording;

    expect(fn () => DesiredRecording::make(enabled: true, retentionHours: 72, destination: $recording->destination))
        ->toThrow(InvalidConfigurationException::class);
});

it('always renders both recording selectors, and each form validates (D16)', function (?string $destination, ?string $destinationRef): void {
    // destination_ref is REQUIRED since v0.7.0 (a string or null), and so is
    // destination: null is how the fleet says "the node default", so neither key is
    // ever omitted.
    $recording = DesiredRecording::make(enabled: true, retentionHours: 24, destination: $destination, destinationRef: $destinationRef);
    $wire = $recording->toWire();

    expect($wire)->toHaveKeys(['destination', 'destination_ref'])
        ->and($wire['destination'])->toBe($destination)
        ->and($wire['destination_ref'])->toBe($destinationRef);

    $document = DesiredDocument::make()->stream(DesiredStream::make('show-2041-main', $recording));
    $context = 'destination '.json_encode($destination).' and destination_ref '.json_encode($destinationRef);

    SchemaValidator::forSchema('fleet.heartbeat.response')->assertValid(
        fleetResponseAround($document->toWire(1)),
        context: "A heartbeat answer with {$context}",
    );

    $enroll = fleetBody('enroll.response');
    $enroll->desired_state = json_decode((string) json_encode($document->toWire(1)), false);

    SchemaValidator::forSchema('fleet.enroll.response')->assertValid($enroll, context: "An enroll answer with {$context}");
})->with([
    'neither: the node default' => [null, null],
    'only destination_ref' => [null, 'tsp-archive'],
    'only destination' => ['/var/lib/tmesis/ingest', null],
]);

it('rejects a mutated command kind', function (): void {
    // A validator that accepts everything is a validator that is not running, and the
    // vector file names this as the cheapest closed-set value to prove it with.
    $mutated = fleetBody('heartbeat.response.commands');

    expect($mutated->commands)->not->toBeEmpty();

    $mutated->commands[0]->kind = 'definitely-not-a-command-kind';

    expect(SchemaValidator::forSchema('fleet.heartbeat.response')->isValid($mutated))
        ->toBeFalse('fleet.heartbeat.response accepted a command kind that is not in its enum.');
});

it('rejects a heartbeat that drops a required field', function (string $field): void {
    $mutated = fleetBody('heartbeat.request.applied-43');
    unset($mutated->{$field});

    expect(SchemaValidator::forSchema('fleet.heartbeat.request')->isValid($mutated))
        ->toBeFalse("fleet.heartbeat.request accepted a body with no {$field}.");
})->with(['desired_revision_applied', 'admission', 'revocations_digest', 'desired_revision_rejected', 'jobs_pause']);

it('rejects an enrolment that invents a field', function (): void {
    // Every fleet schema is additionalProperties: false, so a control plane that
    // sends a field the node does not know is told, rather than having it dropped.
    $mutated = fleetBody('enroll.request');
    $mutated->something_a_newer_control_plane_sends = true;

    expect(SchemaValidator::forSchema('fleet.enroll.request')->isValid($mutated))->toBeFalse();
});

it('rejects a desired state below the v0.7.0 minimums, or missing a recording selector', function (Closure $mutate, string $what): void {
    $mutated = fleetBody('heartbeat.response.revision-43');
    $mutate($mutated->desired_state);

    expect(SchemaValidator::forSchema('fleet.heartbeat.response')->isValid($mutated))
        ->toBeFalse("fleet.heartbeat.response accepted {$what}.");
})->with([
    'revision 0' => [function (stdClass $d): void {
        $d->revision = 0;
    }, 'revision 0'],
    'retention_hours 0' => [function (stdClass $d): void {
        $d->streams[0]->recording->retention_hours = 0;
    }, 'retention_hours 0'],
    'min_key_epoch -1' => [function (stdClass $d): void {
        $d->streams[0]->min_key_epoch = -1;
    }, 'min_key_epoch -1'],
    'no destination_ref' => [function (stdClass $d): void {
        unset($d->streams[0]->recording->destination_ref);
    }, 'a recording with no destination_ref key'],
    'no destination' => [function (stdClass $d): void {
        unset($d->streams[0]->recording->destination);
    }, 'a recording with no destination key'],
]);
