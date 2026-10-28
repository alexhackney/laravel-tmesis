<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Fleet\FleetStreamState;
use AlexHackney\Tmesis\Events\ArtifactDeleted;
use AlexHackney\Tmesis\Events\RecordingCompleted;
use AlexHackney\Tmesis\Events\RecordingRegistered;
use AlexHackney\Tmesis\Events\RecordingStarted;
use AlexHackney\Tmesis\Events\StreamInputLost;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Webhooks\EventMap;

/*
 * The tmesis v0.8.0 event fields: the told master on the three recording.* events, the
 * purge on artifact.deleted, and the widened enums.
 */

/**
 * @return array<string, mixed>
 */
function v080Example(string $event): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(ContractDir::example($event)), true, 512, JSON_THROW_ON_ERROR);
}

it('reads source_generations as [] on an ingest recording', function (string $event): void {
    /** @var RecordingStarted|RecordingCompleted|RecordingRegistered $instance */
    $instance = EventMap::make(v080Example($event));

    expect($instance->artifact())->toBe('ingest')
        ->and($instance->sourceGenerations())->toBe([]);
})->with(['recording.started', 'recording.completed', 'recording.registered']);

it('reads a told master\'s events: the joined sources in order, master_finished, mp4', function (): void {
    $sources = ['01J8ZCK5R7V2QNX8RW4TKB6PD2', '01J8ZCM1T8V3QNX7RW2KPB4DF9'];

    $started = v080Example('recording.started');
    $started = array_replace($started, ['artifact' => 'master', 'container' => 'mpegts', 'source_generations' => $sources]);
    SchemaValidator::forSchema('recording.started')->assertValid(json_decode((string) json_encode($started), false), context: 'A master recording.started');

    $completed = v080Example('recording.completed');
    $completed = array_replace($completed, ['artifact' => 'master', 'reason' => 'master_finished', 'container' => 'mp4', 'source_generations' => $sources]);
    SchemaValidator::forSchema('recording.completed')->assertValid(json_decode((string) json_encode($completed), false), context: 'A master recording.completed');

    $registered = v080Example('recording.registered');
    $registered = array_replace($registered, ['artifact' => 'master', 'container' => 'mp4', 'source_generations' => $sources]);
    SchemaValidator::forSchema('recording.registered')->assertValid(json_decode((string) json_encode($registered), false), context: 'A master recording.registered');

    /** @var RecordingStarted $a */
    $a = EventMap::make($started);
    /** @var RecordingCompleted $b */
    $b = EventMap::make($completed);
    /** @var RecordingRegistered $c */
    $c = EventMap::make($registered);

    expect($a->artifact())->toBe(RecordingStarted::ARTIFACT_MASTER)
        ->and($a->sourceGenerations())->toBe($sources)
        ->and($b->reason())->toBe(RecordingCompleted::REASON_MASTER_FINISHED)
        ->and($b->container())->toBe(RecordingCompleted::CONTAINER_MP4)
        ->and($b->sourceGenerations())->toBe($sources)
        ->and($c->container())->toBe(RecordingRegistered::CONTAINER_MP4)
        ->and($c->sourceGenerations())->toBe($sources);
});

it('reads artifact.deleted\'s purge: false at the deadline, true with its reason after a purge', function (): void {
    $payload = v080Example('artifact.deleted');

    /** @var ArtifactDeleted $deadline */
    $deadline = EventMap::make($payload);

    expect($deadline->purge())->toBeFalse()
        ->and($deadline->purgeReason())->toBeNull();

    $payload = array_replace($payload, ['purge' => true, 'purge_reason' => 'Copied to tsp-media; free the disk now']);
    SchemaValidator::forSchema('artifact.deleted')->assertValid(json_decode((string) json_encode($payload), false), context: 'A purged artifact.deleted');

    /** @var ArtifactDeleted $purged */
    $purged = EventMap::make($payload);

    expect($purged->purge())->toBeTrue()
        ->and($purged->purgeReason())->toBe('Copied to tsp-media; free the disk now');
});

it('takes node_draining on stream.input_lost and rtmps on stream.published', function (): void {
    $lost = array_replace(v080Example('stream.input_lost'), ['reason' => StreamInputLost::REASON_NODE_DRAINING]);
    SchemaValidator::forSchema('stream.input_lost')->assertValid(json_decode((string) json_encode($lost), false), context: 'A draining stream.input_lost');

    $published = v080Example('stream.published');
    $published['protocol'] = StreamPublished::PROTOCOL_RTMPS;
    SchemaValidator::forSchema('stream.published')->assertValid(json_decode((string) json_encode($published), false), context: 'An RTMPS stream.published');

    /** @var StreamInputLost $a */
    $a = EventMap::make($lost);
    /** @var StreamPublished $b */
    $b = EventMap::make($published);

    expect($a->reason())->toBe('node_draining')
        ->and($b->protocol())->toBe('rtmps');
});

it('decodes an rtmps publisher on the fleet heartbeat\'s stream report', function (): void {
    $state = FleetStreamState::fromWire((object) [
        'stream' => 'show-2041-main',
        'state' => 'live',
        'session' => '01J8ZC8XKWQ4M7T0K3PZ2B9RVN',
        'epoch' => 3,
        'protocol' => 'rtmps',
        'dual_accept' => false,
    ], '$.streams[0]');

    expect($state->protocol)->toBe('rtmps');
});
