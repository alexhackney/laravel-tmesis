<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\EnrollRequest;
use AlexHackney\Tmesis\Data\Fleet\FleetHeartbeatEvent;
use AlexHackney\Tmesis\Data\Fleet\FleetNodeStats;
use AlexHackney\Tmesis\Data\Fleet\FleetStreamState;
use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredRelay;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Fleet\EnrollOutcome;
use AlexHackney\Tmesis\Fleet\HeartbeatHandler;
use AlexHackney\Tmesis\Hooks\KeytermsHookController;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Webhooks\EventMap;

pest()->group('schemas-responses');

/**
 * The tmesis tag the PENDING list below was measured at.
 *
 * It is here so the next repin can tell whether the unmodelled set GREW rather than
 * having a fourteenth entry absorbed into a list nobody counted.
 *
 * Between a repin and the tmesis tag it reads the pinned commit's short sha, the same
 * value as the PROVENANCE.md Tag row (decision D9); the tag string is written last,
 * together with the other tag sites, once tmesis has tagged.
 */
const INVENTORY_MEASURED_AT = 'v0.9.3';

/**
 * Schema `$defs` whose class does not carry the `$def`'s name.
 *
 * `HookResponse` (v0.6.0, `publish.verify.response.schema.json`) is the body
 * `HookDecision` renders: the class shipped under that name in v0.1, is the public
 * surface a `PublishHookDecider` returns, and a decision is what it models, so it is
 * not renamed. The class pins the `$def` in an `@see`.
 *
 * The three `Fleet*` names (v0.7.0, `fleet.heartbeat.request.schema.json`): the
 * short `$def` names are already taken in this package, `NodeStats` by the admin stats
 * DTO (decision D5), and `StreamState` and `HeartbeatEvent` read as the admin stream
 * record and a webhook event beside them. Each class names its `$def` in its docblock.
 *
 * @var array<string, class-string>
 */
const DEF_CLASS_DEVIATIONS = [
    'HookResponse' => HookDecision::class,
    'NodeStats' => FleetNodeStats::class,
    'StreamState' => FleetStreamState::class,
    'HeartbeatEvent' => FleetHeartbeatEvent::class,
];

/**
 * Schema `$defs` this package RENDERS rather than decodes, and the class that renders
 * each one.
 *
 * The fleet receiver is the one place the package is the server: the enroll and
 * heartbeat RESPONSES are its output, the node is their reader. So a response `$def`
 * is modelled the way a request component is (REQUEST_COMPONENT_EMITTERS): by the
 * builder or handler that renders it, not by a DTO of its own, and the schemas-fleet
 * and fleet-receiver legs are what validate the rendered body against the schema.
 * `ListenAddresses` is the one request-side entry: the `listen` object EnrollRequest
 * reads into a typed array shape rather than a class.
 *
 * @var array<string, array{0: class-string, 1: string}>
 */
const DEF_EMITTERS = [
    'EnrollResponse' => [EnrollOutcome::class, 'EnrollOutcome::toResponse() renders the enroll 200, the fleet token read once'],
    'HeartbeatResponse' => [HeartbeatHandler::class, 'HeartbeatHandler::handle() renders the heartbeat 200: ack, desired_state, commands'],
    'Command' => [HeartbeatHandler::class, 'one entry of the heartbeat 200 `commands`, rendered by HeartbeatHandler::commandWire() from the fleet store row'],
    'DesiredState' => [DesiredDocument::class, 'DesiredDocument::toWire() renders the document with the revision the package owns'],
    'DesiredStream' => [DesiredStream::class, 'DesiredStream::toWire(), one entry of the document `streams`, labels always an object'],
    'DesiredRecording' => [DesiredRecording::class, 'DesiredRecording::toWire(), both selectors always present, null for the node default'],
    'DesiredRelay' => [DesiredRelay::class, 'DesiredRelay::toWire(), the one place a fleet relay URL is written'],
    'ListenAddresses' => [EnrollRequest::class, 'EnrollRequest::$listen, the `listen` object read into an array{rtmp, srt} shape'],
    // tmesis v0.8.0: the keyterms hook's answer, which the package serves.
    'DesiredJobsPause' => ['AlexHackney\Tmesis\Fleet\DesiredJobsPause', 'DesiredJobsPause::toWire(), the document `jobs_pause` (v0.9.0), always present on the wire, null when none, because absent CLEARS a fleet pause'],
    'KeytermsHookResponse' => [KeytermsHookController::class, 'the keyterms hook route renders the 200 `{keyterms: [...]}` from the app\'s KeytermsProvider, normalised to the node\'s grammar'],
];

/**
 * Components whose class does not carry the component's name.
 *
 * The four D5 deviations, decided 2026-09-07: the component name is an artifact of
 * where the Go type lived, and `AlexHackney\Tmesis\Events\*` already means "a webhook event",
 * so `EventResponse` could not become a class called that here. Each class pins its
 * component in an `@see`. Renaming two and leaving two was explicitly rejected.
 *
 * @var array<string, string>
 */
const COMPONENT_CLASS_DEVIATIONS = [
    'NodeSnapshot' => 'AlexHackney\Tmesis\Data\Node\NodeStats',
    'StreamStatsResponse' => 'AlexHackney\Tmesis\Data\Streams\StreamStats',
    'EventListResponse' => 'AlexHackney\Tmesis\Data\Streams\StreamEventList',
    'EventResponse' => 'AlexHackney\Tmesis\Data\Streams\StreamEvent',
];

/**
 * Request components, and the class that RENDERS each one.
 *
 * openapi.json declares its request bodies since tmesis v0.2.0 (board gap G9 closed
 * 2026-09-19): nine components a consumer never decodes, ten since v0.3.0 added
 * `CaptionsSpec`, twenty since v0.5.0 added the ten artifact request components. The rule: a request component is modelled by the builder or method
 * that renders it, not by a DTO of its own, and the schemas-requests leg is what validates the rendered body
 * against the component. Each entry names the emitter and what it renders; the walk
 * below accepts the entry when the emitter class exists.
 *
 * @var array<string, array{0: string, 1: string}>
 */
const REQUEST_COMPONENT_EMITTERS = [
    'CreateStreamRequest' => ['AlexHackney\Tmesis\Data\Streams\StreamSpec', 'Streams::createStream() sends id plus StreamSpec::toRequestBody()'],
    'StreamSpec' => ['AlexHackney\Tmesis\Data\Streams\StreamSpec', 'Streams::updateStream() sends StreamSpec::toRequestBody(), the PATCH body'],
    'RecordingSpec' => ['AlexHackney\Tmesis\Data\Streams\StreamSpec', 'the nested `recording` object the flat ingestRecording*() methods render'],
    'IngestRecordingSpec' => ['AlexHackney\Tmesis\Data\Streams\StreamSpec', 'the nested `recording.ingest` object: ingestRecordingEnabled(), ingestRetentionHours(), ingestDestination(), ingestDestinationRef()'],
    'PreviewSpec' => ['AlexHackney\Tmesis\Data\Streams\StreamSpec', 'the nested `preview` object: previewEnabled(), previewMaxMonitors()'],
    'CaptionsSpec' => ['AlexHackney\Tmesis\Data\Streams\StreamSpec', 'the nested `captions` object: captionsEnabled(), captionsAdvertise(), captionsProvider(), captionsLanguages(), captionsKeyterms() (v0.8.0)'],
    'JobPauseRequest' => ['AlexHackney\Tmesis\Data\Jobs\JobPauseRequest', 'TranscodeJobs::setTranscodePause() sends JobPauseRequest::toArray() (v0.9.0), `until` always present (null is until cleared)'],
    'RelayInput' => ['AlexHackney\Tmesis\Data\Relays\RelayInput', 'Relays::createRelay() sends RelayInput::toArray(); StreamSpec::relays() renders one per entry'],
    'RelayPatch' => ['AlexHackney\Tmesis\Data\Relays\RelayPatch', 'Relays::updateRelay() sends RelayPatch::toArray()'],
    'RevocationRequest' => ['AlexHackney\Tmesis\Resources\Streams', 'Streams::pushKeyRevocation() renders {streams: [...]} from its array argument; no class holds it'],
    'PlanRequest' => ['AlexHackney\Tmesis\Data\Artifacts\PlanRequest', 'Artifacts::replaceTransferPlan() sends PlanRequest::toArray(), the builder of the same name'],
    'PlanTarget' => ['AlexHackney\Tmesis\Data\Artifacts\PlanTarget', 'one entry of PlanRequest::targets(), rendered by PlanTarget::toArray()'],
    'SignedDestination' => ['AlexHackney\Tmesis\Data\Artifacts\SignedDestination', 'a PlanTarget\'s `signed`, rendered by SignedDestination::toArray()'],
    'InitialAuthorization' => ['AlexHackney\Tmesis\Data\Artifacts\InitialAuthorization', 'one entry of SignedDestination::authorizations(), rendered by InitialAuthorization::toArray()'],
    'AuthorizationRequest' => ['AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest', 'Artifacts::supplyTransferAuthorization() sends AuthorizationRequest::toArray()'],
    'AuthorizationBundle' => ['AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle', 'the `requests` of an AuthorizationRequest or InitialAuthorization, every key rendered'],
    'PartAuthorization' => ['AlexHackney\Tmesis\Data\Artifacts\PartAuthorization', 'one entry of AuthorizationBundle::parts(), rendered by PartAuthorization::toArray()'],
    'SignedRequest' => ['AlexHackney\Tmesis\Data\Artifacts\SignedRequest', 'every leaf of an AuthorizationBundle; a credential, rendered only by SignedRequest::toArray()'],
    'ReleaseRequest' => ['AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest', 'Artifacts::releaseArtifact() sends ReleaseRequest::toArray()'],
    'OverrideRequest' => ['AlexHackney\Tmesis\Data\Artifacts\OverrideRequest', 'Artifacts::overrideArtifact() sends OverrideRequest::toArray(), the discardAbandoned() shape included'],
    // tmesis v0.6.0, LTWC: the stream fallback block, the preview grant bodies, the marker body.
    'FallbackSpec' => ['AlexHackney\Tmesis\Data\Streams\StreamSpec', 'the nested `fallback` object: fallbackEndSeconds(); hold_seconds, chain and slate_asset are RESERVED (501) and never rendered'],
    'PreviewGrantInput' => ['AlexHackney\Tmesis\Data\Preview\PreviewGrantInput', 'PreviewGrants::createPreviewGrant() sends PreviewGrantInput::toArray(), the builder of the same name'],
    'PreviewGrantPatch' => ['AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch', 'PreviewGrants::updatePreviewGrant() sends PreviewGrantPatch::toArray()'],
    'MarkerRequest' => ['AlexHackney\Tmesis\Data\Markers\MarkerRequest', 'Markers::placeMarker() sends MarkerRequest::toArray(), the id minted as a ULID unless given'],
    // tmesis v0.8.0: the told master's body. purgeArtifact takes ReleaseRequest, above.
    'MasterRequest' => ['AlexHackney\Tmesis\Data\Artifacts\MasterRequest', 'Artifacts::createMaster() sends MasterRequest::toArray(), the request id minted as a ULID unless given'],
];

/**
 * Classes under `src/Data/` that are deliberately not a component, each with the
 * reason it is exempt.
 *
 * @var array<string, string>
 */
const TIER_ONE_EXEMPTIONS = [
    'AlexHackney\Tmesis\Data\Node\NodeConfig' => 'a config value object, not a wire shape',
];

/**
 * Schema `$defs` this package does not model yet, and when it will.
 *
 * ONE since the v0.8.0 repin: `StreamLease`, the told grant of a desired stream's live
 * output prefix (`lease: {epoch, holder}` on the enroll and heartbeat responses'
 * `DesiredStream`). It is OPTIONAL on the wire and absent is null, so a control plane
 * that never emits it changes nothing; the package renders none until a consumer wants
 * hot standby (out of the v0.8.0 scope). It lands in DEF_EMITTERS with
 * DesiredStream::toWire() the day that ships.
 *
 * It was EMPTY from the v0.7.0 repin. Thirteen fleet `$defs` were pending at v0.6.0; F2
 * modelled `EnrollRequest` and F3 `HeartbeatRequest` (`Data/Fleet/`, the same short
 * names), the heartbeat's `NodeStats`, `StreamState` and `HeartbeatEvent` are the three
 * `Fleet*` classes in DEF_CLASS_DEVIATIONS, v0.7.0's new `RejectedRevision` is a class
 * of that name, and the eight the receiver renders are in DEF_EMITTERS. A pending
 * entry is a DECLARED, dated omission: the same loud-skip rule the rest of this suite
 * runs on, so a `$def` a later tag adds lands here by name, not in silence.
 *
 * @var list<string>
 */
const TIER_TWO_PENDING = ['StreamLease'];

/**
 * Components reachable ONLY from `x-tmesis-local-only` operations: shapes the box's own
 * CLI sends and reads, which this package never wraps (the routes leg holds that).
 *
 * DERIVED from the document. `leaveFleet` (v0.7.0) brings three, `FleetLeaveRequest`,
 * `FleetLeaveResponse` and `LeaveObligation`; `armSealingDrain` (v0.8.0, the
 * decommission's loopback route) brings `SealingDrainResponse`. `ErrorBody` they share
 * with every other route, so it is not local-only. A component another operation also reaches is
 * modelled like any other.
 *
 * @return list<string>
 */
function localOnlyComponents(): array
{
    $document = ContractDir::readJson(ContractDir::openapi());

    /** @var array<string, array<string, mixed>> $components */
    $components = $document['components']['schemas'];

    $reach = static function (array $roots) use ($components): array {
        $seen = [];
        $queue = $roots;

        while ($queue !== []) {
            $name = array_shift($queue);

            if (isset($seen[$name]) || ! isset($components[$name])) {
                continue;
            }

            $seen[$name] = true;
            preg_match_all('#"\$ref":"\#/components/schemas/([A-Za-z0-9]+)"#', (string) json_encode($components[$name], JSON_UNESCAPED_SLASHES), $matches);
            array_push($queue, ...$matches[1]);
        }

        return array_keys($seen);
    };

    $localRoots = [];
    $otherRoots = [];

    /** @var array<string, array<string, mixed>> $paths */
    $paths = $document['paths'];

    foreach ($paths as $operations) {
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                continue;
            }

            preg_match_all('#"\$ref":"\#/components/schemas/([A-Za-z0-9]+)"#', (string) json_encode($operation, JSON_UNESCAPED_SLASHES), $matches);

            if (($operation['x-tmesis-local-only'] ?? false) === true) {
                array_push($localRoots, ...$matches[1]);
            } else {
                array_push($otherRoots, ...$matches[1]);
            }
        }
    }

    // A component no operation reaches (HealthPublic is reached through getHealth's
    // oneOf) counts as the admin API's, never as local-only.
    $out = array_values(array_diff($reach($localRoots), $reach($otherRoots)));
    sort($out);

    return $out;
}

/**
 * Every class under `src/Data/`, as a fully qualified name.
 *
 * @param  list<string>  $exclude  directory names under src/Data to skip
 * @return list<string>
 */
function dataClasses(array $exclude = []): array
{
    $root = dirname(__DIR__, 2).'/src/Data';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $out = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = trim(str_replace($root, '', $file->getPathname()), '/');

        foreach ($exclude as $directory) {
            if (str_starts_with($relative, $directory.'/')) {
                continue 2;
            }
        }

        $out[] = 'AlexHackney\Tmesis\Data\\'.str_replace('/', '\\', substr($relative, 0, -4));
    }

    sort($out);

    return $out;
}

/**
 * @return list<string>
 */
function eventClasses(): array
{
    $files = glob(dirname(__DIR__, 2).'/src/Events/*.php') ?: [];

    $out = array_map(
        static fn (string $path): string => 'AlexHackney\Tmesis\Events\\'.basename($path, '.php'),
        $files,
    );

    sort($out);

    return $out;
}

/**
 * Every distinct `$defs` name across the 45 schema files.
 *
 * @return list<string>
 */
function schemaDefs(): array
{
    $names = [];

    foreach (ContractDir::schemaFiles() as $schema) {
        $document = ContractDir::readJson(ContractDir::schema($schema));

        /** @var array<string, mixed> $defs */
        $defs = $document['$defs'] ?? [];

        foreach (array_keys($defs) as $name) {
            $names[(string) $name] = true;
        }
    }

    $out = array_keys($names);
    sort($out);

    return $out;
}

it('models every component in the openapi document, response or request', function (): void {
    /** @var array<string, mixed> $components */
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    $classes = dataClasses(exclude: ['Webhooks', 'Hooks', 'Fleet']);

    $missing = [];
    $localOnly = localOnlyComponents();

    foreach (array_keys($components) as $component) {
        if (in_array($component, $localOnly, true)) {
            continue;
        }

        $emitter = REQUEST_COMPONENT_EMITTERS[$component] ?? null;

        if ($emitter !== null) {
            if (! class_exists($emitter[0])) {
                $missing[] = "{$component} (expected the emitter {$emitter[0]})";
            }

            continue;
        }

        $expected = COMPONENT_CLASS_DEVIATIONS[$component] ?? null;

        if ($expected !== null) {
            if (! in_array($expected, $classes, true)) {
                $missing[] = "{$component} (expected the deviation {$expected})";
            }

            continue;
        }

        $matches = array_filter(
            $classes,
            static fn (string $class): bool => str_ends_with($class, '\\'.$component),
        );

        if ($matches === []) {
            $missing[] = (string) $component;
        }
    }

    // Zero gaps, by design. A component with no class is a shape the node can send
    // that this package would hand back as an array: the failure this test exists to
    // find, arriving as a named list rather than as a mystery in a consumer's app.
    expect($missing)->toBe([], 'Components with no DTO: '.implode(', ', $missing));
    // 68 at the v0.2.0 tag: the 59 of rc7 (RecordingRecord, IngestRecordingRecord,
    // PreviewRecord and PreviewMonitorRecord joined the stream record and
    // PreviewSnapshot the stats body; 54 at rc5, when SourceSnapshot joined the stats
    // body) plus the nine request components G9's closing declared (the eight in
    // REQUEST_COMPONENT_EMITTERS and HealthPublic, the unauthenticated half of the
    // health oneOf). 75 at the v0.3.0 tag: the seven captions components
    // (CaptionsSpec, the request half, in REQUEST_COMPONENT_EMITTERS; CaptionsRecord,
    // CaptionSessionRecord, CaptionSnapshot, TranscriptResponse, TranscriptCue and
    // TranscriptWord, each a class of the same name). 98 at the v0.5.0 tag: the 23
    // artifact components, every one a class of the same name under Data/Artifacts
    // (the ten request halves in REQUEST_COMPONENT_EMITTERS, each its own builder; no
    // new deviation). 126 at the v0.6.0 tag (ceab0f6; first measured on branch commit 7645374): the 28
    // preview-grant, marker, fallback and transcode-job components (handover 3.4 to
    // 3.8), each a class of the same name. 129 at the v0.7.0 tag: the three leave
    // components, local-only and so unmodelled by design (localOnlyComponents()). 133
    // at the v0.8.0 tag: MasterInfo and MasterTarget (classes of the same name),
    // MasterRequest (REQUEST_COMPONENT_EMITTERS), and SealingDrainResponse, local-only.
    // 137 at the v0.9.0 tag (91ccf85): HealthLiveCapacity, HealthJobsPause and
    // JobPauseResponse (classes of the same name) and JobPauseRequest
    // (REQUEST_COMPONENT_EMITTERS).
    // tmesis v0.9.1 (a0081e4) adds HealthTranscription; 138 still at the v0.9.2 tag
    // (66bc00d), which added properties only.
    expect($components)->toHaveCount(138)
        ->and($localOnly)->toBe(['FleetLeaveRequest', 'FleetLeaveResponse', 'LeaveObligation', 'SealingDrainResponse']);
});

it('has no class under src/Data that is not a component, except the declared exemptions', function (): void {
    /** @var array<string, mixed> $components */
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    $named = [];

    foreach (array_keys($components) as $component) {
        $named[] = COMPONENT_CLASS_DEVIATIONS[$component] ?? (string) $component;
    }

    $extra = [];

    // Fleet: the fleet DTOs model the fleet.* schemas' $defs, not an admin API
    // component (the Webhooks and Hooks precedent); the $defs leg below holds them.
    foreach (dataClasses(exclude: ['Webhooks', 'Hooks', 'Fleet']) as $class) {
        $short = substr((string) strrchr($class, '\\'), 1);

        if (in_array($class, $named, true) || in_array($short, $named, true)) {
            continue;
        }

        if (! array_key_exists($class, TIER_ONE_EXEMPTIONS)) {
            $extra[] = $class;
        }
    }

    expect($extra)->toBe([], 'Classes under src/Data with no component and no written exemption: '.implode(', ', $extra));
});

it('gives every tier one exemption a written reason', function (): void {
    foreach (TIER_ONE_EXEMPTIONS as $class => $reason) {
        expect(class_exists($class))->toBeTrue("The exemption names a class that does not exist: {$class}.")
            ->and(strlen($reason))->toBeGreaterThan(20, "The exemption for {$class} does not say why.");
    }
});

it('names an emitter only for a request component the document declares, with a written reason', function (): void {
    /** @var array<string, mixed> $components */
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    foreach (REQUEST_COMPONENT_EMITTERS as $component => [$class, $reason]) {
        expect(array_key_exists($component, $components))->toBeTrue("The emitter list names a component the document does not have: {$component}.")
            ->and(class_exists($class))->toBeTrue("The emitter for {$component} names a class that does not exist: {$class}.")
            ->and(strlen($reason))->toBeGreaterThan(20, "The emitter for {$component} does not say what it renders.");
    }
});

it('models every schema $def, or declares it pending with the tag it was measured at', function (): void {
    $modelled = array_merge(
        dataClasses(exclude: ['Node', 'Streams', 'Relays']),
        eventClasses(),
    );

    $shortNames = array_map(
        static fn (string $class): string => substr((string) strrchr($class, '\\'), 1),
        $modelled,
    );

    foreach (DEF_CLASS_DEVIATIONS as $def => $class) {
        if (in_array($class, $modelled, true)) {
            $shortNames[] = $def;
        }
    }

    foreach (DEF_EMITTERS as $def => [$class]) {
        if (class_exists($class)) {
            $shortNames[] = $def;
        }
    }

    $unmodelled = [];

    foreach (schemaDefs() as $def) {
        if (! in_array($def, $shortNames, true)) {
            $unmodelled[] = $def;
        }
    }

    sort($unmodelled);

    expect($unmodelled)->toBe(
        TIER_TWO_PENDING,
        'The unmodelled $defs no longer match the pending list measured at '.INVENTORY_MEASURED_AT
        .'. A NEW one is a shape this package has not caught up with; a MISSING one means it was modelled '
        .'and the list should shrink.'
    );

    // 39 at rc7: the 38 of rc5 (the 33 of rc2 plus stream.live's LadderResolved,
    // ResolvedRung and SourceSpec, and the two new event roots; unchanged at rc6) plus
    // PreviewPlayback, shared by stream.live and stream.sealed. Unchanged at v0.2 step 1
    // (the branch commit): the four artifacts that moved carry descriptions, one enum
    // value, one const and one response headers object, no $def. Unchanged at the v0.2.0 tag:
    // only the openapi document moved, and $defs live in the schemas. 42 at the v0.3.0
    // tag: CaptionPlayback, shared by stream.live, stream.sealed and
    // caption.session_ended, and the two new event roots. 43 at the v0.4.0 tag: the
    // lease.fenced root; the relay feed and the source audio codec are fields on
    // $defs that already existed. 49 at the v0.5.0 tag: the six artifact.* and
    // transfer.* roots; their ArtifactIdentity block is inlined in each, not a $def.
    // 62 at the v0.6.0 tag (ceab0f6): HookResponse (the hook response, modelled by
    // HookDecision, DEF_CLASS_DEVIATIONS), the five job.* roots and JobError,
    // JobLandedObject, JobOutputReport, JobProbeSummary, JobSourceReport, and the
    // marker.placed root with MarkerPlacement. Every one is modelled, so the pending
    // list stayed the thirteen fleet $defs. 63 at the v0.7.0 tag: RejectedRevision,
    // the heartbeat's refused-revision report, a class of that name; the pending list
    // is empty (TIER_TWO_PENDING says how each fleet $def is held). 65 at the v0.8.0
    // tag: KeytermsHookResponse, the keyterms hook's answer (modelled by the hook
    // route), and StreamLease, the fleet lease, PENDING (TIER_TWO_PENDING). 76 at the
    // v0.9.0 tag (91ccf85): the four new event roots (NodeCapacityChanged,
    // NodeDiskFloorNear, JobsPaused, JobsResumed), NodeLiveCapacity,
    // NodeLiveCapacityPrevious, NodeJobsCapacity, NodeVideoEngine and NodeJobsPause
    // (classes of the same name under Data/Webhooks), JobsPauseState (Data/Fleet) and
    // DesiredJobsPause (DEF_EMITTERS). The pending list is unchanged. Still 76 at the
    // v0.9.1 tag (a0081e4): resumed_after is a property, not a $def. Still 76 at the
    // v0.9.2 tag (66bc00d): the failover fields are properties too. Still 76 at the
    // v0.9.3 tag (235c821): sockets, rotations and the job provider are properties.
    expect(TIER_TWO_PENDING)->toBe(['StreamLease'])
        ->and(schemaDefs())->toHaveCount(76)
        ->and(INVENTORY_MEASURED_AT)->toBe('v0.9.3');
});

it('names a $def emitter only for a $def the schemas declare, with a written reason', function (): void {
    $defs = schemaDefs();

    foreach (DEF_EMITTERS as $def => [$class, $reason]) {
        expect(in_array($def, $defs, true))->toBeTrue("The emitter list names a \$def no schema has: {$def}.")
            ->and(class_exists($class))->toBeTrue("The emitter for {$def} names a class that does not exist: {$class}.")
            ->and(strlen($reason))->toBeGreaterThan(20, "The emitter for {$def} does not say what it renders.")
            ->and(array_key_exists($def, DEF_CLASS_DEVIATIONS))->toBeFalse("{$def} is both a deviation and an emitter.");
    }
});

it('exercises every bundled schema in some leg', function (): void {
    // 45 files since v0.9.0 (jobs.paused, jobs.resumed, node.capacity_changed and
    // node.disk_floor_near joined, all events). 41 since v0.8.0 (keyterms.hook.response joined: the keyterms hook's answer,
    // which the package SERVES). 40 since v0.6.0 (the five job.* events, marker.placed
    // and the hook response joined; 33 at v0.5.0 when the three artifact.* and three transfer.*
    // events did; 27 at v0.4.0 when lease.fenced did; 26 at v0.3.0 when the two
    // caption.session_* schemas did), and every one of them has to be somebody's job:
    //
    //   38 events                schemas-events
    //   publish.verify           schemas-events (the shape) and hook-endpoint (the endpoint)
    //   publish.verify.response  hook-endpoint (every HookDecision) and vectors-hook
    //   4 fleet.*                schemas-fleet
    //   keyterms.hook.response   vectors-keyterms (every answer the route renders)
    //
    // A schema added upstream that nothing validates is the silence this leg exists
    // to break.
    $schemas = ContractDir::schemaFiles();

    $events = ContractDir::eventSchemaFiles();

    $fleet = array_values(array_filter($schemas, static fn (string $name): bool => str_starts_with($name, 'fleet.')));

    expect($schemas)->toHaveCount(45)
        ->and($schemas)->toContain('keyterms.hook.response')
        ->and($events)->not->toContain('keyterms.hook.response')
        ->and($events)->toHaveCount(38)
        ->and($fleet)->toHaveCount(4)
        ->and(array_values(array_intersect(ContractDir::HOOK_SCHEMAS, $schemas)))->toBe(ContractDir::HOOK_SCHEMAS);

    // Every event name in the catalog this package dispatches on, so a schema with no
    // mapping is a failure here rather than a base-class dispatch nobody noticed.
    foreach ($events as $event) {
        expect(EventMap::CLASSES)->toHaveKey($event);
    }
});
