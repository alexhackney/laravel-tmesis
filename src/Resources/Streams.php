<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Resources;

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Data\Streams\PatchResponse;
use AlexHackney\Tmesis\Data\Streams\RevocationResponse;
use AlexHackney\Tmesis\Data\Streams\SealResponse;
use AlexHackney\Tmesis\Data\Streams\SessionActionResponse;
use AlexHackney\Tmesis\Data\Streams\StreamEventList;
use AlexHackney\Tmesis\Data\Streams\StreamList;
use AlexHackney\Tmesis\Data\Streams\StreamRecord;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Data\Streams\StreamStats;
use AlexHackney\Tmesis\Data\Streams\TranscriptResponse;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeInterface;

/**
 * Streams: the reads, the provisioning writes, and the two session actions. Method
 * names are the openapi operationIds.
 *
 * Since tmesis `v0.2.0` the openapi document carries every route's request body and
 * its path and query parameters (board gap G9 closed), so the REQUEST shapes this
 * class sends are the components `CreateStreamRequest` and `StreamSpec` (with the
 * nested `RecordingSpec`, `IngestRecordingSpec` and `PreviewSpec`),
 * `RevocationRequest`, and the declared query parameters (`state`, `include`,
 * `since`/`from`, `until`/`to`, `limit`, `order`, `seal`, `grace_seconds`,
 * `retain_config`); the relay bodies are `RelayInput` and `RelayPatch` on Relays. A
 * `schemas-requests` leg will validate what this class renders
 * against those components. The shipped Go types in `internal/api/streams.go`,
 * `streamhandlers.go`, `streamvalidate.go` and `changesemantics.go` remain the
 * reference for the SEMANTICS (change classes, the merged-row checks, what each
 * `422` means). Every RESPONSE is pinned to a component and is validated.
 *
 * The four WHEP operations (`whepPreflight`, `whepJoin`, `whepLeave`,
 * `whepSessionPreflight`, since rc7) are deliberately NOT here. They carry
 * `x-tmesis-listener: preview` in the openapi document: they are served on the
 * node's PREVIEW listener, not the admin API, with no bearer token, an SDP offer in
 * and `application/sdp` out, and the client is a WHEP player in a browser. The
 * monitor URL a control plane hands that player comes from
 * `getStream($id, includePreview: true)->previewMonitor()` or from `stream.live`'s
 * `playback()->preview()`; the `routes` leg asserts the four stay unwrapped. Every
 * WHEP `201` carries `X-Tmesis-Stream-State: live|held`, a snapshot at answer time,
 * exposed cross-origin: a HELD session answers `201` with `held` and no frames until
 * the rejoin, and only a hold entered before the tap's first init is `409 not_live`
 * (API.md 9.20; tmesis D1, 2026-09-18).
 *
 * The transcript (`GET /api/v1/streams/{id}/transcript`, `getStreamTranscript`,
 * since tmesis `v0.3.0`, API.md 9.5b) is a TAIL READ: `since` is a cue `seq`,
 * exclusive, so a caller polls with the last `seq` it saw while the show runs, and
 * the same route answers the whole session once sealed. Its `200` is one of three
 * media types: `format=json` (the default) is the typed feed, getStreamTranscript();
 * `vtt` and `srt` are opaque documents, getStreamTranscriptText(). A `404` covers no
 * such stream AND a stream with no captions in that language, one answer the node
 * does not distinguish.
 */
class Streams
{
    /**
     * The six states this build emits and the list filter validates against
     * (`internal/api/streamhandlers.go`; `input_lost` joined at rc5).
     *
     * @var list<string>
     */
    public const STATES = ['provisioned', 'publishing', 'live', 'input_lost', 'draining', 'sealed'];

    /**
     * The two reading orders of the events route (`docs/API.md` 9.5a).
     *
     * @var list<string>
     */
    public const ORDERS = ['asc', 'desc'];

    /**
     * The two `include` tokens the single-stream read accepts (`docs/API.md` 9.5):
     * `preview` since rc7, `captions` since tmesis `v0.3.0`. They compose, sent
     * comma-joined in this order.
     */
    public const INCLUDE_PREVIEW = 'preview';

    public const INCLUDE_CAPTIONS = 'captions';

    /**
     * The transcript renderings that are NOT the typed feed, with the `Accept` each
     * answers as (`docs/API.md` 9.5b). `json` is getStreamTranscript().
     *
     * @var array<string, string>
     */
    public const TRANSCRIPT_TEXT_FORMATS = [
        'vtt' => 'text/vtt',
        'srt' => 'application/x-subrip',
    ];

    public function __construct(private readonly Transport $transport) {}

    /**
     * GET /api/v1/streams.
     *
     * The `include` query parameter is refused with a 422 for every value on the list
     * route at this tag, so this method never sends it. The single-stream read
     * accepts one token: getStream().
     */
    public function listStreams(?string $state = null): StreamList
    {
        if ($state !== null && ! in_array($state, self::STATES, true)) {
            throw InvalidConfigurationException::invalid(
                'state',
                'it must be one of '.implode(', ', self::STATES)
            );
        }

        return StreamList::fromArray($this->transport->get('/api/v1/streams', ['state' => $state]));
    }

    /**
     * GET /api/v1/streams/:id.
     *
     * Two `include` tokens, which compose: `preview` (since rc7) adds the
     * `preview_monitor` block, read through `StreamRecord::previewMonitor()`;
     * `captions` (since tmesis `v0.3.0`) adds `captions.session`, read through
     * `StreamRecord::captions()->session()`, and only while captions are on for the
     * stream. Any other token is a 422 at the node, and each block is absent without
     * its token.
     */
    public function getStream(string $id, bool $includePreview = false, bool $includeCaptions = false): StreamRecord
    {
        return StreamRecord::fromArray($this->transport->get(
            $this->transport->path('/api/v1/streams/%s', $id),
            ['include' => self::includeTokens($includePreview, $includeCaptions)],
        ));
    }

    /**
     * The `include` value for the single-stream read, null when it asks for nothing.
     *
     * The openapi document schemas the parameter as ONE enum token while its
     * description and API.md 9.5 accept a comma-separated set; the node parses the
     * set, and this sends both tokens joined when both are asked for.
     */
    public static function includeTokens(bool $preview, bool $captions): ?string
    {
        $tokens = array_keys(array_filter([
            self::INCLUDE_PREVIEW => $preview,
            self::INCLUDE_CAPTIONS => $captions,
        ]));

        return $tokens === [] ? null : implode(',', $tokens);
    }

    /**
     * GET /api/v1/streams/:id/transcript, the JSON feed (`format=json`, the node's
     * default, so no `format` is sent).
     *
     * `$lang` absent is the stream's effective first language. `$since` is the last
     * cue `seq` already seen (exclusive; `TranscriptResponse::lastSeq()` hands it
     * back), absent meaning the whole session. A 404 is no such stream OR no captions
     * in that language, and arrives as NotFoundException either way.
     */
    public function getStreamTranscript(string $id, ?string $lang = null, ?int $since = null): TranscriptResponse
    {
        self::guardTranscriptSince($since);

        return TranscriptResponse::fromArray($this->transport->get(
            $this->transport->path('/api/v1/streams/%s/transcript', $id),
            ['lang' => $lang, 'since' => $since],
        ));
    }

    /**
     * GET /api/v1/streams/:id/transcript as a WebVTT or SRT document, returned
     * verbatim.
     *
     * `$format` is `vtt` (`text/vtt`) or `srt` (`application/x-subrip`), refused
     * before the request otherwise; the typed feed is getStreamTranscript(). Both
     * documents are rendered from the same cues as the feed, and errors are the JSON
     * envelope whatever was asked for.
     */
    public function getStreamTranscriptText(string $id, string $format, ?string $lang = null, ?int $since = null): string
    {
        self::guardTranscriptFormat($format);
        self::guardTranscriptSince($since);

        return $this->transport->getText(
            $this->transport->path('/api/v1/streams/%s/transcript', $id),
            ['format' => $format, 'lang' => $lang, 'since' => $since],
            self::TRANSCRIPT_TEXT_FORMATS[$format],
        );
    }

    /**
     * GET /api/v1/streams/:id/stats.
     */
    public function getStreamStats(string $id): StreamStats
    {
        return StreamStats::fromArray(
            $this->transport->get($this->transport->path('/api/v1/streams/%s/stats', $id))
        );
    }

    /**
     * GET /api/v1/streams/:id/events.
     *
     * THE WINDOW IS ALWAYS THE MOST RECENT `limit` ROWS inside `since`/`until`
     * (`limit` defaults to 200 at the node, is clamped to 1000 rather than refused,
     * and the echoed `limit` says what applied). `order` only sets the direction they
     * are read in, and the default is `asc` since 2026-09-15 (rc7): oldest first, the
     * way `journalctl -n 200` reads, the last 200 things that happened in the order
     * they happened. `desc` is newest first, for a panel that renders the latest row
     * at the top. A consumer that assumed `events()[0]` is the newest row is wrong
     * from rc7 on; one that wants the FIRST rows of a long show asks with `since`.
     */
    public function getStreamEvents(
        string $id,
        ?DateTimeInterface $since = null,
        ?DateTimeInterface $until = null,
        ?int $limit = null,
        ?string $order = null,
    ): StreamEventList {
        if ($order !== null && ! in_array($order, self::ORDERS, true)) {
            throw InvalidConfigurationException::invalid(
                'order',
                'it must be one of '.implode(', ', self::ORDERS)
            );
        }

        return StreamEventList::fromArray($this->transport->get(
            $this->transport->path('/api/v1/streams/%s/events', $id),
            [
                'since' => $since === null ? null : Rfc3339::format($since),
                'until' => $until === null ? null : Rfc3339::format($until),
                'limit' => $limit,
                'order' => $order,
            ],
        ));
    }

    /**
     * POST /api/v1/streams: provision a stream.
     *
     * IDEMPOTENT ON THE ID. Re-posting the same id with a config that resolves
     * identically answers 200 with the existing record and creates nothing; a
     * different desired config is 409 and arrives as ConflictException. Changing a
     * stream goes through updateStream(), where the change semantics are declared and
     * audited.
     *
     * The 422 cases: an unknown ladder, a segment grid the ladder's keyframe cadence
     * cannot hold (since tmesis `v0.2.0`; API.md 9.4 and 9.6), an unknown field. All
     * arrive as ValidationException carrying the node's `details`.
     */
    public function createStream(string $id, ?StreamSpec $spec = null): StreamRecord
    {
        $body = ['id' => $id] + ($spec ?? StreamSpec::make())->toRequestBody();

        return StreamRecord::fromArray($this->transport->post('/api/v1/streams', $body)->body);
    }

    /**
     * POST /api/v1/streams, reporting whether it created anything.
     *
     * The fact lives in the status code and nowhere in the body: 201 provisioned a
     * stream, 200 found one that already resolved identically. A caller that only
     * needs the record should use createStream().
     *
     * @return array{0: StreamRecord, 1: bool} the record, and whether this call created it
     */
    public function createStreamWithOutcome(string $id, ?StreamSpec $spec = null): array
    {
        $body = ['id' => $id] + ($spec ?? StreamSpec::make())->toRequestBody();

        $result = $this->transport->post('/api/v1/streams', $body);

        return [StreamRecord::fromArray($result->body), $result->status === 201];
    }

    /**
     * PATCH /api/v1/streams/:id: change a provisioned stream.
     *
     * A PARTIAL document. Only the fields the spec mentions are sent, and a field
     * mentioned as null is CLEARED rather than ignored, so StreamSpec is what carries
     * the difference between "leave min_key_epoch alone" and "remove the floor".
     *
     * The answer says what happened field by field, and a field can be pending rather
     * than applied: reading the 200 as "it is set" is how a control plane comes to
     * believe a ladder switched mid-show. A milestone field answers 501 and arrives as
     * NotImplementedException carrying the milestone that delivers it.
     *
     * The 422 cases: an unknown ladder, a segment grid the ladder's keyframe cadence
     * cannot hold (since tmesis `v0.2.0`; API.md 9.4 and 9.6; the `details[].path` is
     * `ladder` when only the ladder changed), an unknown field. All arrive as
     * ValidationException carrying the node's `details`.
     */
    public function updateStream(string $id, StreamSpec $spec): PatchResponse
    {
        self::guardPatch($spec);

        return PatchResponse::fromArray($this->transport->patch(
            $this->transport->path('/api/v1/streams/%s', $id),
            $spec->toRequestBody(),
        )->body);
    }

    /**
     * DELETE /api/v1/streams/:id: seal the stream and write its replay.
     *
     * The parameters are a QUERY, not a body. Every value in the answer is EFFECTIVE
     * rather than an echo of the request.
     *
     * `$retainConfig` keeps the provisioning record so the same id can be published
     * again without re-provisioning it. `seal: false` (discard instead of seal) is
     * RESERVED at v1 and refused with a 422 before anything happens, so this method
     * does not offer it.
     */
    public function deleteStream(string $id, ?int $graceSeconds = null, ?bool $retainConfig = null): SealResponse
    {
        if ($graceSeconds !== null && ($graceSeconds < 0 || $graceSeconds > 300)) {
            throw InvalidConfigurationException::invalid('grace_seconds', 'it must be between 0 and 300');
        }

        $result = $this->transport->delete(
            $this->transport->path('/api/v1/streams/%s', $id),
            ['grace_seconds' => $graceSeconds, 'retain_config' => $retainConfig],
        );

        // 202 started the seal; 200 is a retry of one already under way.
        return SealResponse::fromArray($result->body, started: $result->status === 202);
    }

    /**
     * POST /api/v1/streams/:id/kick: end the current publish session now.
     *
     * READS NO REQUEST BODY, so none is sent. A kick with nobody publishing is a
     * SUCCESS with a null session, which is what makes a control-plane retry safe.
     * The stream stays provisioned; sealing is deleteStream().
     */
    public function kickStream(string $id): SessionActionResponse
    {
        return SessionActionResponse::fromArray(
            $this->transport->post($this->transport->path('/api/v1/streams/%s/kick', $id))->body
        );
    }

    /**
     * POST /api/v1/streams/:id/takeover: displace the standing publisher in favour of
     * the next one.
     *
     * Also reads no request body. `docs/API.md` 9.9 describes a takeover WINDOW and a
     * request body; neither is shipped at `v0.1.0-rc2` and both are M4.
     */
    public function takeoverStream(string $id): SessionActionResponse
    {
        return SessionActionResponse::fromArray(
            $this->transport->post($this->transport->path('/api/v1/streams/%s/takeover', $id))->body
        );
    }

    /**
     * POST /api/v1/keys/revocations: replace the revocation list.
     *
     * A FULL REPLACEMENT of the list, not an addition to it: whatever is not in
     * `$streams` is no longer revoked, and `[]` clears the list entirely. That is
     * deliberate upstream, because a removed entry has to be able to disappear.
     *
     * Revocation works on the NEXT connection and never the current one: the verifier
     * runs at the handshake. Use kickStream() to end a session already running.
     *
     * @param  array<array-key, string>  $streams  the whole list that should be in force,
     *                                             re-indexed so a map still serialises as
     *                                             a JSON array
     */
    public function pushKeyRevocation(array $streams): RevocationResponse
    {
        return RevocationResponse::fromArray(
            $this->transport->post('/api/v1/keys/revocations', ['streams' => array_values($streams)])->body
        );
    }

    /**
     * A text rendering other than `vtt` or `srt`, refused before the request.
     *
     * Shared with FakeStreamsResource, like guardPatch().
     */
    public static function guardTranscriptFormat(string $format): void
    {
        if (array_key_exists($format, self::TRANSCRIPT_TEXT_FORMATS)) {
            return;
        }

        throw InvalidConfigurationException::invalid(
            'format',
            'it must be one of '.implode(', ', array_keys(self::TRANSCRIPT_TEXT_FORMATS))
            .'; the JSON feed is getStreamTranscript()'
        );
    }

    /**
     * A negative transcript `since`, refused before the request: the node answers it
     * with a 422 on `since`, and nothing but the value decides that.
     */
    public static function guardTranscriptSince(?int $since): void
    {
        if ($since === null || $since >= 0) {
            return;
        }

        throw InvalidConfigurationException::invalid('since', 'it must be 0 or greater');
    }

    /**
     * A patch that mentions nothing, refused before the request.
     *
     * Shared with FakeStreamsResource so a test that passes against the fake is not
     * one the node answers with a 422.
     */
    public static function guardPatch(StreamSpec $spec): void
    {
        if (! $spec->mentionsNothing()) {
            return;
        }

        throw InvalidConfigurationException::invalid(
            'spec',
            'it mentions no fields, and a patch that changes nothing is a 422 at the node'
        );
    }
}
