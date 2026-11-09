<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\MasterInfo;
use AlexHackney\Tmesis\Data\Fleet\FleetStreamState;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Data\Jobs\EditCut;
use AlexHackney\Tmesis\Data\Jobs\EditOperation;
use AlexHackney\Tmesis\Data\Jobs\JobEditInput;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Jobs\Spec;
use AlexHackney\Tmesis\Data\Node\Stats\IdentitySnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\IntegrationSnapshot;
use AlexHackney\Tmesis\Data\Node\Stats\VideoEngineSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\DeliverySnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\RecordingSnapshot;
use AlexHackney\Tmesis\Data\Streams\Stats\SessionSnapshot;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Data\Webhooks\NodeCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeJobsCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeJobsPause;
use AlexHackney\Tmesis\Data\Webhooks\NodeLiveCapacity;
use AlexHackney\Tmesis\Data\Webhooks\NodeVideoEngine;
use AlexHackney\Tmesis\Events\CaptionSessionEnded;
use AlexHackney\Tmesis\Events\JobsPaused;
use AlexHackney\Tmesis\Events\JobsResumed;
use AlexHackney\Tmesis\Events\NodeCapacityChanged;
use AlexHackney\Tmesis\Events\NodeOnline;
use AlexHackney\Tmesis\Events\RecordingCompleted;
use AlexHackney\Tmesis\Events\StreamInputLost;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Events\StreamPublishRejected;
use AlexHackney\Tmesis\Events\StreamSealed;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;

pest()->group('schemas-events');

/*
 * Every closed set the package spells as constants, held EQUAL to the enum it mirrors,
 * both directions. tmesis v0.8.0 widened five of them (rtmps, node_draining,
 * crash_recovered, master_finished, master_pending); a widening the package has not
 * taken is a named failure here rather than a value a consumer's switch never names.
 */

/**
 * The values of every constant on $class whose name starts with $prefix.
 *
 * @param  class-string  $class
 * @return list<string>
 */
function constantsWithPrefix(string $class, string $prefix): array
{
    $out = [];

    foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
        if (str_starts_with($name, $prefix) && is_string($value)) {
            $out[] = $value;
        }
    }

    sort($out);

    return $out;
}

/**
 * The enum of a top-level property of an event schema's root `$def`.
 *
 * @return list<string>
 */
function eventEnum(string $schema, string $property): array
{
    $document = ContractDir::readJson(ContractDir::schema($schema));
    $root = basename((string) $document['$ref']);
    $enum = $document['$defs'][$root]['properties'][$property]['enum'] ?? [];
    sort($enum);

    return $enum;
}

it('spells every protocol the contract carries, rtmps included', function (): void {
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];
    $record = $components['StreamRecord']['properties']['protocol']['oneOf'][0]['enum'];
    sort($record);

    $heartbeat = ContractDir::readJson(ContractDir::schema('fleet.heartbeat.request'))['$defs']['StreamState']['properties']['protocol']['enum'];

    expect(constantsWithPrefix(StreamPublished::class, 'PROTOCOL_'))->toBe(eventEnum('stream.published', 'protocol'))
        ->and(constantsWithPrefix(StreamPublishRejected::class, 'PROTOCOL_'))->toBe(eventEnum('stream.publish_rejected', 'protocol'))
        ->and(constantsWithPrefix(HookRequest::class, 'PROTOCOL_'))->toBe(eventEnum('publish.verify', 'protocol'))
        ->and(constantsWithPrefix(StreamRecord::class, 'PROTOCOL_'))->toBe($record)
        ->and(FleetStreamState::PROTOCOLS)->toEqualCanonicalizing($heartbeat)
        ->and($record)->toContain('rtmps');
});

it('spells every input_lost reason, sealed_by and recording.completed reason', function (): void {
    expect(constantsWithPrefix(StreamInputLost::class, 'REASON_'))->toBe(eventEnum('stream.input_lost', 'reason'))
        ->and(constantsWithPrefix(StreamSealed::class, 'SEALED_BY_'))->toBe(eventEnum('stream.sealed', 'sealed_by'))
        ->and(constantsWithPrefix(RecordingCompleted::class, 'REASON_'))->toBe(eventEnum('recording.completed', 'reason'));
});

it('spells every master state the document allows', function (): void {
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    expect(MasterInfo::STATES)->toEqualCanonicalizing($components['MasterInfo']['properties']['state']['enum']);
});

it('holds the eleven hold reasons in the order the document states them', function (): void {
    // hold_reasons is a string array with its closed set in prose: "in this order:
    // not_finalized, ..., retirement_failed." master_pending joined at v0.8.0.
    $description = (string) ContractDir::readJson(ContractDir::openapi())['components']['schemas']['ArtifactStatus']['properties']['hold_reasons']['description'];

    preg_match('/in this order: ([a-z_, ]+)\./', $description, $matches);

    expect(ArtifactStatus::HOLD_REASONS)->toBe(explode(', ', $matches[1] ?? ''))
        ->and(ArtifactStatus::HOLD_REASONS)->toContain('master_pending');
});

/**
 * The enum of a property of a NAMED `$def` of an event schema.
 *
 * @return list<string>
 */
function eventDefEnum(string $schema, string $def, string $property): array
{
    $enum = ContractDir::readJson(ContractDir::schema($schema))['$defs'][$def]['properties'][$property]['enum'] ?? [];
    sort($enum);

    return $enum;
}

it('spells every publish_rejected reason, over_capacity and encoder_unavailable included', function (): void {
    // tmesis v0.9.0 widened it from 12 to 14 (handover 3.1, 3.4).
    expect(constantsWithPrefix(StreamPublishRejected::class, 'REASON_'))->toBe(eventEnum('stream.publish_rejected', 'reason'))
        ->and(eventEnum('stream.publish_rejected', 'reason'))->toContain('over_capacity', 'encoder_unavailable')->toHaveCount(14);
});

it('spells every v0.9.0 node event closed set', function (): void {
    // handover 3.2 to 3.5: admission, both capacity sources, the capacity_changed
    // reason, the jobs pause source on all three carriers, the resumed cause.
    expect(constantsWithPrefix(NodeCapacity::class, 'ADMISSION_'))->toBe(eventDefEnum('node.online', 'NodeCapacity', 'admission'))
        ->and(constantsWithPrefix(NodeLiveCapacity::class, 'ADMISSION_'))->toBe(eventDefEnum('node.capacity_changed', 'NodeLiveCapacity', 'admission'))
        ->and(constantsWithPrefix(NodeLiveCapacity::class, 'SOURCE_'))->toBe(eventDefEnum('node.capacity_changed', 'NodeLiveCapacity', 'source'))
        ->and(constantsWithPrefix(NodeJobsCapacity::class, 'SOURCE_'))->toBe(eventDefEnum('node.capacity_changed', 'NodeJobsCapacity', 'source'))
        ->and(constantsWithPrefix(NodeCapacityChanged::class, 'REASON_'))->toBe(eventEnum('node.capacity_changed', 'reason'))
        ->and(constantsWithPrefix(NodeJobsPause::class, 'SOURCE_'))->toBe(eventDefEnum('node.online', 'NodeJobsPause', 'source'))
        ->and(constantsWithPrefix(JobsPaused::class, 'SOURCE_'))->toBe(eventEnum('jobs.paused', 'source'))
        ->and(constantsWithPrefix(JobsResumed::class, 'SOURCE_'))->toBe(eventEnum('jobs.resumed', 'source'))
        ->and(constantsWithPrefix(JobsResumed::class, 'CAUSE_'))->toBe(eventEnum('jobs.resumed', 'cause'));
});

it('holds caption resumable reasons equal to resumed_after', function (): void {
    $schema = ContractDir::readJson(ContractDir::schema('caption.session_started'));
    $root = basename((string) $schema['$ref']);
    $enum = $schema['$defs'][$root]['properties']['resumed_after']['oneOf'][0]['enum'];
    sort($enum);
    $reasons = CaptionSessionEnded::RESUMABLE_REASONS;
    sort($reasons);

    expect($reasons)->toBe($enum);
});

it('spells every NodeVideoEngine closed set on both carriers', function (string $schema): void {
    foreach (['ACCELERATOR_' => 'accelerator', 'PLACEMENT_' => 'placement', 'REQUESTED_' => 'requested'] as $prefix => $property) {
        expect(constantsWithPrefix(NodeVideoEngine::class, $prefix))->toBe(eventDefEnum($schema, 'NodeVideoEngine', $property));
    }
})->with(['node.online', 'node.capacity_changed']);

it('spells every caption end reason, including provider switch', function (): void {
    expect(constantsWithPrefix(CaptionSessionEnded::class, 'REASON_'))->toBe(eventEnum('caption.session_ended', 'reason'));
});

it('spells the node.online config_source rule, defaults included (v0.10.0)', function (): void {
    $sources = NodeOnline::CONFIG_SOURCES;
    sort($sources);

    expect(constantsWithPrefix(NodeOnline::class, 'CONFIG_SOURCE_'))->toBe(eventEnum('node.online', 'config_source'))
        ->and($sources)->toBe(eventEnum('node.online', 'config_source'));
});

it('holds the job stages and the job media edit kind equal to the contract (v0.10.0)', function (): void {
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    // `edit` sits between probe and audio, the order the node runs the stages in.
    expect(JobStatus::STAGES)->toBe(['fetch', 'probe', 'edit', 'audio', 'encode', 'package', 'thumbnails', 'transcribe', 'upload', 'verify'])
        ->and(JobStatus::STAGES)->toEqualCanonicalizing($components['JobError']['properties']['stage']['enum'])
        ->and(JobStatus::STAGES)->toEqualCanonicalizing($components['JobProgressStatus']['properties']['stage']['enum'] ?? $components['JobProgressStatus']['properties']['stage']['oneOf'][0]['enum'])
        ->and(ArtifactStatus::JOB_KINDS)->toContain(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and(ArtifactStatus::PREFIX_KINDS)->not->toContain(ArtifactStatus::KIND_JOB_MEDIA_EDIT)
        ->and(Spec::OUTPUTS)->toContain(Spec::OUTPUT_MEDIA_EDIT)
        ->and(EditOperation::OPS)->toEqualCanonicalizing($components['EditOperation']['properties']['op']['enum'])
        ->and(EditOperation::CODECS)->toEqualCanonicalizing($components['EditOperation']['properties']['codec']['enum'])
        ->and(EditOperation::FITS)->toEqualCanonicalizing($components['EditOperation']['properties']['fit']['enum'])
        ->and(EditCut::MODES)->toEqualCanonicalizing($components['EditCut']['properties']['mode']['enum'])
        ->and(Spec::CONTAINERS)->toEqualCanonicalizing($components['MediaEditRequest']['properties']['container']['enum'])
        ->and(Spec::CONTAINERS)->toEqualCanonicalizing($components['Spec']['properties']['container']['enum'])
        ->and(JobEditInput::KIND_FILE)->toBeIn($components['JobEditInput']['properties']['kind']['enum'])
        ->and(JobEditInput::KIND_HLS)->toBeIn($components['JobEditInput']['properties']['kind']['enum']);
});

it('spells every closed set the stats and node blocks gained as enums (v0.10.0)', function (): void {
    $components = ContractDir::readJson(ContractDir::openapi())['components']['schemas'];

    $enum = static function (string $component, string $property) use ($components): array {
        $values = $components[$component]['properties'][$property]['enum'];
        sort($values);

        return $values;
    };

    $sorted = static function (array $values): array {
        sort($values);

        return $values;
    };

    expect($sorted(SessionSnapshot::PROTOCOLS))->toBe($enum('SessionSnapshot', 'protocol'))
        ->and($sorted(DeliverySnapshot::DRAIN_OUTCOMES))->toBe($enum('DeliverySnapshot', 'packager_drain_outcome'))
        ->and($sorted(DeliverySnapshot::MODES))->toBe($enum('DeliverySnapshot', 'mode'))
        ->and($sorted(RecordingSnapshot::ARTIFACTS))->toBe($enum('RecordingSnapshot', 'artifact'))
        ->and($sorted(RecordingSnapshot::CONTAINERS))->toBe($enum('RecordingSnapshot', 'container'))
        ->and($sorted(RecordingSnapshot::STATES))->toBe($enum('RecordingSnapshot', 'state'))
        ->and($sorted(IdentitySnapshot::ROLES))->toBe($enum('IdentitySnapshot', 'role'))
        ->and($sorted(IntegrationSnapshot::MODES))->toBe($enum('IntegrationSnapshot', 'mode'))
        ->and($sorted(VideoEngineSnapshot::ACCELERATORS))->toBe($enum('VideoEngineSnapshot', 'accelerator'))
        ->and($sorted(VideoEngineSnapshot::PLACEMENTS))->toBe($enum('VideoEngineSnapshot', 'placement'))
        ->and($sorted(VideoEngineSnapshot::REQUESTED))->toBe($enum('VideoEngineSnapshot', 'requested'));
});
