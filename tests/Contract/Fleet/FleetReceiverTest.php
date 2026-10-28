<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\Fleet\FleetAdmissionChanged;
use AlexHackney\Tmesis\Events\Fleet\FleetCommandAcked;
use AlexHackney\Tmesis\Events\Fleet\FleetRevisionRejected;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Fleet\DesiredDocument;
use AlexHackney\Tmesis\Fleet\DesiredRecording;
use AlexHackney\Tmesis\Fleet\DesiredRelay;
use AlexHackney\Tmesis\Fleet\DesiredStream;
use AlexHackney\Tmesis\Fleet\FleetStore;
use AlexHackney\Tmesis\Fleet\RevocationsDigest;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Tests\Doubles\ArrayDesiredStateProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

pest()->group('fleet-receiver');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/*
 * The fleet receiver driven by the vector's own node-sent bodies (tmesis v0.7.0,
 * test/vectors/fleet.json), through the package's routes, with every answer held to
 * its schema and to the vector's answer.
 *
 * The bodies go on the wire as the vector wrote them. The one thing rewritten is the
 * REVISION: the package owns the number (a counter per node from 1) where
 * the hand-written exchange starts at 42, so a vector revision is mapped to the one
 * this receiver actually sent. Ids the receiver mints (node id, tokens, command ids)
 * are compared by shape, and by equality across the D12 pair.
 *
 * ORDER. The vector refuses revision 44 and THEN corrects a digest drift by resending
 * the applied 43. This receiver keeps revisions monotonic and never stores a document
 * (only its keyed hash, because documents carry relay URLs), so while a refused
 * revision is outstanding its answer is that revision again, the node's re-send rule
 * (tmesis fleetdesired.go, desiredApplier.reject). The leg therefore runs the drift at
 * 43 first, then the refusal, then the fix as a higher revision, which is what D6 asks
 * of a control plane. Revision 44's own content (an s3:// destination) the package
 * refuses to build at all; schemas-fleet holds that. A revocation added while a refusal
 * stands still converges, because the node applies a refused revision's valid list
 * (tmesis D19); the last case holds the package's side of that.
 */

/**
 * One vector document's body by name, decoded to objects.
 */
function receiverVector(string $name): stdClass
{
    $vector = json_decode((string) file_get_contents(ContractDir::vectors('fleet')), false, 512, JSON_THROW_ON_ERROR);

    foreach ($vector->documents as $document) {
        if ($document->name === $name) {
            return $document->body;
        }
    }

    throw new RuntimeException("The fleet vector has no document named [{$name}].");
}

/**
 * The vector's revision 42 content (the enroll answers' document) or 43's (one more
 * key epoch and one revocation), built with the package's own builders.
 */
function receiverDocument(int $vectorRevision): DesiredDocument
{
    $document = DesiredDocument::make()->stream(DesiredStream::make(
        'show-2041-main',
        DesiredRecording::make(enabled: true, retentionHours: 72, destinationRef: 'tsp-archive'),
        minKeyEpoch: $vectorRevision === 42 ? 6 : 7,
        labels: ['show_id' => '2041', 'site' => 'csn'],
        relays: [DesiredRelay::make('fleet-origin-atl', 'srt://origin-atl-01.internal:8890')],
    ));

    return $vectorRevision === 42 ? $document : $document->revoke('show-2039-rehearsal');
}

/**
 * POST a body to one of the two fleet routes with a bearer, as the node does.
 */
function receiverPost(object $test, string $route, string $token, stdClass $body): TestResponse
{
    return $test->call('POST', route($route), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ], (string) json_encode($body, JSON_UNESCAPED_SLASHES));
}

/**
 * A vector heartbeat with its revisions mapped onto the ones this receiver sent.
 *
 * @param  array<int, int>  $revisions  vector revision to receiver revision
 */
function receiverBeat(string $name, array $revisions): stdClass
{
    $body = receiverVector($name);
    $body->desired_revision_applied = $revisions[$body->desired_revision_applied];

    if ($body->desired_revision_rejected !== null) {
        $body->desired_revision_rejected->revision = $revisions[$body->desired_revision_rejected->revision];
    }

    return $body;
}

/**
 * An answer decoded to objects and validated against the schema it answers under.
 */
function receiverAnswer(TestResponse $response, string $schema, string $context): stdClass
{
    $response->assertOk();
    $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);

    SchemaValidator::forSchema($schema)->assertValid($body, context: $context);

    return $body;
}

/**
 * A desired state as the vector writes it at the receiver's revision, with the
 * `jobs_pause: null` the package always sends (tmesis v0.9.0, handover 3.5): no desired
 * state in the vector carries the key, and absent and null both mean "no fleet pause"
 * at the node, so the receiver's explicit null is the same document.
 */
function receiverExpected(stdClass $desired, int $revision): string
{
    $copy = json_decode((string) json_encode($desired), false);
    $copy->revision = $revision;

    if (! property_exists($copy, 'jobs_pause')) {
        $copy->jobs_pause = null;
    }

    return (string) json_encode($copy, JSON_UNESCAPED_SLASHES);
}

beforeEach(function (): void {
    $this->withFleet();
    ArrayDesiredStateProvider::$documents['edge-venue-07'] = receiverDocument(42);
});

it('answers the enrollment and its D12 repeat as the vector does: one node id, a fresh token, the first one dead', function (): void {
    $join = Tmesis::fleet()->joinToken('edge-venue-07', 'edge');

    $first = receiverAnswer(receiverPost($this, 'tmesis.fleet.enroll', $join->reveal(), receiverVector('enroll.request')), 'fleet.enroll.response', 'The enroll answer');
    $repeat = receiverAnswer(receiverPost($this, 'tmesis.fleet.enroll', $join->reveal(), receiverVector('enroll.request.repeat')), 'fleet.enroll.response', 'The D12 repeat answer');

    $vector = receiverVector('enroll.response');
    $vectorRepeat = receiverVector('enroll.response.repeat');

    // The vector's pair: same node id and name, a different token, the same document.
    expect($vectorRepeat->node_id)->toBe($vector->node_id)
        ->and($vectorRepeat->fleet_token)->not->toBe($vector->fleet_token)
        // And this receiver's.
        ->and($repeat->node_id)->toBe($first->node_id)
        ->and($repeat->node_name)->toBe($first->node_name)
        ->and($first->node_name)->toBe($vector->node_name)
        ->and($repeat->fleet_token)->not->toBe($first->fleet_token)
        ->and($first->heartbeat_url)->toBe(route('tmesis.fleet.heartbeat'))
        ->and(json_encode($first->desired_state, JSON_UNESCAPED_SLASHES))->toBe(receiverExpected($vector->desired_state, $first->desired_state->revision))
        ->and(json_encode($repeat->desired_state, JSON_UNESCAPED_SLASHES))->toBe(receiverExpected($vectorRepeat->desired_state, $first->desired_state->revision));

    $store = app(FleetStore::class);

    // The token the lost answer carried is revoked; heartbeats bear the repeat's.
    expect($store->nodeRowByToken(hash('sha256', $first->fleet_token)))->toBeNull()
        ->and($store->nodeRowByToken(hash('sha256', $repeat->fleet_token))?->id)->toBe($first->node_id);

    receiverPost($this, 'tmesis.fleet.heartbeat', $first->fleet_token, receiverBeat('heartbeat.request.applied-42', [42 => $first->desired_state->revision]))
        ->assertStatus(401);
});

it('drives the vector\'s heartbeats: 42 to 43, a digest drift resent at 43, a refusal, the fix, and the commands acked', function (): void {
    Event::fake([FleetRevisionRejected::class, FleetCommandAcked::class, FleetAdmissionChanged::class]);

    $join = Tmesis::fleet()->joinToken('edge-venue-07', 'edge');
    $enrolled = receiverAnswer(receiverPost($this, 'tmesis.fleet.enroll', $join->reveal(), receiverVector('enroll.request')), 'fleet.enroll.response', 'The enroll answer');
    $token = $enrolled->fleet_token;
    $nodeId = $enrolled->node_id;

    $r42 = $enrolled->desired_state->revision;
    $revisions = [42 => $r42];

    // 42 applied, the content moves: the answer acks both events and carries 43.
    ArrayDesiredStateProvider::$documents['edge-venue-07'] = receiverDocument(43);
    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, receiverBeat('heartbeat.request.applied-42', $revisions)), 'fleet.heartbeat.response', 'The answer to applied-42');
    $vector = receiverVector('heartbeat.response.revision-43');

    $revisions[43] = $answer->desired_state->revision;

    expect($revisions[43])->toBe($r42 + 1)
        ->and($answer->ack_event_ids)->toBe($vector->ack_event_ids)
        ->and(json_encode($answer->desired_state, JSON_UNESCAPED_SLASHES))->toBe(receiverExpected($vector->desired_state, $revisions[43]))
        ->and($answer->commands)->toBe([]);

    // 43 applied, digest matching: nothing to say.
    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, receiverBeat('heartbeat.request.applied-43', $revisions)), 'fleet.heartbeat.response', 'The answer to applied-43');

    expect($answer->desired_state)->toBeNull()
        ->and($answer->ack_event_ids)->toBe([]);

    // The drift (R16): an admin API push emptied the list, the revision did not move.
    // The vector's drift body also still reports 44's refusal; that half runs below,
    // so it is cleared here.
    $drift = receiverBeat('heartbeat.request.digest-drift', $revisions + [44 => 0]);
    $drift->desired_revision_rejected = null;
    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $drift), 'fleet.heartbeat.response', 'The answer to digest-drift');

    expect(json_encode($answer->desired_state, JSON_UNESCAPED_SLASHES))
        ->toBe(receiverExpected(receiverVector('heartbeat.response.revision-43-resent')->desired_state, $revisions[43]));

    // Converged again.
    $converged = receiverBeat('heartbeat.request.converged', $revisions + [44 => 0]);
    $converged->desired_revision_rejected = null;
    expect(receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $converged), 'fleet.heartbeat.response', 'The answer to converged')->desired_state)->toBeNull();

    // A revision the node refuses: content the package can build (a destination
    // directory) that the node judges at apply time. The package sends it, and keeps
    // sending it while it is the one intended, as the node's re-send rule expects.
    ArrayDesiredStateProvider::$documents['edge-venue-07'] = DesiredDocument::make()
        ->stream(DesiredStream::make('show-2041-main', DesiredRecording::make(true, 72, '/srv/not-on-this-box'), 7, ['show_id' => '2041', 'site' => 'csn']))
        ->revoke('show-2039-rehearsal');
    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $converged), 'fleet.heartbeat.response', 'The answer carrying the refused revision');
    $revisions[44] = $answer->desired_state->revision;

    expect($revisions[44])->toBe($revisions[43] + 1);

    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, receiverBeat('heartbeat.request.rejected-44', $revisions)), 'fleet.heartbeat.response', 'The answer to rejected-44');

    expect($answer->desired_state->revision)->toBe($revisions[44])
        ->and(Tmesis::fleet()->node($nodeId)->rejected?->revision)->toBe($revisions[44])
        ->and(Tmesis::fleet()->node($nodeId)->rejected?->reason)->toBe(receiverVector('heartbeat.request.rejected-44')->desired_revision_rejected->reason);

    Event::assertDispatched(FleetRevisionRejected::class, fn (FleetRevisionRejected $e): bool => $e->rejected->revision === $revisions[44]);

    // The host app fixes the document: a HIGHER revision (D6), never 43 again.
    ArrayDesiredStateProvider::$documents['edge-venue-07'] = receiverDocument(43)->openPosture(false);
    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, receiverBeat('heartbeat.request.rejected-44', $revisions)), 'fleet.heartbeat.response', 'The answer with the fix');
    $fixed = $answer->desired_state->revision;

    expect($fixed)->toBe($revisions[44] + 1)
        ->and(json_encode($answer->desired_state, JSON_UNESCAPED_SLASHES))->toBe(receiverExpected(receiverVector('heartbeat.response.revision-43')->desired_state, $fixed));

    // The node applies the fix and the rejection clears; then the commands. The
    // vector's batch is drain, kick, seal and a moot update; this package queues the
    // three v0.7.0 performs (D10) and never an update.
    $revisions[45] = $fixed;
    $applied = receiverBeat('heartbeat.request.converged', $revisions);
    $applied->desired_revision_applied = $fixed;
    $applied->desired_revision_rejected = null;

    $drain = Tmesis::fleet()->drain($nodeId);
    $kick = Tmesis::fleet()->kick($nodeId, 'show-2041-main');
    $seal = Tmesis::fleet()->seal($nodeId, 'show-2039-rehearsal', 30);

    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $applied), 'fleet.heartbeat.response', 'The answer with commands');
    $vectorCommands = array_slice(receiverVector('heartbeat.response.commands')->commands, 0, 3);

    expect($answer->desired_state)->toBeNull()
        ->and(array_map(fn (stdClass $c): string => $c->id, $answer->commands))->toBe([$drain, $kick, $seal]);

    foreach ($answer->commands as $i => $command) {
        expect([$command->kind, $command->stream, json_encode($command->args)])
            ->toBe([$vectorCommands[$i]->kind, $vectorCommands[$i]->stream, json_encode($vectorCommands[$i]->args)]);
    }

    $acked = receiverBeat('heartbeat.request.commands-acked', $revisions);
    $acked->desired_revision_applied = $fixed;
    $acked->desired_revision_rejected = null;
    $acked->acked_command_ids = [$drain, $kick, $seal];

    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $acked), 'fleet.heartbeat.response', 'The answer to commands-acked');

    expect($answer->commands)->toBe([])
        ->and($answer->desired_state)->toBeNull()
        ->and(Tmesis::fleet()->node($nodeId)->isDraining())->toBeTrue()
        ->and(Tmesis::fleet()->node($nodeId)->rejected)->toBeNull()
        ->and(Tmesis::fleet()->node($nodeId)->converged())->toBeTrue();

    Event::assertDispatchedTimes(FleetCommandAcked::class, 3);
    Event::assertDispatched(FleetAdmissionChanged::class);
});

it('converges a refused revision\'s new revocation while the refusal stands (tmesis D19)', function (): void {
    // D19 (tmesis API.md 15.3): a node that refuses a revision for its content still
    // puts that revision's revocation list in force, so the digest it reports matches
    // the list the package intends even while desired_revision_rejected stands. The
    // package keeps sending its newest revision and needs nothing else: this pins that
    // the answer, the node record and the observations read that state correctly.
    Event::fake([FleetRevisionRejected::class]);

    $join = Tmesis::fleet()->joinToken('edge-venue-07', 'edge');
    $enrolled = receiverAnswer(receiverPost($this, 'tmesis.fleet.enroll', $join->reveal(), receiverVector('enroll.request')), 'fleet.enroll.response', 'The enroll answer');
    $token = $enrolled->fleet_token;
    $nodeId = $enrolled->node_id;
    $applied = $enrolled->desired_state->revision;

    // Content the node refuses (a destination directory it judges at apply time) that
    // ALSO revokes a stream.
    $refusable = fn (string ...$revoked): DesiredDocument => DesiredDocument::make()
        ->stream(DesiredStream::make('show-2041-main', DesiredRecording::make(true, 72, '/srv/not-on-this-box'), 7, ['show_id' => '2041', 'site' => 'csn']))
        ->revoke(...$revoked);

    ArrayDesiredStateProvider::$documents['edge-venue-07'] = $refusable('show-2039-rehearsal');
    $beat = receiverBeat('heartbeat.request.applied-42', [42 => $applied]);
    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $beat), 'fleet.heartbeat.response', 'The answer carrying the refusable revision');
    $refused = $answer->desired_state->revision;

    expect($refused)->toBe($applied + 1)
        ->and($answer->desired_state->revocations)->toBe(['show-2039-rehearsal']);

    // The node refuses it, applied stays, and the list is in force: the vector's
    // rejected-44 body carries exactly that digest.
    $report = receiverBeat('heartbeat.request.rejected-44', [43 => $applied, 44 => $refused]);

    expect($report->revocations_digest)->toBe(RevocationsDigest::of(['show-2039-rehearsal']));

    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $report), 'fleet.heartbeat.response', 'The answer to the refusal');
    $node = Tmesis::fleet()->node($nodeId);

    // The package still sends its newest revision (the node's resend rule makes that a
    // no-op there), the digests agree, and the node is NOT converged: the rest of the
    // document is not applied.
    expect($answer->desired_state->revision)->toBe($refused)
        ->and($node->rejected?->revision)->toBe($refused)
        ->and($node->appliedRevision)->toBe($applied)
        ->and($node->revocationsDigest)->toBe($node->intendedRevocationsDigest)
        ->and($node->converged())->toBeFalse();

    // A key revoked while the refusal stands: a new, still refusable revision with the
    // longer list. The node refuses it too and puts the longer list in force.
    ArrayDesiredStateProvider::$documents['edge-venue-07'] = $refusable('show-2039-rehearsal', 'show-2040-leaked');
    $answer = receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $report), 'fleet.heartbeat.response', 'The answer with a revocation added under the refusal');
    $newer = $answer->desired_state->revision;

    expect($newer)->toBe($refused + 1)
        ->and($answer->desired_state->revocations)->toBe(['show-2039-rehearsal', 'show-2040-leaked']);

    $report->desired_revision_rejected->revision = $newer;
    $report->revocations_digest = RevocationsDigest::of(['show-2040-leaked', 'show-2039-rehearsal']);
    receiverAnswer(receiverPost($this, 'tmesis.fleet.heartbeat', $token, $report), 'fleet.heartbeat.response', 'The answer to the second refusal');
    $node = Tmesis::fleet()->node($nodeId);

    expect($node->rejected?->revision)->toBe($newer)
        ->and($node->appliedRevision)->toBe($applied)
        ->and($node->revocationsDigest)->toBe($node->intendedRevocationsDigest)
        ->and($node->converged())->toBeFalse();

    // One observation per refused revision, never one per beat.
    Event::assertDispatchedTimes(FleetRevisionRejected::class, 2);
});
