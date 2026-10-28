<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Events\ArtifactReleased;
use AlexHackney\Tmesis\Events\CaptionSessionEnded;
use AlexHackney\Tmesis\Events\NodeDraining;
use AlexHackney\Tmesis\Events\RecordingCompleted;
use AlexHackney\Tmesis\Events\RecordingFailed;
use AlexHackney\Tmesis\Events\RecordingRegistered;
use AlexHackney\Tmesis\Events\RecordingStarted;
use AlexHackney\Tmesis\Events\StreamPublishRejected;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

pest()->group('schemas-events');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);

    // Decision D3, retired at v0.3.1. The five recording.* events have an
    // emitter since rc7 for the ingest artifact, and the payloads are now the node's
    // generated examples (docs/contract/examples/), which show that ingest artifact;
    // the WEBHOOKS.md fences they replace showed a master artifact with no emitter
    // until M4.
    fwrite(STDERR, 'payloads: the generated docs/contract/examples/ at the contract root.'.PHP_EOL);
});

it('names the five recording events, whose examples show the ingest artifact', function (): void {
    $recording = array_values(array_filter(
        array_keys(eventSchemaNames()),
        static fn (string $event): bool => str_starts_with($event, 'recording.'),
    ));

    sort($recording);

    // If this set moves, the note above is describing a set that no longer exists
    // and has to be rewritten rather than quietly left.
    expect($recording)->toBe([
        'recording.completed',
        'recording.degraded',
        'recording.failed',
        'recording.registered',
        'recording.started',
    ]);
});

/**
 * Every event schema in the resolved root, as a dataset.
 *
 * A schema added upstream shows up here as a new row rather than as silence.
 *
 * @return array<string, array{0: string}>
 */
function eventSchemaNames(): array
{
    $out = [];

    // The hook's request and response (publish.verify, publish.verify.response) and
    // the fleet.* documents are not deliveries; ContractDir owns that rule.
    foreach (ContractDir::eventSchemaFiles() as $name) {
        $out[$name] = [$name];
    }

    return $out;
}

/**
 * The generated example for an event at ContractDir::root(), so a TMESIS_CONTRACT_DIR
 * run exercises the checkout's own bodies. Every registered event has one (the
 * provenance leg holds the set), so a missing file fails rather than skips.
 */
function payloadPath(string $event): string
{
    $path = ContractDir::example($event);

    if (! is_file($path)) {
        throw new RuntimeException("No generated example for {$event} at [{$path}].");
    }

    return $path;
}

it('validates, maps, and round-trips the payload', function (string $event): void {
    $path = payloadPath($event);

    $json = (string) file_get_contents($path);
    $validator = SchemaValidator::forSchema($event);

    // 1. the fixture is a valid payload for its schema
    $validator->assertValid(json_decode($json, false), context: "The {$event} example");

    /** @var array<string, mixed> $payload */
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    // 2. it maps to the typed class, never the base class
    $expected = EventMap::classFor($event);
    expect($expected)->not->toBe(TmesisWebhookReceived::class, "No typed class for {$event}.");

    $instance = EventMap::make($payload);
    expect($instance)->toBeInstanceOf($expected)
        ->and($instance->event())->toBe($event)
        ->and($instance->isKnownEvent())->toBeTrue();

    // 3. the round trip is byte neutral
    $validator->assertValid(
        json_decode((string) json_encode($instance->toArray()), false),
        context: "The {$event} round trip"
    );

    // 4. every accessor runs, so a typo in a key name is caught by a valid fixture
    foreach (accessorsOf($instance) as $method) {
        $instance->{$method}();
    }
})->with(eventSchemaNames());

it('tolerates an unknown field', function (string $event): void {
    $path = payloadPath($event);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $payload['x_future_field'] = 1;

    $instance = EventMap::make($payload);

    // Deliberately NOT validated against the schema: every schema is
    // additionalProperties:false, so a payload from a newer node fails the pinned
    // schema. That is the repin signal. The package must still read it.
    expect($instance)->toBeInstanceOf(EventMap::classFor($event))
        ->and($instance->toArray()['x_future_field'])->toBe(1);

    foreach (accessorsOf($instance) as $method) {
        $instance->{$method}();
    }
})->with(eventSchemaNames());

it('holds the event name with a const, so a swapped name fails validation', function (string $event): void {
    $path = payloadPath($event);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $other = $event === 'stream.live' ? 'stream.sealed' : 'stream.live';
    $payload['event'] = $other;

    expect(SchemaValidator::forSchema($event)->isValid(json_decode((string) json_encode($payload), false)))
        ->toBeFalse("The {$event} schema accepted an event name of {$other}.");
})->with(eventSchemaNames());

/**
 * The examples show `api_stop` and `publisher` (the fences they replaced showed
 * `publisher_stop` and `publisher`). The values the tmesis v0.2.0 step 1 rejoin model (final plan 11.38)
 * made live are pinned here by overlaying them on the fence and validating the
 * result at ContractDir::root(), so the row runs against both roots.
 *
 * The first row is the enum widening: `input_lost` joined `reason` at step 1, so
 * it FAILS against the rc7 bundled copies and passes once the repin lands, which is
 * what pins the repin. The other two pass on both roots because their enums did
 * not move; they pin the model rather than the enum. The third keeps the fixture's
 * `raw_parts_preserved: true` and its `path`, which is exactly the refused-close
 * shape, and exercises the schema's crossfield rule from the side that matters
 * now.
 *
 * @param  array<string, mixed>  $overlay
 * @param  class-string<TmesisWebhookReceived>  $class
 */
it('pins the values the v0.2 step 1 rejoin model made live', function (
    string $event,
    array $overlay,
    string $class,
    string $accessor,
    string $expected,
): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(payloadPath($event)), true, 512, JSON_THROW_ON_ERROR);
    $payload = array_replace($payload, $overlay);

    SchemaValidator::forSchema($event)->assertValid(
        json_decode((string) json_encode($payload), false),
        context: "The {$event} fixture with ".json_encode($overlay).' overlaid'
    );

    $instance = EventMap::make($payload);

    expect($instance)->toBeInstanceOf($class)
        ->and($instance->{$accessor}())->toBe($expected);
})->with([
    'recording.completed reason input_lost' => ['recording.completed', ['reason' => 'input_lost'], RecordingCompleted::class, 'reason', RecordingCompleted::REASON_INPUT_LOST],
    'recording.started started_by resume' => ['recording.started', ['started_by' => 'resume', 'part' => 1], RecordingStarted::class, 'startedBy', RecordingStarted::STARTED_BY_RESUME],
    'recording.failed stage finalize with the file kept' => ['recording.failed', ['stage' => 'finalize'], RecordingFailed::class, 'stage', RecordingFailed::STAGE_FINALIZE],
    // tmesis v0.7.0: the in-place fleet drain, and the publish it refuses.
    'node.draining reason fleet' => ['node.draining', ['reason' => 'fleet'], NodeDraining::class, 'reason', NodeDraining::REASON_FLEET],
    'stream.publish_rejected reason closed' => ['stream.publish_rejected', ['reason' => 'closed'], StreamPublishRejected::class, 'reason', StreamPublishRejected::REASON_CLOSED],
    // tmesis v0.9.0 (handover 3.1, 3.4): the full box and the failed live pin.
    'stream.publish_rejected reason over_capacity' => ['stream.publish_rejected', ['reason' => 'over_capacity'], StreamPublishRejected::class, 'reason', StreamPublishRejected::REASON_OVER_CAPACITY],
    'stream.publish_rejected reason encoder_unavailable' => ['stream.publish_rejected', ['reason' => 'encoder_unavailable'], StreamPublishRejected::class, 'reason', StreamPublishRejected::REASON_ENCODER_UNAVAILABLE],
]);

/**
 * The caption events' closed sets, read from the schema at ContractDir::root() so a
 * value added upstream fails here rather than arriving as an unnamed string.
 */
it('holds caption.session_ended\'s closed reason set as ENDED_REASONS', function (): void {
    $schema = json_decode(
        (string) file_get_contents(ContractDir::root().'/docs/contract/schemas/caption.session_ended.schema.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $enum = $schema['$defs']['CaptionSessionEnded']['properties']['reason']['enum'];

    expect(CaptionSessionEnded::ENDED_REASONS)->toBe($enum);
});

it('validates every ended reason and a null artifacts block', function (string $reason): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(payloadPath('caption.session_ended')), true, 512, JSON_THROW_ON_ERROR);

    // Only a stream_ended close after the seal carries artifacts; every other reason
    // is null, and so is a live close.
    $payload = array_replace($payload, ['reason' => $reason, 'artifacts' => null, 'detail' => 'provider said no']);

    SchemaValidator::forSchema('caption.session_ended')->assertValid(
        json_decode((string) json_encode($payload), false),
        context: "caption.session_ended with reason {$reason}"
    );

    $event = EventMap::make($payload);

    expect($event)->toBeInstanceOf(CaptionSessionEnded::class)
        ->and($event->reason())->toBe($reason)
        ->and($event->isProviderSwitch())->toBe($reason === CaptionSessionEnded::REASON_PROVIDER_SWITCH)
        ->and($event->artifacts())->toBeNull();
})->with(CaptionSessionEnded::ENDED_REASONS);

it('refuses a map of languages where caption.session_ended expects one artifacts entry', function (): void {
    // The event is already per language: artifacts is ONE CaptionPlayback, the same
    // object stream.sealed's vod.captions carries under this lang, not a map of them.
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(payloadPath('caption.session_ended')), true, 512, JSON_THROW_ON_ERROR);
    $payload['artifacts'] = ['en' => $payload['artifacts']];

    expect(SchemaValidator::forSchema('caption.session_ended')->isValid(json_decode((string) json_encode($payload), false)))
        ->toBeFalse();
});

it('validates stream.live and stream.sealed with no caption rendition', function (string $event, string $block): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(payloadPath($event)), true, 512, JSON_THROW_ON_ERROR);
    $payload[$block]['captions'] = null;

    SchemaValidator::forSchema($event)->assertValid(
        json_decode((string) json_encode($payload), false),
        context: "{$event} with {$block}.captions null"
    );
})->with([
    'stream.live playback' => ['stream.live', 'playback'],
    'stream.sealed vod' => ['stream.sealed', 'vod'],
]);

it('holds transfer.authorization_required\'s closed reason set as REASONS', function (): void {
    $schema = ContractDir::readJson(ContractDir::schema('transfer.authorization_required'));

    $enum = $schema['$defs']['TransferAuthorizationRequired']['properties']['reason']['enum'];

    expect(TransferAuthorizationRequired::REASONS)->toBe($enum);
});

it('carries the same eight identity fields, typed alike, on all six artifact and transfer events', function (string $event): void {
    $schema = ContractDir::readJson(ContractDir::schema($event));

    /** @var array<string, mixed> $root */
    $root = array_values($schema['$defs'])[0];

    // job_id joined at v0.6.0, and the three session fields became nullable: null on
    // a job generation, which names job_id instead.
    $identity = ['stream', 'session', 'session_epoch', 'job_id', 'generation', 'kind', 'inventory_sha256', 'labels'];

    foreach ($identity as $field) {
        expect($root['required'])->toContain($field);
    }

    $types = static fn (string $field): array => array_map(
        static fn (array $branch): string => (string) ($branch['type'] ?? ''),
        (array) ($root['properties'][$field]['oneOf'] ?? []),
    );

    expect($types('stream'))->toBe(['string', 'null'])
        ->and($types('session'))->toBe(['string', 'null'])
        ->and($types('session_epoch'))->toBe(['integer', 'null'])
        ->and($types('job_id'))->toBe(['string', 'null'])
        ->and($root['properties']['kind']['enum'])->toEqualCanonicalizing(ArtifactStatus::KINDS);
})->with([
    'artifact.finalized',
    'artifact.released',
    'artifact.deleted',
    'transfer.completed',
    'transfer.failed',
    'transfer.authorization_required',
]);

/**
 * Shapes the examples do not show, overlaid on them and validated at
 * ContractDir::root(): the abandoned generation's discard (API.md 12a.5a), the
 * completion probe (TMESIS-REVIEW-7 R3), and a recording registered before v0.5.0.
 *
 * @param  array<string, mixed>  $overlay
 * @param  class-string<TmesisWebhookReceived>  $class
 */
it('validates the v0.5.0 shapes the examples do not show', function (
    string $event,
    array $overlay,
    string $class,
    string $accessor,
    mixed $expected,
): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(payloadPath($event)), true, 512, JSON_THROW_ON_ERROR);
    $payload = array_replace($payload, $overlay);

    SchemaValidator::forSchema($event)->assertValid(
        json_decode((string) json_encode($payload), false),
        context: "The {$event} example with ".json_encode($overlay).' overlaid'
    );

    $instance = EventMap::make($payload);

    expect($instance)->toBeInstanceOf($class)
        ->and($instance->{$accessor}())->toBe($expected);
})->with([
    'artifact.released abandoned discard' => [
        'artifact.released',
        ['action' => 'discard', 'plan_revision' => 0, 'inventory_sha256' => '', 'waived_targets' => []],
        ArtifactReleased::class,
        'isAbandonedDiscard',
        true,
    ],
    'transfer.authorization_required completion probe' => [
        'transfer.authorization_required',
        ['reason' => 'completion_probe_required', 'required' => ['head'], 'multipart_upload_id' => null],
        TransferAuthorizationRequired::class,
        'requiresHead',
        true,
    ],
    'recording.registered before v0.5.0' => [
        'recording.registered',
        ['generation' => null],
        RecordingRegistered::class,
        'generation',
        null,
    ],
]);

it('refuses a recording.registered with no generation key at all', function (): void {
    // Required means PRESENT: a pre-v0.5.0 recording sends null, never omits it.
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents(payloadPath('recording.registered')), true, 512, JSON_THROW_ON_ERROR);
    unset($payload['generation']);

    expect(SchemaValidator::forSchema('recording.registered')->isValid(json_decode((string) json_encode($payload), false)))
        ->toBeFalse();
});
