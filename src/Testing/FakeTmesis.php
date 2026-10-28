<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\PendingNode;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Data\Artifacts\ArtifactStatus;
use AlexHackney\Tmesis\Data\Artifacts\InventoryEntry;
use AlexHackney\Tmesis\Data\Artifacts\MasterInfo;
use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\ErrorResponse;
use AlexHackney\Tmesis\Data\Jobs\JobError;
use AlexHackney\Tmesis\Data\Jobs\JobPauseRequest;
use AlexHackney\Tmesis\Data\Jobs\JobPauseResponse;
use AlexHackney\Tmesis\Data\Jobs\JobStatus;
use AlexHackney\Tmesis\Data\Markers\MarkerListResponse;
use AlexHackney\Tmesis\Data\Markers\MarkerRecord;
use AlexHackney\Tmesis\Data\Markers\MarkerRequest;
use AlexHackney\Tmesis\Data\Markers\MarkerResponse;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Data\Preview\PreviewGrant;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantInput;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantListResponse;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch;
use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayListResponse;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Data\Relays\RelayRecord;
use AlexHackney\Tmesis\Data\Relays\RelayStopResponse;
use AlexHackney\Tmesis\Data\Streams\PatchResponse;
use AlexHackney\Tmesis\Data\Streams\RevocationResponse;
use AlexHackney\Tmesis\Data\Streams\SealResponse;
use AlexHackney\Tmesis\Data\Streams\SessionActionResponse;
use AlexHackney\Tmesis\Data\Streams\StreamList;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Data\Streams\TranscriptResponse;
use AlexHackney\Tmesis\Exceptions\ErrorMap;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\TmesisException;
use AlexHackney\Tmesis\Fleet\FleetControlPlane;
use AlexHackney\Tmesis\Resources\Streams;
use AlexHackney\Tmesis\Support\Keyterms;
use AlexHackney\Tmesis\Support\RelayFeed;
use AlexHackney\Tmesis\Support\RelayUrl;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * The test double a consuming app writes its tests against.
 *
 * `Tmesis::fake()` swaps the client binding for one of these. Nothing here builds a
 * transport, so no HTTP is involved at all and a consumer never has to stub the node.
 *
 * Relay data passes through RelayUrl::redact() on the way IN and the call log never
 * records a URL, so the fake cannot hold or replay a credential either.
 */
class FakeTmesis implements TmesisClientInterface
{
    /** @var array<string, mixed> */
    private array $health = [];

    /** @var array<string, mixed> */
    private array $doctor = [];

    /** @var array<string, mixed> */
    private array $nodeStats = [];

    /** @var array<string, StreamRecord> */
    private array $streams = [];

    /** @var array<string, array<string, mixed>> */
    private array $streamStats = [];

    /** @var array<string, array<string, mixed>> */
    private array $streamEvents = [];

    /** @var array<string, list<RelayRecord>> */
    private array $relays = [];

    /** @var array<string, array<string, mixed>> */
    private array $transcripts = [];

    /** @var array<string, string> */
    private array $transcriptPrimaries = [];

    /** @var array<string, array<string, array{document: string, lang: ?string}>> */
    private array $transcriptTexts = [];

    /**
     * The last minted relay number per stream. Never decremented, so a delete cannot
     * hand the next create an id that is already taken.
     *
     * @var array<string, int>
     */
    private array $relayIds = [];

    /** @var list<array{operation: string, node: string, args: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, Throwable> */
    private array $throws = [];

    /** @var list<string> */
    private array $revocations = [];

    private ?FakeArtifactLedger $artifacts = null;

    private ?FakeFleet $fleet = null;

    /** The audit id every canned PatchResponse carries. */
    private const FAKE_AUDIT_ID = '01J8ZFAKEAUDIT0000000000000';

    /**
     * When each writable field takes effect, from `internal/api/changesemantics.go`,
     * keyed by the DOTTED PATH the node reports back in `applied` and `pending`
     * (`docs/API.md` 9.6).
     *
     * The fake answers a PATCH with the same shape the node would, because "applied"
     * and "pending" are the distinction a consumer's code has to get right and a fake
     * that reported everything as applied would let it get it wrong in tests. A mixed
     * patch is the case that needs the dotted keys: `preview.enabled` is
     * `next_session` while `preview.max_monitors` on the same patch is `live`.
     *
     * @var array<string, string>
     */
    private const PATCH_SEMANTICS = [
        'ladder' => 'next_session',
        'segment_seconds' => 'next_session',
        'min_key_epoch' => 'live',
        'webhook_url' => 'live',
        'labels' => 'live',
        'relays' => 'live',
        'recording.ingest.enabled' => 'live',
        'recording.ingest.retention_hours' => 'live',
        'recording.ingest.destination' => 'live',
        'recording.ingest.destination_ref' => 'live',
        'fallback.end_seconds' => 'live',
        'preview.enabled' => 'next_session',
        'preview.max_monitors' => 'live',
        'captions.enabled' => 'next_segment',
        'captions.advertise' => 'next_segment',
        'captions.provider' => 'next_segment',
        'captions.languages' => 'next_segment',
        'captions.keyterms' => 'next_segment',
    ];

    /**
     * The effective captions block the fake's standalone box resolves an untold field
     * to (`docs/API.md` 9.5's examples): off, advertised when on, the node's one
     * provider, the node's one language, no keyterms (tmesis `v0.8.0`). A spec's null
     * `provider` and empty or null `languages` resolve through here too, because the
     * record is never null.
     *
     * @var array{enabled: bool, advertise: bool, provider: string, languages: list<string>, keyterms: list<string>}
     */
    private const DEFAULT_CAPTIONS = [
        'enabled' => false,
        'advertise' => true,
        'provider' => 'deepgram',
        'languages' => ['en'],
        'keyterms' => [],
    ];

    /** The node's `fallback.end_seconds` default the fake box resolves a null to. */
    private const DEFAULT_FALLBACK_END_SECONDS = 3600;

    /**
     * Spec fields that are VALUES rather than objects to descend into: a patch that
     * mentions one replaces it wholesale, so it is one dotted path, never many.
     *
     * @var list<string>
     */
    private const WHOLE_FIELDS = ['labels', 'relays'];

    /**
     * The fleet control plane for a test (plan F9): the package's REAL enrolment,
     * store and heartbeat handler, minus HTTP and tokens, with a scripted node per
     * enrolment. Needs the package migrations on the test database. Binds itself, so
     * `Tmesis::fleet()` answers it from here on.
     */
    public function fleet(): FakeFleet
    {
        if ($this->fleet === null) {
            /** @var FakeFleet $fleet */
            $fleet = app(FakeFleet::class);
            $this->fleet = $fleet;
            app()->instance(FleetControlPlane::class, $fleet);
        }

        return $this->fleet;
    }

    public function node(?string $name = null): PendingNode
    {
        $registry = app(NodeRegistry::class);

        $config = $name === null ? $registry->default() : $registry->get($name);

        return new FakeNode($registry, $config, $this);
    }

    public function connect(NodeConfig $node): PendingNode
    {
        return new FakeNode(app(NodeRegistry::class), $node, $this);
    }

    /**
     * @param  array<string, mixed>  $health
     */
    public function withHealth(array $health): self
    {
        $this->health = $health;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $doctor
     */
    public function withDoctor(array $doctor): self
    {
        $this->doctor = $doctor;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function withNodeStats(array $stats): self
    {
        $this->nodeStats = $stats;

        return $this;
    }

    /**
     * @param  list<StreamRecord|array<string, mixed>>  $streams
     */
    public function withStreams(array $streams): self
    {
        foreach ($streams as $stream) {
            $this->withStream($stream);
        }

        return $this;
    }

    /**
     * @param  StreamRecord|array<string, mixed>  $stream
     */
    public function withStream(StreamRecord|array $stream): self
    {
        $raw = $stream instanceof StreamRecord ? $stream->toArray() : $stream;

        // `captions` is REQUIRED on a record since tmesis v0.3.0; a record written
        // against an older shape gets the fake box's effective default rather than a
        // decode failure on the first read.
        $raw['captions'] = self::effectiveCaptions($raw['captions'] ?? []);

        $record = StreamRecord::fromArray($raw);

        $this->streams[$record->id()] = $record;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    public function withStreamStats(string $streamId, array $stats): self
    {
        $this->streamStats[$streamId] = $stats;

        return $this;
    }

    /**
     * Seed the JSON transcript (`format=json`) for one stream.
     *
     * The body's own `lang` is the language it answers for: a read naming another is
     * the node's `404`, and so is a read of a stream with nothing seeded. A read with
     * `since` returns only the cues whose `seq` is above it, the way the node tails.
     *
     * On every JSON read the body's `provider` is rewritten: the first returned cue's
     * provider, else the stream's primary. The primary is, in order: `$primary`; the
     * held stream's `captions.provider` when the fake holds that stream; the seeded
     * body's own `provider`.
     *
     * @param  TranscriptResponse|array<string, mixed>  $transcript
     */
    public function withStreamTranscript(string $streamId, TranscriptResponse|array $transcript, ?string $primary = null): self
    {
        $this->transcripts[$streamId] = $transcript instanceof TranscriptResponse ? $transcript->toArray() : $transcript;

        if ($primary !== null) {
            $this->transcriptPrimaries[$streamId] = $primary;
        } else {
            unset($this->transcriptPrimaries[$streamId]);
        }

        return $this;
    }

    /**
     * Seed a WebVTT (`vtt`) or SRT (`srt`) rendering for one stream, answered verbatim.
     *
     * `$lang` null answers for any language; set, a read naming another is the node's
     * `404`. The fake applies no `since` to a text document.
     */
    public function withStreamTranscriptText(string $streamId, string $format, string $document, ?string $lang = null): self
    {
        Streams::guardTranscriptFormat($format);

        $this->transcriptTexts[$streamId][$format] = ['document' => $document, 'lang' => $lang];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $events
     */
    public function withStreamEvents(string $streamId, array $events): self
    {
        $this->streamEvents[$streamId] = $events;

        return $this;
    }

    /**
     * @param  list<RelayRecord|array<string, mixed>>  $relays
     */
    public function withRelays(string $streamId, array $relays): self
    {
        $records = [];

        foreach ($relays as $relay) {
            // Redacted on the way in, so the fake cannot hold a credential even if a
            // test hands it one.
            $records[] = $relay instanceof RelayRecord ? $relay : RelayRecord::fromArray($relay);
        }

        $this->relays[$streamId] = $records;

        return $this;
    }

    /**
     * Seed a FINALIZED generation: the node's answer to a sealed replay or a finished
     * recording, with its baseline plan (revision 0, no targets) in place.
     *
     * The inventory defaults to a small set for the kind (segments, a playlist and a
     * `playlist.m3u8` master for `hls_sealed`, one `recording.ts` for a recording); an entry
     * given as an array needs only `path` and `publish_order`. `inventory_sha256` is a
     * hash of the entries unless given. `labels` only feed artifactIdentity().
     *
     * The fake has no clock: retention never elapses, so the generation stays held,
     * and what a consumer can move is its release state.
     *
     * @param  list<InventoryEntry|array<string, mixed>>|null  $inventory
     * @param  array<string, string>  $labels
     */
    public function artifactFinalized(
        string $stream,
        string $generation,
        string $kind = ArtifactStatus::KIND_HLS_SEALED,
        ?array $inventory = null,
        ?string $inventorySha256 = null,
        array $labels = [],
        ?string $session = null,
        int $sessionEpoch = 1,
        int $retentionHours = 72,
    ): self {
        $this->artifactLedger()->seedFinalized(
            $stream, $generation, $kind, $inventory, $inventorySha256, $labels, $session, $sessionEpoch, $retentionHours,
        );

        return $this;
    }

    /**
     * Seed an ABANDONED generation: an `hls_sealed` one that never finalized and whose
     * session has ended (`docs/API.md` 12a.5a). It has no inventory and no plan, and
     * takes exactly one decision, `OverrideRequest::discardAbandoned()`.
     *
     * @param  array<string, string>  $labels
     */
    public function artifactAbandoned(string $stream, string $generation, array $labels = [], ?string $session = null, int $sessionEpoch = 1): self
    {
        $this->artifactLedger()->seedAbandoned($stream, $generation, $labels, $session, $sessionEpoch);

        return $this;
    }

    /**
     * Complete every transfer object of one target on the generation's CURRENT plan
     * revision, as the node's executor would. A release needs every target complete.
     */
    public function completeTransfer(string $stream, string $generation, string $targetId): self
    {
        $this->artifactLedger()->settleTarget($stream, $generation, $targetId, ObjectStatus::STATE_COMPLETE);

        return $this;
    }

    /**
     * Fail every transfer object of one target on the current revision, with the safe
     * `error.code` a failed object carries. Only an override that waives the target
     * gets past it.
     */
    public function failTransfer(string $stream, string $generation, string $targetId, string $errorCode = 'attempts_exhausted'): self
    {
        $this->artifactLedger()->settleTarget($stream, $generation, $targetId, ObjectStatus::STATE_FAILED, $errorCode);

        return $this;
    }

    /**
     * Start a told master the consumer requested (`artifacts()->createMaster()`): its
     * `master.state` reads `running`. Its sources keep `master_pending`.
     */
    public function startMaster(string $stream, string $generation): self
    {
        $this->artifactLedger()->advanceMaster($stream, $generation, MasterInfo::STATE_RUNNING);

        return $this;
    }

    /**
     * Finish a told master as the node does at the end of its remux: the generation
     * finalizes (one `master.mp4`), its told target is filed as plan revision 1, and its
     * sources lose `master_pending`. It is NOT registered yet: `registered_at` and the
     * retention deadline stay null (held `retention_clock_unknown`) until
     * completeTransfer() lands the told target, as at the node. Nothing releases it.
     */
    public function finishMaster(string $stream, string $generation): self
    {
        $this->artifactLedger()->advanceMaster($stream, $generation, MasterInfo::STATE_COMPLETED);

        return $this;
    }

    /**
     * Fail a told master: terminal, `master.error` set, the sources untouched and free
     * of `master_pending`.
     */
    public function failMaster(string $stream, string $generation, string $error = 'ffmpeg exited 1'): self
    {
        $this->artifactLedger()->advanceMaster($stream, $generation, MasterInfo::STATE_FAILED, $error);

        return $this;
    }

    /**
     * Assert a master was requested on the stream and accepted. The callback receives
     * the generation's `master` block as the node holds it: `request_id`, `state`,
     * `sources` in join order, `capture_container`, the resolved secret-free `target`
     * (with its `key`), `reason` and `error`.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $master
     */
    public function assertMasterRequested(string $stream, ?callable $master = null): self
    {
        $matches = array_filter(
            $this->artifactLedger()->masters($stream),
            static fn (array $row): bool => $master === null || $master($row['request']),
        );

        PHPUnit::assertNotEmpty($matches, "No matching master was requested on stream [{$stream}].");

        return $this;
    }

    /**
     * Assert the generation was PURGED (tmesis `v0.8.0`): a purge is recorded on its
     * current decision, so its local files go at the next retirement pass.
     */
    public function assertPurged(string $stream, string $generation): self
    {
        $decision = $this->decisionOf($stream, $generation);

        PHPUnit::assertNotNull($decision['purge_requested_at'] ?? null, "Generation [{$generation}] of stream [{$stream}] was not purged.");

        return $this;
    }

    /**
     * The `ArtifactIdentity` block of a seeded generation (`stream`, `session`,
     * `session_epoch`, `generation`, `kind`, `inventory_sha256`, `labels`), to spread
     * into a FakeTmesis::webhook() payload so the event and the ledger agree.
     *
     * @return array<string, mixed>
     */
    public function artifactIdentity(string $stream, string $generation): array
    {
        return $this->artifactLedger()->identity($stream, $generation);
    }

    /**
     * @internal used by FakeArtifactsResource
     */
    public function artifactLedger(): FakeArtifactLedger
    {
        return $this->artifacts ??= new FakeArtifactLedger;
    }

    /**
     * Make one operation throw instead of answering.
     */
    public function throwOn(string $operationId, Throwable $e): self
    {
        $this->throws[$operationId] = $e;

        return $this;
    }

    /**
     * Build a signed synthetic delivery.
     *
     * Static, so it works with or without fake(): the receiver under test is the real
     * one, and this only makes the request.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function webhook(string $event, array $payload = []): PendingWebhook
    {
        return new PendingWebhook($event, $payload);
    }

    /**
     * Build a signed synthetic publish.verify hook.
     *
     * Static for the same reason webhook() is: the endpoint under test is the real
     * one, and this only makes the request. Needs `tmesis.hooks.enabled` true.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hook(array $payload = []): PendingHook
    {
        return new PendingHook($payload);
    }

    /**
     * Build a signed synthetic keyterms hook GET for a stream (tmesis v0.8.0), to test
     * an app's KeytermsProvider through the real route. Needs
     * `tmesis.hooks.keyterms.enabled` true.
     */
    public static function keytermsHook(string $stream = 'fake-stream'): PendingKeytermsHook
    {
        return new PendingKeytermsHook($stream);
    }

    /**
     * The revocation list as the last push left it.
     *
     * @return list<string>
     */
    public function revocations(): array
    {
        return $this->revocations;
    }

    /**
     * @return list<array{operation: string, node: string, args: array<string, mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|null  $args
     */
    public function assertCalled(string $operationId, ?callable $args = null): self
    {
        $matches = array_filter(
            $this->calls,
            fn (array $call): bool => $call['operation'] === $operationId
                && ($args === null || $args($call['args']))
        );

        PHPUnit::assertNotEmpty($matches, "The operation [{$operationId}] was not called.");

        return $this;
    }

    public function assertCalledCount(string $operationId, int $times): self
    {
        $count = count(array_filter($this->calls, fn (array $call): bool => $call['operation'] === $operationId));

        PHPUnit::assertSame($times, $count, "The operation [{$operationId}] was called {$count} times.");

        return $this;
    }

    public function assertNothingCalled(): self
    {
        PHPUnit::assertEmpty($this->calls, 'Expected no Tmesis calls.');

        return $this;
    }

    /**
     * Assert a transfer plan was PUT for the generation, accepted or not.
     *
     * The callback receives the plan body as recorded: `request_id`,
     * `expected_revision`, `inventory_sha256`, `reason` when set, and `targets`, with a
     * signed target's initial authorizations reduced to `artifact_path`, `target_id`,
     * `object_key` and the operations `supplied`. Never a URL or a header value.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $plan
     */
    public function assertTransferPlanPut(string $stream, string $generation, ?callable $plan = null): self
    {
        $matches = array_filter(
            $this->calls,
            static fn (array $call): bool => $call['operation'] === 'replaceTransferPlan'
                && $call['args']['streamId'] === $stream
                && $call['args']['generation'] === $generation
                && ($plan === null || (is_array($call['args']['plan']) && $plan($call['args']['plan'])))
        );

        PHPUnit::assertNotEmpty($matches, "No matching transfer plan was put for generation [{$generation}] of stream [{$stream}].");

        return $this;
    }

    /**
     * Assert the generation has a decision on its CURRENT plan revision: a release, an
     * override or a discard, each of which authorises the node's cleanup. Name
     * `$action` (`DecisionStatus::ACTION_*`) to require one kind.
     *
     * A decision on an older revision does not count: a later plan makes the
     * generation unreleased again, exactly as at the node.
     */
    public function assertReleased(string $stream, string $generation, ?string $action = null): self
    {
        $decision = $this->decisionOf($stream, $generation);

        PHPUnit::assertNotNull($decision, "Generation [{$generation}] of stream [{$stream}] was not released.");

        if ($action !== null) {
            PHPUnit::assertSame($action, $decision['action'], "Generation [{$generation}] of stream [{$stream}] was decided with [{$decision['action']}], not [{$action}].");
        }

        return $this;
    }

    public function assertNotReleased(string $stream, string $generation): self
    {
        PHPUnit::assertNull(
            $this->decisionOf($stream, $generation),
            "Generation [{$generation}] of stream [{$stream}] was released.",
        );

        return $this;
    }

    /**
     * Assert every seeded FINALIZED generation (of `$stream`, or of every stream) has
     * a decision on its current plan revision. The release obligation: from tmesis
     * `v0.5.0` the node deletes nothing without one, so a control plane that copies a
     * generation and never releases it fills the node's disk.
     */
    public function assertEveryFinalizedGenerationReleased(?string $stream = null): self
    {
        $missing = [];

        foreach ($this->artifactLedger()->generations() as [$owner, $generation, $state]) {
            if ($state !== ArtifactStatus::STATE_FINALIZED || ($stream !== null && $owner !== $stream)) {
                continue;
            }

            if ($this->artifactLedger()->currentDecision($generation) === null) {
                $missing[] = "{$owner}/{$generation}";
            }
        }

        PHPUnit::assertSame([], $missing, 'These finalized generations were never released: '.implode(', ', $missing).'.');

        return $this;
    }

    /**
     * Assert every seeded generation (of `$stream`, or of every stream) has a
     * decision: each FINALIZED one on its current plan revision, and each ABANDONED
     * one its discard (`OverrideRequest::discardAbandoned()`). The whole cleanup
     * obligation: an abandoned generation takes nothing but that discard, and one
     * never discarded stays on the node's disk forever.
     */
    public function assertEveryGenerationDecided(?string $stream = null): self
    {
        $missing = [];

        foreach ($this->artifactLedger()->generations() as [$owner, $generation, $state]) {
            if ($stream !== null && $owner !== $stream) {
                continue;
            }

            if ($this->artifactLedger()->currentDecision($generation) === null) {
                $missing[] = "{$owner}/{$generation} (".($state === ArtifactStatus::STATE_FINALIZED ? 'finalized, not released' : 'abandoned, not discarded').')';
            }
        }

        PHPUnit::assertSame([], $missing, 'These generations were never decided: '.implode(', ', $missing).'.');

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decisionOf(string $stream, string $generation): ?array
    {
        $ledger = $this->artifactLedger();

        PHPUnit::assertTrue($ledger->knows($stream, $generation), "No generation [{$generation}] on stream [{$stream}] was seeded.");

        return $ledger->currentDecision($generation);
    }

    /**
     * @param  array<string, mixed>  $args
     *
     * @internal used by the fake resources
     */
    public function record(string $operation, string $node, array $args = []): void
    {
        $this->calls[] = ['operation' => $operation, 'node' => $node, 'args' => $args];

        if (isset($this->throws[$operation])) {
            throw $this->throws[$operation];
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @internal
     */
    public function health(): array
    {
        $health = $this->health === [] ? self::DEFAULT_HEALTH : $this->health;

        // tmesis v0.9.0: a jobs OBJECT carries the pause in force. The fake's own
        // pause (setTranscodePause(), pauseJobsByFleet()) wins while it is active;
        // otherwise a canned `paused` stands, and an absent one is null.
        if (is_array($health['jobs'] ?? null)) {
            $pause = $this->jobsPause();

            $health['jobs']['paused'] = $pause->paused()
                ? ['until' => $pause->untilRaw(), 'reason' => $pause->reason(), 'source' => $pause->source()]
                : ($health['jobs']['paused'] ?? null);
        }

        return $health;
    }

    /**
     * The liveness-only answer: the four HealthPublic fields of health(), and nothing
     * a request with no token would not see.
     *
     * @return array<string, mixed>
     *
     * @internal
     */
    public function publicHealth(): array
    {
        return array_intersect_key($this->health(), array_flip(self::PUBLIC_HEALTH_FIELDS));
    }

    /**
     * @return array<string, mixed>
     *
     * @internal
     */
    public function doctor(): array
    {
        return $this->doctor;
    }

    /**
     * @return array<string, mixed>
     *
     * @internal
     */
    public function nodeStats(): array
    {
        return $this->nodeStats;
    }

    /**
     * @internal
     */
    public function streamList(?string $state): StreamList
    {
        $streams = array_values(array_filter(
            $this->streams,
            fn (StreamRecord $record): bool => $state === null || $record->state() === $state
        ));

        // Through stream(), so a preview_monitor block a test canned never shows on
        // the list: the list route does not carry it.
        return StreamList::fromArray([
            'count' => count($streams),
            'streams' => array_map(fn (StreamRecord $record): array => $this->stream($record->id())->toArray(), $streams),
        ]);
    }

    /**
     * The single-stream read, answered the way the node answers it: the
     * `preview_monitor` block is on the record only when the read sent
     * `include=preview`, and the node's shape with no `preview.public_url` set is a
     * path and a null URL. A record handed to withStream() that already carries the
     * block keeps its own values when asked, and never shows it when not.
     *
     * `captions.session` follows the same rule under `include=captions`, and is
     * absent (never null) while captions are off for the stream even when asked. Not
     * canned, it is an `idle` session: captions on, no provider socket yet.
     *
     * @internal
     */
    public function stream(string $id, bool $includePreview = false, bool $includeCaptions = false): StreamRecord
    {
        $raw = $this->stored($id)->toArray();

        /** @var array<string, mixed> $captions */
        $captions = is_array($raw['captions'] ?? null) ? $raw['captions'] : [];

        if ($includeCaptions && ($captions['enabled'] ?? false) === true) {
            $captions['session'] = is_array($captions['session'] ?? null) ? $captions['session'] : [
                'state' => 'idle',
                'provider' => null,
                'fallback' => null,
                'provider_request_id' => null,
                'started_at' => null,
                'finals' => 0,
                'dropped_pcm_ms' => 0,
                'reconnects' => 0,
                'reason' => null,
            ];
        } else {
            unset($captions['session']);
        }

        $raw['captions'] = $captions;

        if ($includePreview) {
            $monitor = $raw['preview_monitor'] ?? null;

            $raw['preview_monitor'] = is_array($monitor)
                ? $monitor
                : ['whep_path' => "/whep/v1/streams/{$id}", 'whep_url' => null];
        } else {
            unset($raw['preview_monitor']);
        }

        return StreamRecord::fromArray($raw);
    }

    /**
     * The record as the fake holds it, for the writes to build on.
     */
    private function stored(string $id): StreamRecord
    {
        return $this->streams[$id] ?? throw new NotFoundException("No such stream [{$id}].");
    }

    /**
     * @return array<string, mixed>
     *
     * @internal
     */
    public function streamStats(string $id): array
    {
        $stats = $this->streamStats[$id] ?? throw new NotFoundException("No such stream [{$id}].");

        // REQUIRED since tmesis v0.3.0, and null until a captioner has reported.
        if (! array_key_exists('captions', $stats)) {
            $stats['captions'] = null;
        }

        return $stats;
    }

    /**
     * The JSON transcript, tailed past `$since` the way the node tails it.
     *
     * @internal
     */
    public function streamTranscript(string $id, ?string $lang, ?int $since): TranscriptResponse
    {
        $body = $this->transcripts[$id] ?? null;

        if ($body === null || ($lang !== null && ($body['lang'] ?? null) !== $lang)) {
            throw self::noTranscript($id);
        }

        if ($since !== null && is_array($body['cues'] ?? null)) {
            $body['cues'] = array_values(array_filter(
                $body['cues'],
                static fn (mixed $cue): bool => is_array($cue) && is_int($cue['seq'] ?? null) && $cue['seq'] > $since,
            ));
        }

        // The response's provider is the first returned cue's, else the stream's primary.
        $first = is_array($body['cues'] ?? null) ? ($body['cues'][0] ?? null) : null;

        $body['provider'] = is_array($first) && is_string($first['provider'] ?? null)
            ? $first['provider']
            : $this->transcriptPrimaries[$id]
                ?? (isset($this->streams[$id]) ? $this->streams[$id]->captions()->provider() : $body['provider'] ?? null);

        return TranscriptResponse::fromArray($body);
    }

    /**
     * @internal
     */
    public function streamTranscriptText(string $id, string $format, ?string $lang): string
    {
        $seeded = $this->transcriptTexts[$id][$format] ?? null;

        if ($seeded === null || ($lang !== null && $seeded['lang'] !== null && $seeded['lang'] !== $lang)) {
            throw self::noTranscript($id);
        }

        return $seeded['document'];
    }

    /**
     * The node's one answer for no stream and no captions in that language alike.
     */
    private static function noTranscript(string $id): NotFoundException
    {
        return new NotFoundException("No transcript to render for stream [{$id}].");
    }

    /**
     * The timeline, with the stored rows sorted by `at` in the direction asked:
     * oldest first when none was, which is the node's default since rc7 (decision
     * D6). The rows a test canned are the window; the fake applies no `limit`.
     *
     * @return array<string, mixed>
     *
     * @internal
     */
    public function streamEvents(string $id, ?string $order = null): array
    {
        $body = $this->streamEvents[$id] ?? ['stream' => $id, 'count' => 0, 'limit' => 200, 'events' => []];

        $events = $body['events'] ?? null;

        if (! is_array($events)) {
            return $body;
        }

        $events = array_values($events);

        usort($events, static function (mixed $a, mixed $b): int {
            $left = is_array($a) && is_string($a['at'] ?? null) ? Rfc3339::parse($a['at']) : null;
            $right = is_array($b) && is_string($b['at'] ?? null) ? Rfc3339::parse($b['at']) : null;

            return $left <=> $right;
        });

        $body['events'] = $order === 'desc' ? array_reverse($events) : $events;

        return $body;
    }

    /**
     * @internal
     */
    public function relayList(string $streamId): RelayListResponse
    {
        $relays = $this->relays[$streamId] ?? [];

        return RelayListResponse::fromArray([
            'relays' => array_map(fn (RelayRecord $relay): array => $relay->toArray(), $relays),
        ]);
    }

    /**
     * @internal
     */
    public function relay(string $streamId, string $relayId): RelayRecord
    {
        foreach ($this->relays[$streamId] ?? [] as $relay) {
            if ($relay->id() === $relayId) {
                return $relay;
            }
        }

        throw new NotFoundException("No such relay [{$relayId}] on stream [{$streamId}].");
    }

    /**
     * Whether the fake already holds a stream with this id.
     *
     * @internal
     */
    public function knowsStream(string $id): bool
    {
        return isset($this->streams[$id]);
    }

    /**
     * The fake plays a STANDALONE box (decision D4): the ingest copy is on, as the
     * `edge` and `standalone` config default has it, kept 72 hours and local only;
     * the preview is on with 50 monitors. A consumer testing an origin-role node,
     * where the ingest copy defaults off, sets the block through withStream() or the
     * spec.
     *
     * @internal
     */
    public function createdStream(string $id, StreamSpec $spec): StreamRecord
    {
        $existing = $this->streams[$id] ?? null;

        $defaults = [
            'id' => $id,
            'state' => 'provisioned',
            'session' => null,
            'epoch' => null,
            'protocol' => null,
            'started_at' => null,
            'ladder' => 'default',
            'segment_seconds' => 6,
            'dvr_window_seconds' => 0,
            'delivery_mode' => 'origin',
            'min_key_epoch' => null,
            'webhook_url' => null,
            'labels' => [],
            'recording' => ['ingest' => ['enabled' => true, 'retention_hours' => 72, 'destination' => null, 'destination_ref' => null]],
            'preview' => ['enabled' => true, 'max_monitors' => 50],
            'captions' => self::DEFAULT_CAPTIONS,
            'fallback' => ['end_seconds' => self::DEFAULT_FALLBACK_END_SECONDS],
            'version' => 1,
            'created_at' => '2026-01-01T00:00:00.000Z',
            'updated_at' => '2026-01-01T00:00:00.000Z',
            'sealed_at' => null,
            // A string, as the component requires: the node builds it from
            // delivery.origin.url_prefix (default /hls/), the stream name and the
            // packager's name for the full-window master (WEBHOOKS.md 8.6).
            'playback' => ['master' => "/hls/{$id}/playlist_dvr.m3u8"],
        ];

        $base = array_replace($defaults, $existing === null ? [] : $existing->toArray());

        // Leaf by leaf, not array_replace at the top level: a spec that set only
        // preview.max_monitors must not drop preview.enabled from the record. relays
        // are WRITE ONLY and never land on a record: a stream record never echoes a
        // destination URL.
        foreach (self::leaves($spec->toArray()) as $path => $value) {
            if ($path === 'relays') {
                continue;
            }

            $base = self::setLeaf($base, $path, $value);
        }

        /** @var array<string, mixed> $captions */
        $captions = is_array($base['captions'] ?? null) ? $base['captions'] : [];
        $base['captions'] = self::effectiveCaptions($captions);
        $base = self::effectiveFallback($base);

        $record = StreamRecord::fromArray($base);

        $this->streams[$id] = $record;
        $this->provisionRelays($id, $spec);

        return $record;
    }

    /**
     * A PATCH, answered field by field with `applied` and `pending` keyed by the
     * DOTTED PATH of each changed leaf, the way the node keys them (`docs/API.md`
     * 9.6). `unchanged` is always `[]`: the fake has never computed it.
     *
     * @internal
     */
    public function patchedStream(string $id, StreamSpec $spec): PatchResponse
    {
        $record = $this->stored($id);
        $raw = $record->toArray();

        $applied = [];
        $pending = [];

        foreach (self::leaves($spec->toArray()) as $path => $value) {
            $when = self::PATCH_SEMANTICS[$path] ?? 'live';

            if ($when === 'live') {
                $applied[$path] = $when;

                // `relays` never lands on the record: it has no relays field at all,
                // the read surface is listRelays().
                if ($path !== 'relays') {
                    $raw = self::setLeaf($raw, $path, $value);
                }
            } else {
                // Everything the node would NOT have applied yet stays off the
                // record: `pending` and a read that already shows the new value
                // cannot both be true, and a consumer that patched a ladder mid-show
                // must see the running one until the next session.
                $pending[$path] = [
                    'when' => $when,
                    'current' => self::leaf($raw, $path),
                    'requested' => $value,
                    'reason' => "the fake applies {$when} changes the way the node does: not yet",
                ];
            }
        }

        $raw['version'] = $record->version() + 1;
        $raw = self::effectiveFallback($raw);

        $this->streams[$id] = StreamRecord::fromArray($raw);
        $this->provisionRelays($id, $spec);

        return PatchResponse::fromArray([
            'id' => $id,
            'version' => $record->version() + 1,
            'applied' => $applied,
            'pending' => $pending,
            'unchanged' => [],
            'audit_id' => self::FAKE_AUDIT_ID,
        ]);
    }

    /**
     * A captions block with every untold or null field resolved to the fake box's
     * effective value: the record's block is never null and neither is any field
     * of it. A canned `session` is kept.
     *
     * @param  array<string, mixed>  $captions
     * @return array<string, mixed>
     */
    private static function effectiveCaptions(array $captions): array
    {
        foreach (self::DEFAULT_CAPTIONS as $key => $default) {
            $value = $captions[$key] ?? null;

            if ($value === null || $value === []) {
                $captions[$key] = $default;
            }
        }

        // Stored as the node stores them: trimmed, duplicates removed case-insensitively
        // with the first spelling and the order kept. StreamSpec refused a list that
        // breaks a rule before it got here.
        if (is_array($captions['keyterms'])) {
            $captions['keyterms'] = Keyterms::normalize($captions['keyterms']) ?? [];
        }

        return $captions;
    }

    /**
     * The record's `fallback.end_seconds` is always the EFFECTIVE value: a spec that
     * sent null (the node default) reads the fake box's default back, as the node
     * does. Only a record that carries the block is touched, so a canned record from
     * before v0.6.0 stays as it was given.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function effectiveFallback(array $raw): array
    {
        if (is_array($raw['fallback'] ?? null) && ($raw['fallback']['end_seconds'] ?? null) === null) {
            $raw['fallback']['end_seconds'] = self::DEFAULT_FALLBACK_END_SECONDS;
        }

        return $raw;
    }

    /**
     * A spec's fields flattened to dotted leaf paths, descending into the nested
     * blocks (`recording.ingest.*`, `preview.*`) and keeping the whole-value fields
     * (`labels`, `relays`) as one path each.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function leaves(array $fields, string $prefix = ''): array
    {
        $out = [];

        foreach ($fields as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && ! in_array($path, self::WHOLE_FIELDS, true) && $value !== [] && ! array_is_list($value)) {
                /** @var array<string, mixed> $value */
                $out += self::leaves($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function leaf(array $raw, string $path): mixed
    {
        $cursor = $raw;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function setLeaf(array $raw, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $head = array_shift($segments);

        if ($segments === []) {
            $raw[$head] = $value;

            return $raw;
        }

        $child = $raw[$head] ?? null;

        /** @var array<string, mixed> $child */
        $child = is_array($child) ? $child : [];

        $raw[$head] = self::setLeaf($child, implode('.', $segments), $value);

        return $raw;
    }

    /**
     * @internal
     */
    public function sealedStream(string $id, ?int $graceSeconds): SealResponse
    {
        $record = $this->stored($id);

        $this->streams[$id] = StreamRecord::fromArray(array_replace(
            $record->toArray(),
            ['state' => 'sealed', 'session' => null, 'epoch' => null, 'sealed_at' => '2026-01-01T00:00:00.000Z'],
        ));

        // The EFFECTIVE grace, which is the node's 10 second default when the caller
        // named none.
        $grace = $graceSeconds ?? 10;

        return SealResponse::fromArray([
            'id' => $id,
            // `draining` while the grace period runs and `sealed` once there is none
            // to run, which is what the node's 202 carries (docs/API.md 9.7: sealing
            // is asynchronous and the 202 is not the completion signal). The fake has
            // no clock, so the RECORD above is already sealed; the response still
            // reports the state the node reports at the moment it answers.
            'state' => $grace > 0 ? 'draining' : 'sealed',
            'grace_seconds' => $grace,
            'seal' => true,
        ], started: $record->state() !== 'sealed');
    }

    /**
     * @internal
     */
    public function endedSession(string $id): SessionActionResponse
    {
        $record = $this->stored($id);
        $session = $record->session();

        $this->streams[$id] = StreamRecord::fromArray(array_replace(
            $record->toArray(),
            ['state' => 'provisioned', 'session' => null, 'epoch' => null, 'protocol' => null, 'started_at' => null],
        ));

        return SessionActionResponse::fromArray([
            'id' => $id,
            // Null when nobody was publishing, which is a SUCCESS and not an error.
            'session' => $session,
            'state' => 'provisioned',
        ]);
    }

    /**
     * @param  array<array-key, string>  $streams
     *
     * @internal
     */
    public function replacedRevocations(array $streams): RevocationResponse
    {
        // A FULL REPLACEMENT, the way the node treats it: whatever is not in the list
        // is no longer revoked, and [] clears it.
        $this->revocations = array_values($streams);

        return RevocationResponse::fromArray(['count' => count($this->revocations)]);
    }

    /**
     * @internal
     */
    public function createdRelay(string $streamId, RelayInput $relay): RelayRecord
    {
        $record = RelayRecord::fromArray([
            'id' => $relay->id() ?? $this->nextRelayId($streamId, $this->heldRelayIds($streamId)),
            // Redacted on the way in. The fake never holds the credential it was
            // handed, which is the same rule the real client follows.
            'url' => $relay->redacted(),
            'enabled' => $relay->enabled() ?? true,
            'source' => RelayRecord::SOURCE_API,
            'state' => ($relay->enabled() ?? true) ? RelayRecord::STATE_CONNECTING : RelayRecord::STATE_IDLE,
            'bytes_out' => 0,
            'dropped_bytes' => 0,
            'reconnects' => 0,
            'feed' => $relay->feed() ?? RelayFeed::AUTO,
            'feed_resolved' => self::resolvedFeed($relay->feed() ?? RelayFeed::AUTO, $relay->enabled() ?? true),
        ]);

        $this->relays[$streamId][] = $record;

        return $record;
    }

    /**
     * @internal
     */
    public function patchedRelay(string $streamId, string $relayId, RelayPatch $patch): RelayRecord
    {
        $existing = $this->relay($streamId, $relayId);

        $changes = [];

        if ($patch->redacted() !== null) {
            $changes['url'] = $patch->redacted();
        }

        if ($patch->enabled() !== null) {
            $changes['enabled'] = $patch->enabled();
            $changes['state'] = $patch->enabled() ? RelayRecord::STATE_CONNECTING : RelayRecord::STATE_STOPPED;
        }

        // A new feed REPLACES the destination on a running show (relay.stopped then
        // relay.started on the node); here that is the record re-entering connecting.
        if ($patch->feed() !== null) {
            $changes['feed'] = $patch->feed();
            $changes['state'] = ($changes['enabled'] ?? $existing->enabled()) ? RelayRecord::STATE_CONNECTING : RelayRecord::STATE_STOPPED;
        }

        $record = RelayRecord::fromArray(array_replace($existing->toArray(), $changes));

        // The resolved feed moves only when the feed or the running state did. A
        // URL-only patch on a connected or degraded destination keeps what it was
        // sending; recomputing from "connecting" alone cleared it to '' on those
        // states and handed consumer tests a relay that had stopped sending
        // (review, 2026-09-25).
        if ($patch->feed() !== null || $patch->enabled() !== null) {
            $record = RelayRecord::fromArray(array_replace($record->toArray(), [
                'feed_resolved' => self::resolvedFeed($record->feed(), self::relayRunning($record)),
            ]));
        }

        $this->replaceRelay($streamId, $record);

        return $record;
    }

    /**
     * @internal
     */
    public function deletedRelay(string $streamId, string $relayId): RelayStopResponse
    {
        // Throws NotFoundException for an unknown destination, the way the node
        // answers 404 for one.
        $this->relay($streamId, $relayId);

        $this->relays[$streamId] = array_values(array_filter(
            $this->relays[$streamId] ?? [],
            fn (RelayRecord $relay): bool => $relay->id() !== $relayId,
        ));

        // {stopped: 1}, not the record: the node answers both stop paths with the
        // same shape.
        return RelayStopResponse::fromArray(['stopped' => 1]);
    }

    /**
     * @internal
     */
    public function stoppedRelays(string $streamId): RelayStopResponse
    {
        $stopped = 0;
        $records = [];

        foreach ($this->relays[$streamId] ?? [] as $relay) {
            if ($relay->state() !== RelayRecord::STATE_STOPPED && $relay->state() !== RelayRecord::STATE_IDLE) {
                $stopped++;
            }

            $records[] = RelayRecord::fromArray(array_replace(
                $relay->toArray(),
                ['state' => RelayRecord::STATE_STOPPED, 'feed_resolved' => ''],
            ));
        }

        $this->relays[$streamId] = $records;

        return RelayStopResponse::fromArray(['stopped' => $stopped]);
    }

    /**
     * Apply a spec's `relays` array to the fake's relay collection the way the node
     * does (API.md 9.9c): the array is the whole `stream` source set, so mentioning
     * it replaces every `stream` relay and an empty array removes them all, while
     * `static` and `api` destinations are separate sources and survive. A spec that
     * does not mention `relays` leaves everything alone.
     *
     * Redacted on the way in, like createdRelay(): the spec's array carries the URL
     * in full and the fake never holds it.
     */
    private function provisionRelays(string $streamId, StreamSpec $spec): void
    {
        if (! $spec->mentions('relays')) {
            return;
        }

        $relays = $spec->toArray()['relays'];

        if (! is_array($relays)) {
            return;
        }

        $kept = array_values(array_filter(
            $this->relays[$streamId] ?? [],
            static fn (RelayRecord $relay): bool => $relay->source() !== RelayRecord::SOURCE_STREAM,
        ));

        // Every id this call will hold, collected BEFORE any mint: the survivors, and
        // every explicit id in the array, wherever it sits, so a mint earlier in the
        // array cannot take a number an explicit entry later in it has chosen.
        $taken = [];

        foreach ($kept as $relay) {
            $taken[$relay->id()] = true;
        }

        foreach ($relays as $relay) {
            if (is_array($relay) && is_string($relay['id'] ?? null)) {
                $taken[$relay['id']] = true;
            }
        }

        foreach ($relays as $relay) {
            if (! is_array($relay) || ! is_string($relay['url'] ?? null)) {
                continue;
            }

            $enabled = is_bool($relay['enabled'] ?? null) ? $relay['enabled'] : true;
            $feed = is_string($relay['feed'] ?? null) ? $relay['feed'] : RelayFeed::AUTO;

            $kept[] = RelayRecord::fromArray([
                'id' => is_string($relay['id'] ?? null) ? $relay['id'] : $this->nextRelayId($streamId, $taken),
                'url' => RelayUrl::redact($relay['url']),
                'enabled' => $enabled,
                'source' => RelayRecord::SOURCE_STREAM,
                'state' => $enabled ? RelayRecord::STATE_CONNECTING : RelayRecord::STATE_IDLE,
                'bytes_out' => 0,
                'dropped_bytes' => 0,
                'reconnects' => 0,
                'feed' => $feed,
                'feed_resolved' => self::resolvedFeed($feed, $enabled),
            ]);
        }

        $this->relays[$streamId] = $kept;
    }

    /**
     * Whether a destination is being sent anything: the three states in which the
     * node has a transport up (`connecting`, `connected`, `degraded`). `idle`,
     * `stopped` and `failed` send nothing.
     */
    private static function relayRunning(RelayRecord $relay): bool
    {
        return in_array($relay->state(), [
            RelayRecord::STATE_CONNECTING,
            RelayRecord::STATE_CONNECTED,
            RelayRecord::STATE_DEGRADED,
        ], true);
    }

    /**
     * What the fake says a destination is sent (`feed_resolved`), from the feed as
     * configured. The fake models no ladders (D3), so `auto` resolves to `ingest`,
     * and a `rung:<name>` resolves to itself as if the rung existed; a parked or
     * stopped destination resolves to nothing, which the node spells `''`.
     */
    private static function resolvedFeed(string $feed, bool $running): string
    {
        if (! $running) {
            return '';
        }

        return $feed === RelayFeed::AUTO ? RelayFeed::INGEST : $feed;
    }

    /**
     * The one place a relay id is minted, for the API path and the spec path alike.
     *
     * A MONOTONIC counter and not the current count: create two, delete the first,
     * create a third, and a count based id would mint `relay-2` twice. And it skips
     * any number a caller already used as an explicit id, so `relay-2` chosen by a
     * test and `relay-2` minted by the fake can never both exist. An explicit id is
     * never rewritten; only the minted ones move.
     *
     * @param  array<string, true>  $taken  every id the stream holds, or is about to
     */
    private function nextRelayId(string $streamId, array $taken): string
    {
        do {
            $this->relayIds[$streamId] = ($this->relayIds[$streamId] ?? 0) + 1;
            $id = 'relay-'.$this->relayIds[$streamId];
        } while (isset($taken[$id]));

        return $id;
    }

    /**
     * @return array<string, true>
     */
    private function heldRelayIds(string $streamId): array
    {
        $ids = [];

        foreach ($this->relays[$streamId] ?? [] as $relay) {
            $ids[$relay->id()] = true;
        }

        return $ids;
    }

    private function replaceRelay(string $streamId, RelayRecord $record): void
    {
        $out = [];

        foreach ($this->relays[$streamId] ?? [] as $relay) {
            $out[] = $relay->id() === $record->id() ? $record : $relay;
        }

        $this->relays[$streamId] = $out;
    }

    /**
     * The fields the node answers to a request with no bearer (the HealthPublic
     * component, `docs/API.md` 9.1).
     */
    private const PUBLIC_HEALTH_FIELDS = ['status', 'node', 'version', 'contract_version'];

    /**
     * A minimal HealthFull, so getHealth() answers something valid before a test has
     * said anything. Defined here rather than read from tests/, because this class
     * ships to consumers and their vendor/ has no tests directory of ours.
     */
    private const DEFAULT_HEALTH = [
        'status' => 'ok',
        'node' => 'fake-node',
        'node_role' => 'standalone',
        'version' => '0.0.0-fake',
        'contract_version' => 1,
        'started_at' => '2026-01-01T00:00:00.000Z',
        'uptime_seconds' => 0,
        'ready' => true,
        'draining' => false,
        'admission' => 'open',
        'config_source' => 'defaults',
        'ingest' => 'listening',
        'degraded_reasons' => [],
        'transcription' => [],
        'journal' => null,
        'streams' => null,
        'jobs' => null,
        'capacity' => null,
        'disk' => null,
        // tmesis v0.9.0 (handover 3.4 and 3.6): a box that does not encode live and
        // runs no jobs encoder.
        'live_capacity' => [
            'admission' => 'not_applicable',
            'source' => 'not_applicable',
            'unit_ladder' => '',
            'encode_slots_total' => null,
            'encode_slots_used' => 0,
            'device_sessions_total' => null,
            'device_sessions_used' => 0,
            'measured_at' => null,
            // v0.9.3: the fake's box has no self-hosted captions, so neither number is known.
            'caption_sessions_total' => null,
            'caption_sessions_used' => null,
        ],
        'video_engine' => null,
        'jobs_video_engine' => null,
    ];

    // ---------------------------------------------------------------------------
    // Transcode jobs and their generations (tmesis v0.6.0, LTWB). A job is submitted
    // through transcodeJobs()->createTranscodeJob(); a test then drives it with
    // startJob(), progressJob(), completeJob(), failJob() or cancelJob(). Its outputs'
    // generations live in the artifact ledger under the job, so the release
    // assertions above cover them.
    // ---------------------------------------------------------------------------

    private ?FakeJobLedger $jobs = null;

    /**
     * @internal used by FakeTranscodeJobsResource
     */
    public function jobLedger(): FakeJobLedger
    {
        return $this->jobs ??= new FakeJobLedger($this->artifactLedger());
    }

    /**
     * The submitted job, as getTranscodeJob() would answer it.
     */
    public function job(string $id): JobStatus
    {
        return $this->jobLedger()->get($id);
    }

    /**
     * The job a claim started running: `running`, `attempts` at least 1.
     */
    public function startJob(string $id, string $stage = 'fetch'): self
    {
        $this->jobLedger()->start($id, $stage);

        return $this;
    }

    /**
     * A running job's progress (`JobStatus::STAGES`).
     */
    public function progressJob(string $id, string $stage, float $percent): self
    {
        $this->jobLedger()->progress($id, $stage, $percent);

        return $this;
    }

    /**
     * Complete the job as the node does: one FINALIZED generation per output, the
     * Spec's targets filed as its revision 1 and every object landed. The generations
     * are held until released (`jobArtifacts()->releaseJobArtifact()`), which
     * `assertEveryFinalizedGenerationReleased()` checks. `$outputs` merges fields into
     * an output's report by kind; `['audio' => ['state' => 'failed', 'error' => [...]]]`
     * fails an optional output while the job completes.
     *
     * @param  array<string, array<string, mixed>>  $outputs
     * @return array<string, string> output kind to its generation id
     */
    public function completeJob(string $id, array $outputs = []): array
    {
        return $this->jobLedger()->complete($id, $outputs);
    }

    /**
     * Fail the job terminally; its plans are superseded with an empty revision.
     *
     * @return list<array{generation: string, target_id: string, object_key: string}>
     *                                                                                what had already landed, as `job.failed`'s `landed`
     */
    public function failJob(string $id, string $code = 'source_unreadable', string $class = JobError::CLASS_PERMANENT, ?string $stage = 'fetch'): array
    {
        return $this->jobLedger()->fail($id, $code, $class, $stage);
    }

    /**
     * Cancel the job on the node's side, as a DELETE from elsewhere would. `409
     * job_not_cancellable` on a terminal job, as at the node.
     */
    public function cancelJob(string $id): self
    {
        $this->jobLedger()->cancel($id);

        return $this;
    }

    /**
     * The objects that had already transferred when the job failed or was cancelled,
     * as `job.failed`'s `landed`: the node never deletes remotely, so these are the
     * caller's to remove. Empty for a job that has not wound down.
     *
     * @return list<array{generation: string, target_id: string, object_key: string}>
     */
    public function jobLanded(string $id): array
    {
        return $this->jobLedger()->landed($id);
    }

    /**
     * Seed a FINALIZED job generation directly, with its baseline plan (revision 0),
     * for a test that only exercises the read and release routes. `$kind` is one of
     * `ArtifactStatus::JOB_KINDS`.
     *
     * @param  list<InventoryEntry|array<string, mixed>>|null  $inventory
     * @param  array<string, string>  $labels
     */
    public function artifactFinalizedForJob(
        string $job,
        string $generation,
        string $kind = ArtifactStatus::KIND_JOB_HLS,
        ?array $inventory = null,
        ?string $inventorySha256 = null,
        array $labels = [],
        int $retentionHours = 72,
    ): self {
        $this->artifactLedger()->seedJobFinalized($job, $generation, $kind, $inventory, $inventorySha256, $labels, $retentionHours);

        return $this;
    }

    /**
     * Complete every object of one target of a job generation's current revision.
     */
    public function completeJobTransfer(string $job, string $generation, string $targetId): self
    {
        return $this->completeTransfer(FakeArtifactLedger::jobOwner($job), $generation, $targetId);
    }

    /**
     * The identity block of a job generation (`stream`, `session` and
     * `session_epoch` null, `job_id` set), to spread into a FakeTmesis::webhook()
     * payload.
     *
     * @return array<string, mixed>
     */
    public function jobArtifactIdentity(string $job, string $generation): array
    {
        return $this->artifactLedger()->identity(FakeArtifactLedger::jobOwner($job), $generation);
    }

    /**
     * Assert a job was submitted under `$idempotencyKey`. The callback receives the
     * recorded body, `Spec::toRedactedArray()`: never a presigned query string.
     *
     * @param  (callable(array<string, mixed>): bool)|null  $spec
     */
    public function assertJobSubmitted(string $idempotencyKey, ?callable $spec = null): self
    {
        $matches = array_filter(
            $this->calls,
            static fn (array $call): bool => $call['operation'] === 'createTranscodeJob'
                && is_array($call['args']['job'] ?? null)
                && ($call['args']['job']['idempotency_key'] ?? null) === $idempotencyKey
                && ($spec === null || $spec($call['args']['job'])),
        );

        PHPUnit::assertNotEmpty($matches, "No matching transcode job was submitted under [{$idempotencyKey}].");

        return $this;
    }

    /**
     * Assert the job was cancelled, through cancelTranscodeJob() or cancelJob().
     */
    public function assertJobCancelled(string $id): self
    {
        PHPUnit::assertTrue($this->jobLedger()->knows($id), "No job [{$id}] was submitted.");
        PHPUnit::assertSame(JobStatus::STATE_CANCELLED, $this->job($id)->state(), "Job [{$id}] was not cancelled.");

        return $this;
    }

    /**
     * Assert a job generation has a decision on its current plan revision.
     */
    public function assertJobArtifactReleased(string $job, string $generation, ?string $action = null): self
    {
        return $this->assertReleased(FakeArtifactLedger::jobOwner($job), $generation, $action);
    }

    /**
     * assertPurged() for a job generation (`jobArtifacts()->purgeJobArtifact()`).
     */
    public function assertJobArtifactPurged(string $job, string $generation): self
    {
        return $this->assertPurged(FakeArtifactLedger::jobOwner($job), $generation);
    }

    // ---------------------------------------------------------------------------
    // The told jobs pause (tmesis v0.9.0, handover 3.5). A box fact at the node; the
    // fake keeps one pause across its nodes, as it does for grants. A past `until` is
    // stored and reads inactive, the clear is idempotent, and fleetOwnsJobsPause()
    // makes the two writes answer `409 fleet_owned` as an enrolled box does.
    // ---------------------------------------------------------------------------

    /** @var array{until: ?string, reason: string, source: string, set_at: string}|null the stored pause */
    private ?array $jobsPause = null;

    private bool $jobsPauseFleetOwned = false;

    /** The routes a node with no job engine answers `501` (withoutJobEngine()). */
    private const JOB_ENGINE_OPERATIONS = [
        'createTranscodeJob', 'listTranscodeJobs', 'getTranscodeJob', 'cancelTranscodeJob',
        'setTranscodePause', 'getTranscodePause', 'clearTranscodePause',
    ];

    /**
     * Make the box ENROLLED for the pause routes: setTranscodePause() and
     * clearTranscodePause() answer `409 fleet_owned` (detail `jobs_pause`);
     * getTranscodePause() still answers. Pair it with pauseJobsByFleet() to model the
     * desired state's pause.
     */
    public function fleetOwnsJobsPause(bool $owned = true): self
    {
        $this->jobsPauseFleetOwned = $owned;

        return $this;
    }

    /**
     * Seed the pause the desired state's `jobs_pause` would apply (`source: fleet`).
     * Null clears it, as a revision without `jobs_pause` does.
     */
    public function pauseJobsByFleet(?JobPauseRequest $pause): self
    {
        $this->jobsPause = $pause === null ? null : $this->storedJobsPause($pause, JobPauseResponse::SOURCE_FLEET);

        return $this;
    }

    /**
     * Make createTranscodeJob() answer `503 encoder_unavailable` (an
     * EncoderUnavailableException), as a box whose pinned jobs encoder failed at boot
     * does (handover 3.4). Recorded first, like every throwOn().
     */
    public function jobsEncoderUnavailable(string $reason = 'h264_nvenc: no /dev/nvidia* device'): self
    {
        return $this->throwOn('createTranscodeJob', self::fakeError(
            503,
            'encoder_unavailable',
            'the pinned jobs video encoder is not usable on this box: '.$reason,
        ));
    }

    /**
     * Make the box one with NO job engine (a build without a job runner): every
     * transcode route, the three pause routes included, answers `501 not_implemented`
     * naming M3, as the node does (a NotImplementedException). Health `jobs` stays as
     * canned; the default is already null, a box with no engine.
     */
    public function withoutJobEngine(): self
    {
        $error = ErrorMap::toException(501, ErrorResponse::fromArray(['error' => [
            'code' => 'not_implemented',
            'message' => 'this build has no job runner; it lands at M3',
            'details' => null,
            'request_id' => 'fake',
            'milestone' => 'M3',
        ]]));

        foreach (self::JOB_ENGINE_OPERATIONS as $operation) {
            $this->throwOn($operation, $error);
        }

        return $this;
    }

    /**
     * The pause as getTranscodePause() answers it now.
     */
    public function jobsPause(): JobPauseResponse
    {
        $pause = $this->jobsPause;

        if ($pause === null || ($pause['until'] !== null && Rfc3339::parse($pause['until']) <= self::clockNow())) {
            return JobPauseResponse::fromArray(['paused' => false]);
        }

        return JobPauseResponse::fromArray(['paused' => true, ...$pause]);
    }

    /**
     * @internal used by FakeTranscodeJobsResource
     */
    public function setJobsPause(JobPauseRequest $pause): JobPauseResponse
    {
        $this->refuseFleetOwnedPause();

        $this->jobsPause = $this->storedJobsPause($pause, JobPauseResponse::SOURCE_API);

        return $this->jobsPause();
    }

    /**
     * @internal used by FakeTranscodeJobsResource
     */
    public function clearJobsPause(): void
    {
        $this->refuseFleetOwnedPause();

        $this->jobsPause = null;
    }

    /**
     * Assert the pause is active, and when given, its source.
     */
    public function assertJobsPaused(?string $source = null): self
    {
        $pause = $this->jobsPause();

        PHPUnit::assertTrue($pause->paused(), 'No jobs pause is active.');

        if ($source !== null) {
            PHPUnit::assertSame($source, $pause->source(), 'The jobs pause has another source.');
        }

        return $this;
    }

    public function assertJobsNotPaused(): self
    {
        PHPUnit::assertFalse($this->jobsPause()->paused(), 'A jobs pause is active.');

        return $this;
    }

    /**
     * @return array{until: ?string, reason: string, source: string, set_at: string}
     */
    private function storedJobsPause(JobPauseRequest $pause, string $source): array
    {
        $until = $pause->until();

        return [
            // Stored in UTC with its fraction, whatever offset it was sent with: the node
            // keeps UnixNano and encoding/json writes RFC3339Nano (trailing zeros trimmed,
            // no fraction when zero). The microsecond is PHP's limit.
            'until' => $until === null ? null : Rfc3339::formatNano(new DateTimeImmutable($until)),
            'reason' => $pause->reason(),
            'source' => $source,
            'set_at' => Rfc3339::formatNano(self::clockNow()),
        ];
    }

    private function refuseFleetOwnedPause(): void
    {
        if (! $this->jobsPauseFleetOwned) {
            return;
        }

        throw ErrorMap::toException(409, ErrorResponse::fromArray(['error' => [
            'code' => 'fleet_owned',
            'message' => "the fleet owns this; change it in the control plane's desired state",
            'details' => [['path' => 'jobs_pause', 'message' => "is owned by the fleet's desired state (jobs_pause)"]],
            'request_id' => 'fake',
            'milestone' => null,
        ]]));
    }

    // ---------------------------------------------------------------------------
    // Preview grants and markers (tmesis v0.6.0, LTWC). Grants are NODE-scoped at the
    // node; the fake keeps one ledger across its nodes, as it does for streams.
    // ---------------------------------------------------------------------------

    /** @var array<string, array<string, mixed>> the LIVE grants by id, oldest first */
    private array $previewGrants = [];

    /** @var list<string> the ids revoked through revokePreviewGrant() */
    private array $revokedPreviewGrants = [];

    /**
     * Every marker told, per stream, oldest first, with the body it was told with so
     * a repeat can be told from a conflict.
     *
     * @var array<string, list<array{record: array<string, mixed>, request: array<string, mixed>}>>
     */
    private array $markers = [];

    /**
     * @internal
     */
    public function createdPreviewGrant(PreviewGrantInput $input): PreviewGrant
    {
        $now = self::clockNow();
        $ips = [];

        // The node's canonical form: unmapped, compressed, duplicates dropped.
        foreach ($input->ips() as $ip) {
            $canonical = self::canonicalIp($ip);

            if (! in_array($canonical, $ips, true)) {
                $ips[] = $canonical;
            }
        }

        $grant = [
            'id' => (string) Str::ulid(),
            'ips' => $ips,
            'streams' => $input->streams(),
            'labels' => $input->labels(),
            'created_at' => Rfc3339::format($now),
            'expires_at' => Rfc3339::format($now->modify("+{$input->ttlSeconds()} seconds")),
        ];

        $this->previewGrants[$grant['id']] = $grant;

        return PreviewGrant::fromArray($grant);
    }

    /**
     * @internal
     */
    public function previewGrantList(): PreviewGrantListResponse
    {
        return PreviewGrantListResponse::fromArray(['grants' => array_values($this->livePreviewGrants())]);
    }

    /**
     * @internal
     */
    public function updatedPreviewGrant(string $grantId, PreviewGrantPatch $patch): PreviewGrant
    {
        $grant = $this->livePreviewGrants()[$grantId] ?? throw self::noGrant($grantId);
        $now = self::clockNow();

        // Now plus the ttl, later or earlier than before: the node's rule.
        $grant['expires_at'] = Rfc3339::format($now->modify("+{$patch->ttlSeconds()} seconds"));
        $this->previewGrants[$grantId] = $grant;

        return PreviewGrant::fromArray($grant);
    }

    /**
     * @internal
     */
    public function revokedPreviewGrant(string $grantId): PreviewGrant
    {
        $grant = $this->livePreviewGrants()[$grantId] ?? throw self::noGrant($grantId);

        unset($this->previewGrants[$grantId]);
        $this->revokedPreviewGrants[] = $grantId;

        return PreviewGrant::fromArray($grant);
    }

    /**
     * The LIVE grants: not revoked and not past `expires_at`.
     *
     * @return list<PreviewGrant>
     */
    public function grants(): array
    {
        return array_values(array_map(PreviewGrant::fromArray(...), $this->livePreviewGrants()));
    }

    /**
     * Assert a live grant covers the address (in the node's canonical form, so
     * `::ffff:203.0.113.7` and `203.0.113.7` are one) and, when given, the stream.
     */
    public function assertGranted(string $ip, ?string $stream = null): self
    {
        $ip = self::canonicalIp($ip);
        $covered = array_filter($this->grants(), static fn (PreviewGrant $grant): bool => $grant->covers($ip, $stream));

        PHPUnit::assertNotEmpty($covered, "No live preview grant covers [{$ip}]".($stream === null ? '.' : " on stream [{$stream}]."));

        return $this;
    }

    public function assertRevoked(string $grantId): self
    {
        PHPUnit::assertContains($grantId, $this->revokedPreviewGrants, "The preview grant [{$grantId}] was not revoked.");

        return $this;
    }

    /**
     * @internal
     */
    public function placedMarker(string $streamId, MarkerRequest $request): MarkerResponse
    {
        $stream = $this->stored($streamId);

        // No live session with a packager: nobody publishing, or draining. The fake
        // counts `publishing` and `live` as live and models no passthrough node.
        if (! in_array($stream->state(), [StreamRecord::STATE_PUBLISHING, StreamRecord::STATE_LIVE], true)) {
            throw self::fakeError(409, 'stream_not_live', "The stream {$streamId} has no live session with a packager.");
        }

        $session = $stream->session() ?? 'fake-session';
        $body = $request->toArray();

        foreach ($this->markers[$streamId] ?? [] as $told) {
            if ($told['record']['id'] !== $request->id() || $told['record']['session'] !== $session) {
                continue;
            }

            if ($told['request'] !== $body) {
                throw self::fakeError(409, 'marker_exists', "A different marker already has the id {$request->id()} on this session.");
            }

            return MarkerResponse::fromArray(['marker' => $told['record']], created: false);
        }

        $record = [
            'id' => $request->id(),
            'stream' => $streamId,
            'session' => $session,
            'kind' => $request->kind(),
            'class' => $body['class'] ?? MarkerRequest::DEFAULT_CLASS,
            'at' => $body['at'] ?? null,
            'duration_seconds' => $body['duration_seconds'] ?? null,
            'scte35' => isset($body['scte35']) && is_string($body['scte35']) ? strtoupper((string) preg_replace('/^0x/i', '', $body['scte35'])) : null,
            'state' => MarkerRecord::STATE_QUEUED,
            'start_date' => null,
            'placed_at' => null,
            'complete' => false,
            'placements' => [],
            'created_at' => Rfc3339::format(self::clockNow()),
        ];

        $this->markers[$streamId][] = ['record' => $record, 'request' => $body];

        return MarkerResponse::fromArray(['marker' => $record], created: true);
    }

    /**
     * @internal
     */
    public function markerList(string $streamId): MarkerListResponse
    {
        $this->stored($streamId);

        return MarkerListResponse::fromArray(['markers' => array_map(
            static fn (array $told): array => $told['record'],
            $this->markers[$streamId] ?? [],
        )]);
    }

    /**
     * Land a queued marker, as the node does at the next cut: `placed`, one placement
     * per rendition, and the `marker.placed` delivery a consumer's receiver would get,
     * returned to deliver (`->deliverTo($app)`). The fake models no ladders, so the
     * renditions are the caller's.
     *
     * @param  list<string>  $renditions
     */
    public function landMarker(string $streamId, string $markerId, array $renditions = ['720p'], bool $complete = true): PendingWebhook
    {
        foreach ($this->markers[$streamId] ?? [] as $index => $told) {
            if ($told['record']['id'] !== $markerId || $told['record']['state'] !== MarkerRecord::STATE_QUEUED) {
                continue;
            }

            $now = Rfc3339::format(self::clockNow());
            $record = $told['record'];
            $record['state'] = MarkerRecord::STATE_PLACED;
            $record['start_date'] = $record['at'] ?? $now;
            $record['placed_at'] = $now;
            $record['complete'] = $complete;
            $record['placements'] = array_map(static fn (string $rendition): array => [
                'rendition' => $rendition,
                'segment_index' => 0,
                'media_sequence' => 0,
                'program_date_time' => $now,
            ], $renditions);

            $this->markers[$streamId][$index] = ['record' => $record, 'request' => $told['request']];

            return self::webhook('marker.placed', [
                'stream' => $streamId,
                'session' => $record['session'],
                'epoch' => $this->stored($streamId)->epoch()->value ?? 1,
                'marker' => $markerId,
                'kind' => $record['kind'],
                'class' => $record['class'],
                'duration_seconds' => $record['duration_seconds'],
                'scte35' => $record['scte35'],
                'start_date' => $record['start_date'],
                'complete' => $complete,
                'placements' => $record['placements'],
                'labels' => $this->stored($streamId)->labels(),
            ]);
        }

        throw new NotFoundException("No queued marker [{$markerId}] on stream [{$streamId}].");
    }

    /**
     * The markers told to a stream, oldest first.
     *
     * @return list<MarkerRecord>
     */
    public function markers(string $streamId): array
    {
        return array_map(static fn (array $told): MarkerRecord => MarkerRecord::fromArray($told['record']), $this->markers[$streamId] ?? []);
    }

    /**
     * Assert a marker with this id was told to the stream (queued or since landed),
     * optionally matching the record.
     *
     * @param  (callable(MarkerRecord): bool)|null  $marker
     */
    public function assertMarkerPlaced(string $streamId, string $markerId, ?callable $marker = null): self
    {
        $matches = array_filter(
            $this->markers($streamId),
            static fn (MarkerRecord $record): bool => $record->id() === $markerId && ($marker === null || $marker($record)),
        );

        PHPUnit::assertNotEmpty($matches, "No marker [{$markerId}] was placed on stream [{$streamId}].");

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function livePreviewGrants(): array
    {
        $now = self::clockNow();

        return array_filter(
            $this->previewGrants,
            static fn (array $grant): bool => is_string($grant['expires_at']) && Rfc3339::parse($grant['expires_at']) > $now,
        );
    }

    /**
     * Laravel's clock, in UTC, so `$this->travel()` and `Carbon::setTestNow()` move
     * grant expiry and marker times the way they move everything else in an app's
     * tests. The wall clock would hide an expiry bug.
     */
    private static function clockNow(): DateTimeImmutable
    {
        return Date::now()->toImmutable()->utc();
    }

    private static function canonicalIp(string $ip): string
    {
        $packed = @inet_pton($ip);
        $canonical = $packed === false ? $ip : (string) inet_ntop($packed);

        // An IPv4-mapped IPv6 address is stored as its IPv4 form.
        if (str_starts_with($canonical, '::ffff:') && str_contains($canonical, '.')) {
            return substr($canonical, 7);
        }

        return $canonical;
    }

    private static function noGrant(string $grantId): NotFoundException
    {
        return new NotFoundException("No such live grant [{$grantId}] (unknown, expired or revoked).");
    }

    /**
     * Through ErrorMap, so a consumer's catch and `code()` see what the node gives.
     */
    private static function fakeError(int $status, string $code, string $message): TmesisException
    {
        return ErrorMap::toException($status, ErrorResponse::fromArray(['error' => [
            'code' => $code,
            'message' => $message,
            'details' => null,
            'request_id' => 'fake',
            'milestone' => null,
        ]]));
    }

    /**
     * Redact a URL the way the package does everywhere else.
     */
    public static function redactRelayUrl(string $url): string
    {
        return RelayUrl::redact($url);
    }
}
