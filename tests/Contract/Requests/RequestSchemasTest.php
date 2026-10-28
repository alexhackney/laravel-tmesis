<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\AuthorizationRequest;
use AlexHackney\Tmesis\Data\Artifacts\InitialAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\OverrideRequest;
use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\ReleaseRequest;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Data\Jobs\AudioOpts;
use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobSignedTarget;
use AlexHackney\Tmesis\Data\Jobs\JobSource;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\JobTarget;
use AlexHackney\Tmesis\Data\Jobs\MP4Opts;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Data\Jobs\ThumbnailOpts;
use AlexHackney\Tmesis\Data\Jobs\TranscriptOpts;
use AlexHackney\Tmesis\Data\Markers\MarkerRequest;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantInput;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

pest()->group('schemas-requests');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/*
 * The schemas-requests leg.
 *
 * The responses leg holds what the node SENDS to the document; this holds what the
 * client sends. Every wrapped operation is driven through the real client with
 * Http::fake() answering its response fixture, the recorded request is read back off
 * Http::recorded(), and its JSON body and its query string are validated against the
 * document at ContractDir::root(). The schema validates SHAPE and ENUMS; what only the
 * node can decide (the segment grid, a ladder name, a provider) stays the node's.
 */

/**
 * Known disagreements between what the client sends and what the document allows,
 * each under the finding's name. Keyed `operationId parameter value`.
 *
 * EMPTY at tmesis v0.4.0. The one entry it ever held was F2: through v0.3.0 the
 * openapi document schemed `include` as ONE enum token while its own description,
 * API.md 9.5 and the node took a comma-separated set, so
 * `getStream($id, includePreview: true, includeCaptions: true)` sent
 * `include=preview,captions`, which the node answered and the document refused. The
 * client was right and was NOT bent; TMESIS-API-6 fixed the document on the v0.4.0
 * MINOR (`include` is a string with the pattern
 * `^(preview|captions)(,(preview|captions))*$` now), and the repin
 * deleted the entry when the "still needed" test below went red on it. The
 * mechanism stays: an entry that is no longer needed fails the leg, so a repin
 * cannot carry a dead one.
 *
 * @var array<string, string>
 */
const REQUEST_ALLOW_LIST = [];

/**
 * Query parameters the document declares and the client never sends, each with the
 * reason. Keyed `operationId parameter`. Held EQUAL to the derived set, so a
 * parameter added upstream is named here rather than going unoffered quietly.
 *
 * @var array<string, string>
 */
const QUERY_PARAMETERS_NOT_SENT = [
    'deleteStream seal' => 'RESERVED at v1: seal=false is a 422 before anything happens, and true is the default',
    'getDoctor benchmark' => 'parsed and INERT until the benchmark exists (M3); benchmark stays null in the response',
    'getStreamEvents from' => 'an alias of since; the client sends since, and both with different values is a 422',
    'getStreamEvents to' => 'an alias of until; the client sends until, and both with different values is a 422',
];

/**
 * The representative StreamSpec: EVERY builder method, called once, with a value
 * the client accepts. The map drives the builder, and a test holds its keys equal to
 * the builder's methods by reflection, so a method added to StreamSpec that this leg
 * does not exercise is a red test rather than a body shape nobody validated.
 *
 * The captions methods are here since tmesis v0.3.0 (the plan's "the builder never
 * exposes captions" predates CaptionsSpec and is obsolete).
 *
 * @return array<string, list<mixed>>
 */
function everyBuilderMethod(): array
{
    return [
        'ladder' => ['abr-1080'],
        'segmentSeconds' => [6],
        'minKeyEpoch' => [KeyEpoch::fromInt(7)],
        'webhookUrl' => ['https://control.example.test/tmesis/webhooks'],
        'labels' => [['show' => '2041', 'venue' => 'atl']],
        // With a feed (v0.4.0): the request leg is what proves the rendered relay row
        // carries it in the shape RelayInput declares.
        'relays' => [[RelayInput::make('srt://relay.example.test:8890?streamid=abc', 'yt-main', true, 'rung:720p')]],
        'ingestRecordingEnabled' => [true],
        'ingestRetentionHours' => [72],
        // An absolute directory, not s3://: the next tmesis MINOR refuses s3:// here with
        // a 422 (Destination WP1, TMESIS-DELIVERY-3), and a path is valid on both.
        'ingestDestination' => ['/mnt/archive/ingest'],
        // Null beside the directory above: the two are never both set (v0.6.0), and
        // the named form has its own clientCalls() row, the switch.
        'ingestDestinationRef' => [null],
        'fallbackEndSeconds' => [600],
        'previewEnabled' => [true],
        'previewMaxMonitors' => [50],
        'captionsEnabled' => [true],
        'captionsAdvertise' => [true],
        'captionsProvider' => ['deepgram'],
        'captionsLanguages' => [['en']],
        // tmesis v0.8.0: a fight card's vocabulary, one duplicate spelling the node
        // removes; the builder sends the list as given.
        'captionsKeyterms' => [['Ilia Topuria', 'Cage Sport Network', 'State Farm Arena', 'Atlanta', 'atlanta']],
    ];
}

function representativeSpec(): StreamSpec
{
    $spec = StreamSpec::make();

    foreach (everyBuilderMethod() as $method => $arguments) {
        $spec = $spec->{$method}(...$arguments);
    }

    return $spec;
}

/**
 * Every builder method that takes null, given null: the PATCH that CLEARS, which is
 * a different body from the one that sets (an explicit null is mentioned and unsets
 * a nullable field, `docs/API.md` 9.6).
 */
/**
 * The artifact request values the leg sends: one generation, its inventory, a target
 * pair, one object (tmesis v0.5.0).
 */
const LEG_GENERATION = '01J8ZCK5R7V2QNX8RW4TKB6PD2';

const LEG_INVENTORY = '9f2c1d5e6a7b8c9d0e1f2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f';

const LEG_OBJECT = '01J8ZCK9T4N2QWX6VM3RKB9PD8';

function legSignedRequest(string $method, bool $withHeaders = true): SignedRequest
{
    return SignedRequest::make(
        $method,
        'https://archive.example.net/backup/csn/2026/show/show-ingest.ts?X-Amz-Signature=abc',
        $withHeaders ? ['x-amz-content-sha256' => ['UNSIGNED-PAYLOAD'], 'content-type' => 'video/mp2t'] : [],
        '2026-09-22T22:30:00.000Z',
    );
}

/**
 * Every PlanRequest field and every target shape: an HLS target to a configured
 * destination with a master name, an HLS target to a signed bucket with every
 * SignedDestination field and an initial authorization, and a reason.
 */
function representativePlan(): PlanRequest
{
    $signedTargetId = '01J8ZCK8T4N2QWX6VM3RKB9PD8';

    return PlanRequest::make(LEG_INVENTORY, 0, '01J8ZCK6R3M8QWX2VN5TKB7PD4')
        ->withReason('Archive replay and backup')
        ->withTargets(
            PlanTarget::hlsSealed('csn-vod', 'csn/2026/show/show-dvr-archive', 'playlist.m3u8', '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
            PlanTarget::hlsSealed(
                SignedDestination::make(
                    'us-east-1',
                    'backup',
                    'https://archive.example.net',
                    'path',
                    16,
                    4,
                    [InitialAuthorization::make(
                        'playlist.m3u8',
                        'backup/csn/2026/show/playlist.m3u8',
                        AuthorizationBundle::make(put: legSignedRequest('PUT', withHeaders: false)),
                    )],
                ),
                'backup/csn/2026/show',
                'playlist.m3u8',
                $signedTargetId,
            ),
        );
}

function clearingSpec(): StreamSpec
{
    return StreamSpec::make()
        ->minKeyEpoch(null)
        ->webhookUrl(null)
        ->ingestDestination(null)
        ->ingestDestinationRef(null)
        ->captionsProvider(null)
        ->captionsKeyterms(null)
        ->fallbackEndSeconds(null);
}

/**
 * One call through the real client for every wrapped operation, and more than one
 * where the client can send different shapes. Each row is the operationId the call
 * must hit and the call.
 *
 * @return array<string, array{0: string, 1: Closure(): mixed}>
 */
function clientCalls(): array
{
    $calls = [
        'getHealth' => ['getHealth', fn () => Tmesis::node()->getHealth()],
        'getPublicHealth' => ['getHealth', fn () => Tmesis::node()->getPublicHealth()],
        'getDoctor checks' => ['getDoctor', fn () => Tmesis::node()->getDoctor()],
        'getDoctor cached' => ['getDoctor', fn () => Tmesis::node()->getDoctor(false)],
        'getNodeStats' => ['getNodeStats', fn () => Tmesis::node()->getNodeStats()],
        'listStreams unfiltered' => ['listStreams', fn () => Tmesis::node()->listStreams()],
        'getStream bare' => ['getStream', fn () => Tmesis::node()->getStream('show-2041-main')],
        'getStream preview' => ['getStream', fn () => Tmesis::node()->getStream('show-2041-main', includePreview: true)],
        'getStream captions' => ['getStream', fn () => Tmesis::node()->getStream('show-2041-main', includeCaptions: true)],
        'getStream preview and captions (F2)' => ['getStream', fn () => Tmesis::node()->getStream('show-2041-main', includePreview: true, includeCaptions: true)],
        'getStreamStats' => ['getStreamStats', fn () => Tmesis::node()->streams()->getStreamStats('show-2041-main')],
        'getStreamEvents bare' => ['getStreamEvents', fn () => Tmesis::node()->streams()->getStreamEvents('show-2041-main')],
        'getStreamTranscript bare' => ['getStreamTranscript', fn () => Tmesis::node()->streams()->getStreamTranscript('show-2041-main')],
        'getStreamTranscript tail' => ['getStreamTranscript', fn () => Tmesis::node()->streams()->getStreamTranscript('show-2041-main', 'en', 12)],
        'createStream bare' => ['createStream', fn () => Tmesis::node()->streams()->createStream('show-2041-main')],
        'createStream every builder method' => ['createStream', fn () => Tmesis::node()->streams()->createStream('show-2041-main', representativeSpec())],
        'createStreamWithOutcome' => ['createStream', fn () => Tmesis::node()->streams()->createStreamWithOutcome('show-2041-main', representativeSpec())],
        'updateStream every builder method' => ['updateStream', fn () => Tmesis::node()->streams()->updateStream('show-2041-main', representativeSpec())],
        'updateStream clearing' => ['updateStream', fn () => Tmesis::node()->streams()->updateStream('show-2041-main', clearingSpec())],
        'createStream clearing labels' => ['createStream', fn () => Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->labels([]))],
        'updateStream clearing labels' => ['updateStream', fn () => Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->labels([]))],
        'deleteStream bare' => ['deleteStream', fn () => Tmesis::node()->streams()->deleteStream('show-2041-main')],
        'deleteStream grace and retain' => ['deleteStream', fn () => Tmesis::node()->streams()->deleteStream('show-2041-main', 30, true)],
        'kickStream' => ['kickStream', fn () => Tmesis::node()->streams()->kickStream('show-2041-main')],
        'takeoverStream' => ['takeoverStream', fn () => Tmesis::node()->streams()->takeoverStream('show-2041-main')],
        'pushKeyRevocation list' => ['pushKeyRevocation', fn () => Tmesis::node()->streams()->pushKeyRevocation(['show-2041-main', 'show-2042-main'])],
        'pushKeyRevocation keyed' => ['pushKeyRevocation', fn () => Tmesis::node()->streams()->pushKeyRevocation([3 => 'show-2041-main'])],
        'pushKeyRevocation clear' => ['pushKeyRevocation', fn () => Tmesis::node()->streams()->pushKeyRevocation([])],
        'listRelays' => ['listRelays', fn () => Tmesis::node()->relays()->listRelays('show-2041-main')],
        'getRelay' => ['getRelay', fn () => Tmesis::node()->relays()->getRelay('show-2041-main', 'yt-main')],
        'createRelay url only' => ['createRelay', fn () => Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('rtmp://live.example.test/app/key'))],
        'createRelay every field' => ['createRelay', fn () => Tmesis::node()->relays()->createRelay('show-2041-main', RelayInput::make('rtmp://live.example.test/app/key', 'yt-main', false))],
        'updateRelay url' => ['updateRelay', fn () => Tmesis::node()->relays()->updateRelay('show-2041-main', 'yt-main', RelayPatch::make('rtmp://live.example.test/app/other'))],
        'updateRelay park' => ['updateRelay', fn () => Tmesis::node()->relays()->updateRelay('show-2041-main', 'yt-main', RelayPatch::make(enabled: false))],
        'deleteRelay' => ['deleteRelay', fn () => Tmesis::node()->relays()->deleteRelay('show-2041-main', 'yt-main')],
        'stopRelays' => ['stopRelays', fn () => Tmesis::node()->relays()->stopRelays('show-2041-main')],
        'listArtifacts bare' => ['listArtifacts', fn () => Tmesis::node()->artifacts()->listArtifacts('show-2041-main')],
        'listArtifacts paged' => ['listArtifacts', fn () => Tmesis::node()->artifacts()->listArtifacts('show-2041-main', 50, 'opaque-cursor')],
        'getArtifact' => ['getArtifact', fn () => Tmesis::node()->artifacts()->getArtifact('show-2041-main', LEG_GENERATION)],
        'getArtifactInventory bare' => ['getArtifactInventory', fn () => Tmesis::node()->artifacts()->getArtifactInventory('show-2041-main', LEG_GENERATION)],
        'getArtifactInventory paged' => ['getArtifactInventory', fn () => Tmesis::node()->artifacts()->getArtifactInventory('show-2041-main', LEG_GENERATION, 1000, 'opaque-cursor')],
        'getTransferPlan' => ['getTransferPlan', fn () => Tmesis::node()->artifacts()->getTransferPlan('show-2041-main', LEG_GENERATION)],
        'replaceTransferPlan empty' => ['replaceTransferPlan', fn () => Tmesis::node()->artifacts()->replaceTransferPlan('show-2041-main', LEG_GENERATION, PlanRequest::make(LEG_INVENTORY, 0))],
        'replaceTransferPlan empty reason' => ['replaceTransferPlan', fn () => Tmesis::node()->artifacts()->replaceTransferPlan('show-2041-main', LEG_GENERATION, PlanRequest::make(LEG_INVENTORY, 1)->withReason(''))],
        'replaceTransferPlan every field' => ['replaceTransferPlan', fn () => Tmesis::node()->artifacts()->replaceTransferPlan('show-2041-main', LEG_GENERATION, representativePlan())],
        'replaceTransferPlan recordings' => ['replaceTransferPlan', fn () => Tmesis::node()->artifacts()->replaceTransferPlan('show-2041-main', LEG_GENERATION, PlanRequest::make(LEG_INVENTORY, 2)->withTargets(
            PlanTarget::recordingIngest('csn-vod', 'csn/2026/show/show-ingest.ts'),
            PlanTarget::recordingMaster(SignedDestination::make('us-east-1', 'backup'), 'backup/csn/2026/show/show-master.mp4'),
        ))],
        'listTransfers bare' => ['listTransfers', fn () => Tmesis::node()->artifacts()->listTransfers('show-2041-main', LEG_GENERATION)],
        'listTransfers paged' => ['listTransfers', fn () => Tmesis::node()->artifacts()->listTransfers('show-2041-main', LEG_GENERATION, 10, 'opaque-cursor')],
        'listTransferParts bare' => ['listTransferParts', fn () => Tmesis::node()->artifacts()->listTransferParts('show-2041-main', LEG_GENERATION, LEG_OBJECT)],
        'listTransferParts paged' => ['listTransferParts', fn () => Tmesis::node()->artifacts()->listTransferParts('show-2041-main', LEG_GENERATION, LEG_OBJECT, 128, 'opaque-cursor')],
        'supplyTransferAuthorization parts' => ['supplyTransferAuthorization', fn () => Tmesis::node()->artifacts()->supplyTransferAuthorization('show-2041-main', LEG_GENERATION, LEG_OBJECT, AuthorizationRequest::make(
            1, LEG_INVENTORY, '01J8ZCK8T4N2QWX6VM3RKB9PD8', 'backup/csn/2026/show/show-ingest.ts', 3, 'provider-upload-id',
            AuthorizationBundle::make(parts: [PartAuthorization::make(101, legSignedRequest('PUT'))], abort: legSignedRequest('DELETE', withHeaders: false)),
        ))],
        'supplyTransferAuthorization every operation' => ['supplyTransferAuthorization', fn () => Tmesis::node()->artifacts()->supplyTransferAuthorization('show-2041-main', LEG_GENERATION, LEG_OBJECT, AuthorizationRequest::make(
            1, LEG_INVENTORY, '01J8ZCK8T4N2QWX6VM3RKB9PD8', 'backup/csn/2026/show/show-ingest.ts', 1, null,
            AuthorizationBundle::make(
                put: legSignedRequest('PUT'),
                initiate: legSignedRequest('POST'),
                parts: [],
                complete: legSignedRequest('POST'),
                abort: legSignedRequest('DELETE'),
                head: legSignedRequest('HEAD', withHeaders: false),
            ),
        ))],
        'releaseArtifact' => ['releaseArtifact', fn () => Tmesis::node()->artifacts()->releaseArtifact('show-2041-main', LEG_GENERATION, ReleaseRequest::make(2, LEG_INVENTORY, 'All requested copies verified and accepted'))],
        'overrideArtifact override' => ['overrideArtifact', fn () => Tmesis::node()->artifacts()->overrideArtifact('show-2041-main', LEG_GENERATION, OverrideRequest::override(2, LEG_INVENTORY, 'Primary accepted; backup copy waived', ['01J8ZCK8T4N2QWX6VM3RKB9PD8']))],
        'overrideArtifact discard' => ['overrideArtifact', fn () => Tmesis::node()->artifacts()->overrideArtifact('show-2041-main', LEG_GENERATION, OverrideRequest::discard(0, LEG_INVENTORY, 'Empty plan, discard', []))],
        'overrideArtifact discard abandoned' => ['overrideArtifact', fn () => Tmesis::node()->artifacts()->overrideArtifact('show-2041-main', LEG_GENERATION, OverrideRequest::discardAbandoned('Crashed window, encoder shape changed'))],
        // tmesis v0.6.0 (LTWC): destination_ref, preview grants, markers.
        'updateStream switch to a named destination' => ['updateStream', fn () => Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->ingestDestination(null)->ingestDestinationRef('csn-vod'))],
        'createStream named destination and fallback' => ['createStream', fn () => Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->ingestDestinationRef('csn-vod')->fallbackEndSeconds(1))],
        'createPreviewGrant minimal' => ['createPreviewGrant', fn () => Tmesis::node()->previewGrants()->createPreviewGrant(PreviewGrantInput::make(['203.0.113.7'], 60))],
        'createPreviewGrant every field' => ['createPreviewGrant', fn () => Tmesis::node()->previewGrants()->createPreviewGrant(
            PreviewGrantInput::make(['203.0.113.7', '2001:db8::7'], 86400)->forStreams(['show-2041-main'])->withLabels(['panel_session' => 'p-8841']),
        )],
        'listPreviewGrants' => ['listPreviewGrants', fn () => Tmesis::node()->previewGrants()->listPreviewGrants()],
        'updatePreviewGrant' => ['updatePreviewGrant', fn () => Tmesis::node()->previewGrants()->updatePreviewGrant('01J8Z3N6Q4W5E6R7T8Y9V0H1XP', PreviewGrantPatch::make(3600))],
        'revokePreviewGrant' => ['revokePreviewGrant', fn () => Tmesis::node()->previewGrants()->revokePreviewGrant('01J8Z3N6Q4W5E6R7T8Y9V0H1XP')],
        'placeMarker minimal' => ['placeMarker', fn () => Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::out())],
        'placeMarker every field' => ['placeMarker', fn () => Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::in('break-2041-3-in')
            ->at('2026-08-28T19:27:24.006Z')->withClass('com.example.ads')->durationSeconds(30.0)->scte35('0xFC302000000000000000FFF00F05000000017FEFFE0029325E0000000000009A1A8B11'))],
        'placeMarker at now' => ['placeMarker', fn () => Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::out('break-2041-3')->at(null)->durationSeconds(0))],
        'listMarkers' => ['listMarkers', fn () => Tmesis::node()->markers()->listMarkers('show-2041-main')],
        // tmesis v0.6.0 (LTWB): transcode jobs and their generations.
        'createTranscodeJob minimal' => ['createTranscodeJob', fn () => Tmesis::node()->transcodeJobs()->createTranscodeJob(
            Spec::make(JobSource::url('https://uploads.example.net/abc.mov'), 'video-941-v1')->target(JobTarget::probe('tsp-media', 'videos/941/probe.json')),
        )],
        'createTranscodeJob every field' => ['createTranscodeJob', fn () => Tmesis::node()->transcodeJobs()->createTranscodeJob(representativeJob())],
        'listTranscodeJobs bare' => ['listTranscodeJobs', fn () => Tmesis::node()->transcodeJobs()->listTranscodeJobs()],
        'listTranscodeJobs paged' => ['listTranscodeJobs', fn () => Tmesis::node()->transcodeJobs()->listTranscodeJobs('running', 50, 'opaque-cursor')],
        'getTranscodeJob' => ['getTranscodeJob', fn () => Tmesis::node()->transcodeJobs()->getTranscodeJob(LEG_JOB)],
        'cancelTranscodeJob' => ['cancelTranscodeJob', fn () => Tmesis::node()->transcodeJobs()->cancelTranscodeJob(LEG_JOB)],
        // tmesis v0.9.0: the told jobs pause. `until` is a required key, so the
        // until-cleared body carries it as null; the reason at the 200-byte cap is
        // multibyte (50 four-byte characters).
        'setTranscodePause until' => ['setTranscodePause', fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('show: fight night 12', new DateTimeImmutable('2026-08-28T19:00:00Z')))],
        'setTranscodePause until cleared' => ['setTranscodePause', fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::untilCleared('show: fight night 12'))],
        'setTranscodePause offset string' => ['setTranscodePause', fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make('x', '2026-08-28T15:00:00-04:00'))],
        'setTranscodePause reason at the byte cap' => ['setTranscodePause', fn () => Tmesis::node()->transcodeJobs()->setTranscodePause(JobPauseRequest::make(str_repeat("\u{1F3AC}", 50)))],
        'getTranscodePause' => ['getTranscodePause', fn () => Tmesis::node()->transcodeJobs()->getTranscodePause()],
        'clearTranscodePause' => ['clearTranscodePause', fn () => Tmesis::node()->transcodeJobs()->clearTranscodePause()],
        'listJobArtifacts bare' => ['listJobArtifacts', fn () => Tmesis::node()->jobArtifacts()->listJobArtifacts(LEG_JOB)],
        'listJobArtifacts paged' => ['listJobArtifacts', fn () => Tmesis::node()->jobArtifacts()->listJobArtifacts(LEG_JOB, 50, 'opaque-cursor')],
        'getJobArtifact' => ['getJobArtifact', fn () => Tmesis::node()->jobArtifacts()->getJobArtifact(LEG_JOB, LEG_GENERATION)],
        'getJobArtifactInventory bare' => ['getJobArtifactInventory', fn () => Tmesis::node()->jobArtifacts()->getJobArtifactInventory(LEG_JOB, LEG_GENERATION)],
        'getJobArtifactInventory paged' => ['getJobArtifactInventory', fn () => Tmesis::node()->jobArtifacts()->getJobArtifactInventory(LEG_JOB, LEG_GENERATION, 1000, 'opaque-cursor')],
        'getJobTransferPlan' => ['getJobTransferPlan', fn () => Tmesis::node()->jobArtifacts()->getJobTransferPlan(LEG_JOB, LEG_GENERATION)],
        'replaceJobTransferPlan every job kind' => ['replaceJobTransferPlan', fn () => Tmesis::node()->jobArtifacts()->replaceJobTransferPlan(LEG_JOB, LEG_GENERATION, PlanRequest::make(LEG_INVENTORY, 1)->withTargets(
            PlanTarget::jobHls('tsp-media', 'videos/941', 'master.m3u8'),
            PlanTarget::jobMp4('tsp-media', 'videos/941/mp4'),
            PlanTarget::jobThumbnails(SignedDestination::make('us-east-1', 'tsp-assets'), 'thumbs/941'),
            PlanTarget::jobTranscript('tsp-media', 'videos/941/captions'),
            PlanTarget::jobAudio('tsp-media', 'videos/941/audio.m4a'),
            PlanTarget::jobProbe('tsp-media', 'videos/941/probe.json'),
        ))],
        'listJobTransfers bare' => ['listJobTransfers', fn () => Tmesis::node()->jobArtifacts()->listJobTransfers(LEG_JOB, LEG_GENERATION)],
        'listJobTransfers paged' => ['listJobTransfers', fn () => Tmesis::node()->jobArtifacts()->listJobTransfers(LEG_JOB, LEG_GENERATION, 10, 'opaque-cursor')],
        'listJobTransferParts bare' => ['listJobTransferParts', fn () => Tmesis::node()->jobArtifacts()->listJobTransferParts(LEG_JOB, LEG_GENERATION, LEG_OBJECT)],
        'listJobTransferParts paged' => ['listJobTransferParts', fn () => Tmesis::node()->jobArtifacts()->listJobTransferParts(LEG_JOB, LEG_GENERATION, LEG_OBJECT, 128, 'opaque-cursor')],
        'supplyJobTransferAuthorization' => ['supplyJobTransferAuthorization', fn () => Tmesis::node()->jobArtifacts()->supplyJobTransferAuthorization(LEG_JOB, LEG_GENERATION, LEG_OBJECT, AuthorizationRequest::make(
            1, LEG_INVENTORY, '01J8ZCK8T4N2QWX6VM3RKB9PD8', 'videos/941/audio.m4a', 1, null,
            AuthorizationBundle::make(put: legSignedRequest('PUT')),
        ))],
        'releaseJobArtifact' => ['releaseJobArtifact', fn () => Tmesis::node()->jobArtifacts()->releaseJobArtifact(LEG_JOB, LEG_GENERATION, ReleaseRequest::make(1, LEG_INVENTORY, 'Copied to tsp-media'))],
        'overrideJobArtifact' => ['overrideJobArtifact', fn () => Tmesis::node()->jobArtifacts()->overrideJobArtifact(LEG_JOB, LEG_GENERATION, OverrideRequest::discard(1, LEG_INVENTORY, 'Superseded by v3', ['01J8ZCK8T4N2QWX6VM3RKB9PD8']))],
        // tmesis v0.8.0: the told master (both target shapes, with and without a
        // reason) and the purge (a finalized generation, and an abandoned one).
        'createMaster configured' => ['createMaster', fn () => Tmesis::node()->artifacts()->createMaster('show-2041-main', MasterRequest::make(
            [LEG_GENERATION, '01J8ZCM1T8V3QNX7RW2KPB4DF9'],
            PlanTarget::recordingMaster('tsp-media', 'shows/2041/show-2041-main-master.mp4', '01J8ZCQ3P6N1QWX8VM4RKB2PD7'),
            '01J8ZCQ3K9R4TWX2VN6MPB8DF1',
        )->withReason('Show 2041 ended; combine the parts'))],
        'createMaster signed' => ['createMaster', fn () => Tmesis::node()->artifacts()->createMaster('show-2041-main', MasterRequest::make(
            [LEG_GENERATION],
            PlanTarget::recordingMaster(SignedDestination::make('us-east-1', 'backup', 'https://archive.example.net', 'path', 16, 4), 'backup/2041/master.mp4'),
        ))],
        'purgeArtifact' => ['purgeArtifact', fn () => Tmesis::node()->artifacts()->purgeArtifact('show-2041-main', LEG_GENERATION, ReleaseRequest::make(2, LEG_INVENTORY, 'Copied to tsp-media; free the disk now'))],
        'purgeArtifact abandoned' => ['purgeArtifact', fn () => Tmesis::node()->artifacts()->purgeArtifact('show-2041-main', LEG_GENERATION, ReleaseRequest::purgeAbandoned('Crashed window, nothing to keep'))],
        'purgeJobArtifact' => ['purgeJobArtifact', fn () => Tmesis::node()->jobArtifacts()->purgeJobArtifact(LEG_JOB, LEG_GENERATION, ReleaseRequest::make(1, LEG_INVENTORY, 'Delivered; purge the transcoder'))],
    ];

    foreach (JobStatus::STATES as $state) {
        $calls["listTranscodeJobs {$state}"] = ['listTranscodeJobs', fn () => Tmesis::node()->transcodeJobs()->listTranscodeJobs($state)];
    }

    // Every value the client can produce for a closed set, one row each: the six
    // states, both orders, both text renderings.
    foreach (Streams::STATES as $state) {
        $calls["listStreams {$state}"] = ['listStreams', fn () => Tmesis::node()->listStreams($state)];
    }

    foreach (Streams::ORDERS as $order) {
        $calls["getStreamEvents window {$order}"] = ['getStreamEvents', fn () => Tmesis::node()->streams()->getStreamEvents(
            'show-2041-main',
            new DateTimeImmutable('2026-09-22T18:00:00Z'),
            new DateTimeImmutable('2026-09-22T19:00:00+00:00'),
            50,
            $order,
        )];
    }

    foreach (array_keys(Streams::TRANSCRIPT_TEXT_FORMATS) as $format) {
        $calls["getStreamTranscriptText {$format}"] = ['getStreamTranscript', fn () => Tmesis::node()->streams()->getStreamTranscriptText('show-2041-main', $format, 'en', 0)];
    }

    return $calls;
}

/**
 * The operations the package wraps, derived: no milestone, no listener, not local-only
 * (`leaveFleet`, since v0.7.0). The same
 * rule as the routes leg, read here from the document rather than shared, so this
 * file runs on its own.
 *
 * @return array<string, array{path: string, method: string, operation: array<string, mixed>}>
 */
function requestOperations(): array
{
    /** @var array<string, array<string, mixed>> $paths */
    $paths = ContractDir::readJson(ContractDir::openapi())['paths'];

    $out = [];

    foreach ($paths as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! is_array($operation) || ! is_string($operation['operationId'] ?? null)) {
                continue;
            }

            if (isset($operation['x-tmesis-milestone']) || isset($operation['x-tmesis-listener']) || ($operation['x-tmesis-local-only'] ?? false) === true) {
                continue;
            }

            $out[$operation['operationId']] = ['path' => (string) $path, 'method' => (string) $method, 'operation' => $operation];
        }
    }

    ksort($out);

    return $out;
}

/**
 * The component name under the operation's `requestBody` application/json schema,
 * or null when the document declares no JSON body for it.
 */
function requestComponentFor(string $operationId): ?string
{
    $ref = requestOperations()[$operationId]['operation']['requestBody']['content']['application/json']['schema']['$ref'] ?? null;

    return is_string($ref) ? basename($ref) : null;
}

/**
 * The operation's `in: query` parameters by name, each with the JSON pointer to its
 * schema inside the document. Path parameters are the URL the client already builds.
 *
 * @return array<string, array{schema: array<string, mixed>, pointer: string}>
 */
function queryParametersFor(string $operationId): array
{
    $entry = requestOperations()[$operationId];
    $escape = static fn (string $token): string => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $token));

    $out = [];

    foreach ((array) ($entry['operation']['parameters'] ?? []) as $index => $parameter) {
        if (($parameter['in'] ?? null) !== 'query') {
            continue;
        }

        $out[(string) $parameter['name']] = [
            'schema' => (array) ($parameter['schema'] ?? []),
            'pointer' => '/paths/'.$escape($entry['path']).'/'.$entry['method'].'/parameters/'.$index.'/schema',
        ];
    }

    return $out;
}

/**
 * Operations whose success answer has NO body, with that status. They have no
 * response fixture (the responses leg holds the fixtures to the operations with a
 * component), so the leg answers them empty. `clearTranscodePause` (tmesis v0.9.0) is
 * the first wrapped one.
 *
 * @var array<string, int>
 */
const BODILESS_SUCCESS = ['clearTranscodePause' => 204];

/**
 * The fake answer for one operation: its fixture, or an empty BODILESS_SUCCESS.
 */
function fixtureResponse(string $operationId): PromiseInterface
{
    if (isset(BODILESS_SUCCESS[$operationId])) {
        return Http::response('', BODILESS_SUCCESS[$operationId]);
    }

    $fixture = dirname(__DIR__, 2).'/Fixtures/responses/'.$operationId.'.json';

    return Http::response((string) file_get_contents($fixture), 200, ['Content-Type' => 'application/json']);
}

/**
 * Drive one call with every response answered from the operation's fixture, and
 * hand back the one request it sent.
 */
function sendThroughClient(string $operationId, Closure $call): Request
{
    Http::fake(['*' => fixtureResponse($operationId)]);

    $call();

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1);

    return $recorded[0][0];
}

/**
 * The query a request carried, by name, as the strings that went on the wire.
 *
 * @return array<string, string>
 */
function sentQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * A query string value read the way the node's form-style parser reads it, by the
 * parameter schema's type: `true`/`false` a boolean, an integer an integer, anything
 * else the string it arrived as. A value that will not read as its type stays a
 * string and fails the schema, which is the point.
 */
function readQueryValue(string $value, array $schema): mixed
{
    return match ($schema['type'] ?? null) {
        'boolean' => match ($value) {
            'true' => true,
            'false' => false,
            default => $value,
        },
        'integer' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
        default => $value,
    };
}

it('drives every operation the package wraps, and only those', function (): void {
    $driven = array_values(array_unique(array_map(static fn (array $row): string => $row[0], clientCalls())));
    sort($driven);

    expect($driven)->toBe(array_keys(requestOperations()));
});

it('sends a JSON body exactly where the document declares one', function (string $operationId, Closure $call): void {
    $request = sendThroughClient($operationId, $call);
    $method = strtoupper(requestOperations()[$operationId]['method']);

    expect($request->method())->toBe($method);

    if (requestComponentFor($operationId) === null) {
        // kick, takeover and stopRelays read no body, and the reads take none: a body
        // there says something the contract does not define.
        expect($request->body())->toBe('', "{$operationId} sent a body the document does not declare.");

        return;
    }

    expect(requestOperations()[$operationId]['operation']['requestBody']['required'] ?? false)->toBeTrue()
        ->and($request->body())->not->toBe('')
        ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue();
})->with(clientCalls());

it('validates every body against its request component', function (string $operationId, Closure $call): void {
    $component = requestComponentFor($operationId);

    if ($component === null) {
        expect(true)->toBeTrue();

        return;
    }

    $request = sendThroughClient($operationId, $call);
    $body = json_decode($request->body(), false, 512, JSON_THROW_ON_ERROR);

    SchemaValidator::forOpenApi()->assertValid($body, '/components/schemas/'.$component, "The {$operationId} body");

    // RecordingSpec no longer declares `master` (tmesis v0.8.0: the master is a
    // request, createMaster, and recording.master is 422 on a stream body, any value).
    // The schema now refuses it; this keeps the rule named where a body is checked.
    expect(property_exists($body->recording ?? new stdClass, 'master'))->toBeFalse("{$operationId} sent recording.master, which is 422 unknown field since tmesis v0.8.0.");
})->with(clientCalls());

it('sends only query parameters the document declares, with values it allows', function (string $operationId, Closure $call): void {
    $request = sendThroughClient($operationId, $call);
    $declared = queryParametersFor($operationId);
    $validator = SchemaValidator::forOpenApi();

    foreach (sentQuery($request) as $name => $value) {
        expect(array_key_exists($name, $declared))->toBeTrue("{$operationId} sent the undeclared query parameter [{$name}].");

        $allowed = REQUEST_ALLOW_LIST["{$operationId} {$name} {$value}"] ?? null;

        if ($allowed !== null) {
            continue;
        }

        $validator->assertValid(
            readQueryValue($value, $declared[$name]['schema']),
            $declared[$name]['pointer'],
            "{$operationId} {$name}={$value}",
        );
    }
})->with(clientCalls());

it('accounts for every declared query parameter it never sends', function (): void {
    // ONE fake, answering whichever fixture the current call needs: Http::fake()
    // called in a loop adds stubs rather than replacing them.
    $current = '';
    Http::fake(function () use (&$current) {
        return fixtureResponse($current);
    });

    $sent = [];

    foreach (clientCalls() as [$operationId, $call]) {
        $current = $operationId;
        $before = count(Http::recorded());
        $call();
        $recorded = Http::recorded();

        expect($recorded)->toHaveCount($before + 1);

        foreach (array_keys(sentQuery($recorded[$before][0])) as $name) {
            $sent["{$operationId} {$name}"] = true;
        }
    }

    $unsent = [];

    foreach (array_keys(requestOperations()) as $operationId) {
        foreach (array_keys(queryParametersFor($operationId)) as $name) {
            if (! isset($sent["{$operationId} {$name}"])) {
                $unsent[] = "{$operationId} {$name}";
            }
        }
    }

    $declared = array_keys(QUERY_PARAMETERS_NOT_SENT);
    sort($unsent);
    sort($declared);

    expect($unsent)->toBe($declared);

    foreach (QUERY_PARAMETERS_NOT_SENT as $key => $reason) {
        expect(strlen($reason))->toBeGreaterThan(20, "The entry for {$key} does not say why.");
    }
});

it('refuses nothing the document allows: every closed set equals its enum, both directions', function (): void {
    $enum = static fn (string $operationId, string $name): array => (array) (queryParametersFor($operationId)[$name]['schema']['enum'] ?? []);

    // `include` is a comma set since v0.4.0 (F2 fixed): the closed set of TOKENS is
    // the first alternation of its pattern, `^(preview|captions)(,(preview|captions))*$`,
    // read out of the document rather than typed here, so a token added upstream
    // fails this the same way an enum value would.
    $includePattern = (string) (queryParametersFor('getStream')['include']['schema']['pattern'] ?? '');
    preg_match('/^\^\(([a-z|]+)\)/', $includePattern, $tokens);
    $includeTokens = explode('|', $tokens[1] ?? '');

    // The rc5 lag (input_lost) would have been red here the day the node added it.
    expect(Streams::STATES)->toEqualCanonicalizing($enum('listStreams', 'state'))
        ->and(Streams::ORDERS)->toEqualCanonicalizing($enum('getStreamEvents', 'order'))
        ->and($includePattern)->not->toBe('')
        ->and([Streams::INCLUDE_PREVIEW, Streams::INCLUDE_CAPTIONS])->toEqualCanonicalizing($includeTokens)
        // json is the typed feed, getStreamTranscript(), which sends no format.
        ->and([...array_keys(Streams::TRANSCRIPT_TEXT_FORMATS), 'json'])->toEqualCanonicalizing($enum('getStreamTranscript', 'format'));
});

it('still needs every allow-list entry, and each is what the client really sends', function (): void {
    // TMESIS-API-6 landed on v0.4.0 and the document took the set, so this went red
    // on F2 and the repin deleted the entry rather than carrying a dead
    // one. The list is empty now; the loop stays so the next entry gets the same
    // treatment, and the two-token send below proves the set validates as a whole.
    $validator = SchemaValidator::forOpenApi();

    foreach (array_keys(REQUEST_ALLOW_LIST) as $key) {
        [$operationId, $name, $value] = explode(' ', $key, 3);
        $parameter = queryParametersFor($operationId)[$name];

        expect($validator->isValid(readQueryValue($value, $parameter['schema']), $parameter['pointer']))
            ->toBeFalse("[{$key}] now validates against the document; remove it from REQUEST_ALLOW_LIST.");
    }

    expect(Streams::includeTokens(true, true))->toBe('preview,captions')
        ->and(array_keys(REQUEST_ALLOW_LIST))->toBe([]);

    // The set itself and each token of it validate against the document now.
    $pointer = queryParametersFor('getStream')['include']['pointer'];
    $validator->assertValid((string) Streams::includeTokens(true, true), $pointer, 'include preview,captions');

    foreach (explode(',', (string) Streams::includeTokens(true, true)) as $token) {
        $validator->assertValid($token, $pointer, "include token {$token}");
    }
});

it('exercises every StreamSpec builder method in the representative spec', function (): void {
    $builders = [];

    // A builder method returns the builder. How reflection SPELLS a `self` return
    // differs by PHP version: 8.4 answers 'self', 8.5 answers the resolved class name
    // (reproduced on 8.4.17 and 8.5.2), so every spelling that means this class counts.
    $meansStreamSpec = ['self', 'static', StreamSpec::class];

    foreach ((new ReflectionClass(StreamSpec::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();

        if ($method->isStatic() || ! $type instanceof ReflectionNamedType || ! in_array($type->getName(), $meansStreamSpec, true)) {
            continue;
        }

        $builders[] = $method->getName();
    }

    $exercised = array_keys(everyBuilderMethod());
    sort($builders);
    sort($exercised);

    // Never vacuous: an empty reflection is a broken filter, not a builder with no
    // methods (it was, on 8.5, before the spellings above).
    expect($builders)->not->toBeEmpty('Reflection found no StreamSpec builder methods; the return-type filter is broken.')
        ->and($exercised)->toBe($builders);
});

it('is strict: every request component refuses a field it does not name', function (): void {
    // The negative. A validator is only worth running if the schema can say no: an
    // unknown field on the rendered body of each component, top level and nested,
    // fails. The request components and every one they reach are closed.
    /** @var array<string, array<string, mixed>> $components */
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    $requestComponents = [];

    foreach (requestOperations() as $operationId => $entry) {
        $component = requestComponentFor($operationId);

        if ($component !== null) {
            $requestComponents[$component] = true;
        }
    }

    // And the nested ones they $ref, to a fixed point.
    $queue = array_keys($requestComponents);

    while ($queue !== []) {
        $name = array_shift($queue);
        preg_match_all('#"\$ref":"\#/components/schemas/([A-Za-z0-9]+)"#', (string) json_encode($components[$name], JSON_UNESCAPED_SLASHES), $matches);

        foreach ($matches[1] as $nested) {
            if (! isset($requestComponents[$nested])) {
                $requestComponents[$nested] = true;
                $queue[] = $nested;
            }
        }
    }

    ksort($requestComponents);

    // Twenty since v0.5.0: the ten artifact request components joined. Thirty-two since
    // v0.8.0: MasterRequest, the told master's body, joined the v0.6.0 thirty-one.
    // Thirty-three since v0.9.0: JobPauseRequest, the jobs pause body.
    expect(array_keys($requestComponents))->toBe([
        'AudioOpts',
        'AuthorizationBundle', 'AuthorizationRequest', 'CaptionsSpec', 'CreateStreamRequest',
        'FallbackSpec',
        'IngestRecordingSpec', 'InitialAuthorization',
        'JobPauseRequest', 'JobSignedTarget', 'JobSource', 'JobTarget', 'MP4Opts',
        'MarkerRequest', 'MasterRequest',
        'OverrideRequest', 'PartAuthorization',
        'PlanRequest', 'PlanTarget',
        'PreviewGrantInput', 'PreviewGrantPatch',
        'PreviewSpec', 'RecordingSpec', 'RelayInput', 'RelayPatch',
        'ReleaseRequest', 'RevocationRequest', 'SignedDestination', 'SignedRequest',
        'Spec',
        'StreamSpec',
        'ThumbnailOpts', 'TranscriptOpts',
    ]);

    // The smallest body each one accepts: every required field, nothing else.
    $signed = ['method' => 'PUT', 'url' => 'https://archive.example.net/k', 'headers' => new stdClass, 'expires_at' => '2026-09-22T22:30:00.000Z'];
    $bundle = ['put' => null, 'initiate' => null, 'parts' => null, 'complete' => null, 'abort' => null, 'head' => null];
    $minimalBodies = [
        'CreateStreamRequest' => ['id' => 'show-2041-main'],
        'RelayInput' => ['url' => 'rtmp://live.example.test/app/key'],
        'AuthorizationBundle' => $bundle,
        'AuthorizationRequest' => ['plan_revision' => 1, 'inventory_sha256' => LEG_INVENTORY, 'target_id' => 't', 'object_key' => 'k', 'authorization_revision' => 1, 'multipart_upload_id' => null, 'requests' => (object) $bundle],
        'InitialAuthorization' => ['artifact_path' => 'playlist.m3u8', 'plan_revision' => 1, 'inventory_sha256' => LEG_INVENTORY, 'target_id' => 't', 'object_key' => 'k', 'authorization_revision' => 1, 'multipart_upload_id' => null, 'requests' => (object) $bundle],
        'OverrideRequest' => OverrideRequest::discardAbandoned('abandoned')->toArray(),
        'PartAuthorization' => ['part_number' => 1, 'request' => (object) $signed],
        'PlanRequest' => PlanRequest::make(LEG_INVENTORY, 0)->toArray(),
        'PlanTarget' => ['id' => 't', 'artifact' => 'hls_sealed'],
        'ReleaseRequest' => ReleaseRequest::make(1, LEG_INVENTORY, 'released')->toArray(),
        'SignedDestination' => ['region' => 'us-east-1', 'bucket' => 'backup'],
        'SignedRequest' => $signed,
        'PreviewGrantInput' => ['ips' => ['203.0.113.7'], 'ttl_seconds' => 60],
        'PreviewGrantPatch' => ['ttl_seconds' => 60],
        'MarkerRequest' => ['kind' => 'out'],
        'MasterRequest' => ['request_id' => '01J8ZCQ3K9R4TWX2VN6MPB8DF1', 'sources' => [LEG_GENERATION], 'target' => ['id' => 't', 'artifact' => 'recording_master']],
        'Spec' => ['source' => new stdClass, 'outputs' => ['probe'], 'targets' => new stdClass],
        'JobTarget' => ['id' => 't'],
        'JobSignedTarget' => ['region' => 'us-east-1', 'bucket' => 'tsp-assets'],
        'JobPauseRequest' => JobPauseRequest::untilCleared('show: fight night 12')->toArray(),
    ];

    $validator = SchemaValidator::forOpenApi();

    foreach (array_keys($requestComponents) as $name) {
        expect($components[$name]['additionalProperties'] ?? null)->toBeFalse("{$name} is open to unknown fields.");

        $minimal = json_decode((string) json_encode($minimalBodies[$name] ?? new stdClass), false);

        expect($validator->isValid($minimal, '/components/schemas/'.$name))->toBeTrue("{$name}'s minimal body does not validate.")
            ->and($validator->isValid((object) ((array) $minimal + ['encryption' => ['mode' => 'aes']]), '/components/schemas/'.$name))->toBeFalse("{$name} accepted an unknown field.");
    }

    // Nested: an unknown key under the builder's own nested objects.
    $body = json_decode((string) json_encode(representativeSpec()->toArray()), false);
    $body->captions->translate = true;

    expect($validator->isValid($body, '/components/schemas/StreamSpec'))->toBeFalse();

    // JobPauseRequest's `until` is a REQUIRED key (null allowed): a body without it is
    // refused, which is why toArray() always carries it (tmesis v0.9.0).
    expect($validator->isValid((object) ['reason' => 'show'], '/components/schemas/JobPauseRequest'))->toBeFalse();
});

/**
 * Every OPEN MAP in the request components (an `object` whose additionalProperties is
 * a schema, not false), reached from the request bodies, and what the builder does
 * with it. Keyed by dotted path. PHP encodes an empty array as a JSON array, so each
 * one the builder can render empty must go out as `{}`; the walk below derives the
 * set and holds it EQUAL to these keys, so a map added upstream is named here.
 *
 * @var array<string, string>
 */
const REQUEST_OPEN_MAPS = [
    'labels' => 'StreamSpec::labels(); labels([]) clears the set and goes out as {} through toRequestBody(). Also PreviewGrantInput\'s labels (v0.6.0): withLabels([]) sends no labels at all',
    'targets' => 'Spec::target() (v0.6.0): the map from each output to its JobTargets, never empty, since the outputs are derived from it and a job with none is refused before rendering',
    'requests.put.headers' => 'SignedRequest::toArray(); an empty header set goes out as {}, the refresh rows above send both',
    'requests.initiate.headers' => 'SignedRequest::toArray(), the same leaf as requests.put.headers',
    'requests.complete.headers' => 'SignedRequest::toArray(), the same leaf as requests.put.headers',
    'requests.abort.headers' => 'SignedRequest::toArray(), the same leaf as requests.put.headers',
    'requests.head.headers' => 'SignedRequest::toArray(), the same leaf as requests.put.headers; the head row sends it empty',
];

it('accounts for every open map in the request components', function (): void {
    /** @var array<string, array<string, mixed>> $components */
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    $found = [];

    $walk = function (string $component, string $prefix) use (&$walk, &$found, $components): void {
        foreach ((array) ($components[$component]['properties'] ?? []) as $name => $property) {
            $path = $prefix === '' ? (string) $name : $prefix.'.'.$name;

            foreach ([$property, ...(array) ($property['oneOf'] ?? [])] as $schema) {
                $ref = $schema['$ref'] ?? null;

                if (is_string($ref)) {
                    $walk(basename($ref), $path);
                }

                if (($schema['type'] ?? null) === 'object' && is_array($schema['additionalProperties'] ?? null)) {
                    $found[$path] = true;
                }
            }
        }
    };

    foreach (array_keys(requestOperations()) as $operationId) {
        $component = requestComponentFor($operationId);

        if ($component !== null) {
            $walk($component, '');
        }
    }

    $found = array_keys($found);
    $declared = array_keys(REQUEST_OPEN_MAPS);
    sort($found);
    sort($declared);

    expect($found)->toBe($declared);
});

it('sends an empty label map as a JSON object, and keeps the PHP array in toArray()', function (): void {
    // labels([]) CLEARS the set: `{}` replaces it with nothing at the node (PatchStream
    // stores a non-nil empty map), the same end state as the null the document names.
    // `[]` would be a JSON type error and a 422. The two clearing rows in clientCalls()
    // hold the body to the components; this holds the exact bytes and the data view.
    $request = sendThroughClient('updateStream', fn () => Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->labels([])));

    expect($request->body())->toBe('{"labels":{}}')
        ->and(StreamSpec::make()->labels([])->toArray())->toBe(['labels' => []])
        ->and(StreamSpec::make()->labels(['show' => '2041'])->toRequestBody())->toBe(['labels' => ['show' => '2041']]);
});

/*
 * tmesis v0.6.0 (LTWB): the transcode job request.
 */

const LEG_JOB = 'job_01J8ZCA2R7F3H9NQ4KTX8VD5YB';

/**
 * The representative Spec: every output, every option object with every field, both
 * target kinds (configured and signed), both location fields and a master name.
 */
function representativeJob(): Spec
{
    return Spec::make(JobSource::destinationRef('tsp-media', 'uploads/abc.mov'), 'video-941-v2')
        ->ladder('vod-1080p')
        ->target(
            JobTarget::hlsLadder('tsp-media', 'videos/941', 'master.m3u8', '01J8ZCK7T4N2QWX6VM3RKB9PD8'),
            JobTarget::hlsLadder(JobSignedTarget::make('us-east-1', 'backup', 'https://s3.example.net', 'path', 64, 16), 'backup/videos/941'),
            JobTarget::mp4('tsp-media', 'videos/941/mp4'),
            JobTarget::audio('tsp-media', 'videos/941/audio.m4a'),
            JobTarget::thumbnails('tsp-assets', 'thumbs/941'),
            JobTarget::probe('tsp-media', 'videos/941/probe.json'),
            JobTarget::transcript('tsp-media', 'videos/941/captions'),
        )
        ->callbackUrl('https://app.example.com/api/webhooks/tmesis')
        ->priority('high')
        ->labels(['video_id' => '941'])
        ->timeoutSeconds(7200)
        ->audio(AudioOpts::make('opus', 96))
        ->thumbnails(ThumbnailOpts::make(5, 320, 12, [0, 12.5], [50], 3))
        ->mp4(MP4Opts::make(['1080p', '720p']))
        ->transcript(TranscriptOpts::make('deepgram', 'en', ['vtt', 'json']));
}

it('refuses nothing the job routes allow: the job state filter equals its enum, and every output and kind is the document\'s', function (): void {
    /** @var array<string, array<string, mixed>> $components */
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    expect(JobStatus::STATES)->toEqualCanonicalizing((array) (queryParametersFor('listTranscodeJobs')['state']['schema']['enum'] ?? []))
        ->and(JobStatus::STATES)->toEqualCanonicalizing($components['JobStatus']['properties']['state']['enum'])
        ->and(JobStatus::PRIORITIES)->toEqualCanonicalizing($components['Spec']['properties']['priority']['enum'])
        ->and(Spec::OUTPUTS)->toEqualCanonicalizing($components['JobOutputReport']['properties']['kind']['enum'])
        ->and(JobStatus::STAGES)->toEqualCanonicalizing($components['JobError']['properties']['stage']['enum'])
        ->and(AudioOpts::FORMATS)->toEqualCanonicalizing($components['AudioOpts']['properties']['format']['enum'])
        ->and(ArtifactStatus::KINDS)->toEqualCanonicalizing($components['ArtifactStatus']['properties']['kind']['enum'])
        ->and(ArtifactStatus::KINDS)->toEqualCanonicalizing($components['PlanTarget']['properties']['artifact']['enum'])
        ->and(array_values(Spec::GENERATION_KINDS))->toEqualCanonicalizing(ArtifactStatus::JOB_KINDS);
});

it('never exposes a reserved or removed job field', function (): void {
    $body = representativeJob()->toArray();

    expect($body)->not->toHaveKey('destination')
        ->and($body['source'])->not->toHaveKey('upload_id')
        ->and(array_intersect(['storyboard', 'bif', 'channel_ready'], Spec::OUTPUTS))->toBe([]);
});
