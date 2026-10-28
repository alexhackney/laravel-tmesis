<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactPage;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\DecisionStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryPage;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\PartPage;
use AlexHackney\Tmesis\Data\Artifacts\PlanStatus;
use AlexHackney\Tmesis\Data\Artifacts\TransferPage;
use AlexHackney\Tmesis\Data\Jobs\JobAccepted;
use AlexHackney\Tmesis\Data\Jobs\JobCancelled;
use AlexHackney\Tmesis\Data\Jobs\JobListPage;
use AlexHackney\Tmesis\Data\Jobs\JobPauseResponse;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Markers\MarkerListResponse;
use AlexHackney\Tmesis\Data\Markers\MarkerResponse;
use AlexHackney\Tmesis\Data\Node\DoctorResponse;
use AlexHackney\Tmesis\Data\Node\HealthFull;
use AlexHackney\Tmesis\Data\Node\NodeStats;
use AlexHackney\Tmesis\Data\Preview\PreviewGrant;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantListResponse;
use AlexHackney\Tmesis\Data\Relays\RelayListResponse;
use AlexHackney\Tmesis\Data\Relays\RelayRecord;
use AlexHackney\Tmesis\Data\Relays\RelayStopResponse;
use AlexHackney\Tmesis\Data\Streams\PatchResponse;
use AlexHackney\Tmesis\Data\Streams\RevocationResponse;
use AlexHackney\Tmesis\Data\Streams\SealResponse;
use AlexHackney\Tmesis\Data\Streams\SessionActionResponse;
use AlexHackney\Tmesis\Data\Streams\StreamEventList;
use AlexHackney\Tmesis\Data\Streams\StreamList;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Data\Streams\StreamStats;
use AlexHackney\Tmesis\Data\Streams\TranscriptResponse;
use AlexHackney\Tmesis\Tests\TestCase;

pest()->extends(TestCase::class)->in('Unit');
pest()->extends(TestCase::class)->in('Feature');
pest()->extends(TestCase::class)->in('Contract');

/*
 * Shared contract helpers.
 *
 * They live here rather than in whichever leg declared them first because three legs
 * call them across two directories, and a global function declared inside one test
 * file makes the others runnable only when that file happens to have been loaded too.
 */

/**
 * Every operation this package wraps, with the DTO it decodes into.
 *
 * 55 of the 56 operations with no `x-tmesis-milestone`, no `x-tmesis-listener` and no
 * `x-tmesis-local-only` (the WHEP routes are off-listener since rc7 and are asserted
 * NOT wrapped; the ten artifact operations joined at tmesis `v0.5.0`; the four job
 * routes, the ten job artifact routes, the four preview grant routes and the two
 * marker routes at `v0.6.0`; `createMaster`, `purgeArtifact` and `purgeJobArtifact` at
 * `v0.8.0`; `setTranscodePause` and `getTranscodePause` at `v0.9.0`, whose third
 * route, `clearTranscodePause`, answers an empty `204` and so has no row here). The
 * `routes` leg holds this list to that: an operation in that set that is missing from
 * here fails there, so a route added upstream cannot go unwrapped quietly.
 *
 * The four names that are not their component's (NodeStats, StreamStats,
 * StreamEventList, StreamEvent) are the D5 deviation: the class reads correctly in a
 * Laravel codebase and the component is pinned in its `@see`.
 *
 * @return array<string, array{0: string, 1: class-string}>
 */
function wrappedOperations(): array
{
    return [
        'getHealth' => ['getHealth', HealthFull::class],
        'getDoctor' => ['getDoctor', DoctorResponse::class],
        'getNodeStats' => ['getNodeStats', NodeStats::class],
        'listStreams' => ['listStreams', StreamList::class],
        'getStream' => ['getStream', StreamRecord::class],
        'getStreamStats' => ['getStreamStats', StreamStats::class],
        'getStreamEvents' => ['getStreamEvents', StreamEventList::class],
        'getStreamTranscript' => ['getStreamTranscript', TranscriptResponse::class],
        'listRelays' => ['listRelays', RelayListResponse::class],
        'getRelay' => ['getRelay', RelayRecord::class],
        'createStream' => ['createStream', StreamRecord::class],
        'updateStream' => ['updateStream', PatchResponse::class],
        'deleteStream' => ['deleteStream', SealResponse::class],
        'kickStream' => ['kickStream', SessionActionResponse::class],
        'takeoverStream' => ['takeoverStream', SessionActionResponse::class],
        'pushKeyRevocation' => ['pushKeyRevocation', RevocationResponse::class],
        'createRelay' => ['createRelay', RelayRecord::class],
        'updateRelay' => ['updateRelay', RelayRecord::class],
        'deleteRelay' => ['deleteRelay', RelayStopResponse::class],
        'stopRelays' => ['stopRelays', RelayStopResponse::class],
        'listArtifacts' => ['listArtifacts', ArtifactPage::class],
        'getArtifact' => ['getArtifact', ArtifactStatus::class],
        'getArtifactInventory' => ['getArtifactInventory', InventoryPage::class],
        'getTransferPlan' => ['getTransferPlan', PlanStatus::class],
        'replaceTransferPlan' => ['replaceTransferPlan', PlanStatus::class],
        'listTransfers' => ['listTransfers', TransferPage::class],
        'listTransferParts' => ['listTransferParts', PartPage::class],
        'supplyTransferAuthorization' => ['supplyTransferAuthorization', ObjectStatus::class],
        'releaseArtifact' => ['releaseArtifact', DecisionStatus::class],
        'overrideArtifact' => ['overrideArtifact', DecisionStatus::class],
        // tmesis v0.6.0 (LTWC): preview grants and markers.
        'createPreviewGrant' => ['createPreviewGrant', PreviewGrant::class],
        'listPreviewGrants' => ['listPreviewGrants', PreviewGrantListResponse::class],
        'updatePreviewGrant' => ['updatePreviewGrant', PreviewGrant::class],
        'revokePreviewGrant' => ['revokePreviewGrant', PreviewGrant::class],
        'placeMarker' => ['placeMarker', MarkerResponse::class],
        'listMarkers' => ['listMarkers', MarkerListResponse::class],
        // tmesis v0.6.0 (LTWB): transcode jobs and their generations.
        'createTranscodeJob' => ['createTranscodeJob', JobAccepted::class],
        'listTranscodeJobs' => ['listTranscodeJobs', JobListPage::class],
        'getTranscodeJob' => ['getTranscodeJob', JobStatus::class],
        'cancelTranscodeJob' => ['cancelTranscodeJob', JobCancelled::class],
        'listJobArtifacts' => ['listJobArtifacts', ArtifactPage::class],
        'getJobArtifact' => ['getJobArtifact', ArtifactStatus::class],
        'getJobArtifactInventory' => ['getJobArtifactInventory', InventoryPage::class],
        'getJobTransferPlan' => ['getJobTransferPlan', PlanStatus::class],
        'replaceJobTransferPlan' => ['replaceJobTransferPlan', PlanStatus::class],
        'listJobTransfers' => ['listJobTransfers', TransferPage::class],
        'listJobTransferParts' => ['listJobTransferParts', PartPage::class],
        'supplyJobTransferAuthorization' => ['supplyJobTransferAuthorization', ObjectStatus::class],
        'releaseJobArtifact' => ['releaseJobArtifact', DecisionStatus::class],
        'overrideJobArtifact' => ['overrideJobArtifact', DecisionStatus::class],
        // tmesis v0.8.0: the told master and the operator purge.
        'createMaster' => ['createMaster', ArtifactStatus::class],
        'purgeArtifact' => ['purgeArtifact', DecisionStatus::class],
        'purgeJobArtifact' => ['purgeJobArtifact', DecisionStatus::class],
        // tmesis v0.9.0: the told jobs pause (clearTranscodePause is a bodiless 204).
        'setTranscodePause' => ['setTranscodePause', JobPauseResponse::class],
        'getTranscodePause' => ['getTranscodePause', JobPauseResponse::class],
    ];
}

/**
 * Public methods with no required parameters.
 *
 * @return list<string>
 */
function accessorsOf(object $instance): array
{
    $out = [];

    foreach ((new ReflectionClass($instance))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        if (in_array($method->getName(), ['__construct', '__toString'], true)) {
            continue;
        }

        $out[] = $method->getName();
    }

    return $out;
}

/**
 * The node's error envelope, for Http::fake() answers. Shared here rather than in a
 * test file so every file, run alone or in parallel, can use it.
 *
 * @return array{error: array{code: string, message: string, details: null, request_id: string, milestone: string|null}}
 */
function errorBody(string $code, string $message, ?string $milestone = null): array
{
    return ['error' => [
        'code' => $code,
        'message' => $message,
        'details' => null,
        'request_id' => 'req_01J8ZC',
        'milestone' => $milestone,
    ]];
}
