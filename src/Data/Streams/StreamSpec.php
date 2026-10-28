<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Streams;

use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Resources\Relays;
use AlexHackney\Tmesis\Support\Keyterms;
use AlexHackney\Tmesis\Support\RelayUrl;

/**
 * The provisioning body for POST /api/v1/streams and PATCH /api/v1/streams/:id.
 *
 * It serialises ONLY what was set. That is the whole design: `docs/API.md` 9.6 makes
 * a PATCH a partial document where an omitted field is not mentioned and a field set
 * to null is mentioned and cleared, and a builder that defaulted its fields would
 * silently rewrite every value on the record on every patch.
 *
 * So the two nullable fields distinguish three states:
 *
 *     StreamSpec::make()                     // min_key_epoch not mentioned
 *     StreamSpec::make()->minKeyEpoch(7)     // set to 7
 *     StreamSpec::make()->minKeyEpoch(null)  // mentioned, and cleared
 *
 * **Only the fields this build implements are here.** The six flat fields, the four
 * `recording.ingest.*` fields and the two `preview.*` fields (both blocks since rc7,
 * tmesis final plan 11.34 and 11.36; `destination_ref` since tmesis v0.6.0), the five
 * `captions.*` fields (since tmesis v0.3.0, final plan 11.44; `keyterms` since
 * v0.8.0), and `fallback.end_seconds` (since tmesis v0.6.0). `dvr_window_seconds`
 * answers 501 with a milestone on both POST and PATCH, `recording.master` does not
 * exist since tmesis v0.8.0 and is `422 unknown field` on both verbs, any value, null
 * and `{}` included (it was 501 naming M4; the master is a REQUEST now,
 * `Artifacts::createMaster()`), `fallback.hold_seconds` (M4),
 * `fallback.chain` and `fallback.slate_asset` (M5) are RESERVED and answer 501 naming
 * the path on both verbs whatever their value, null included,
 * `recording.ingest.container` does not exist and is
 * `422 unknown field` (it was documented at rc6 and never honoured: the container
 * follows the publish protocol), and `encode.*`, `encryption` and `delivery` are the
 * same or are refused as unknown fields. A builder that offered them would be
 * offering an error, so the way to send one is deliberately to write the array
 * yourself.
 *
 * The nested fields are set through flat methods that read like the dotted paths the
 * node reports back in `applied` and `pending` (decision D2), and they serialise as
 * one nested object: `->ingestRecordingEnabled(true)->ingestRetentionHours(48)` is
 * `{"recording": {"ingest": {"enabled": true, "retention_hours": 48}}}` on the wire.
 * The node's own refusal messages are mirrored word for word, so a consumer sees the
 * same sentence whether the builder or the node refused it.
 *
 * Every instance is immutable: each setter returns a new spec.
 */
final readonly class StreamSpec
{
    /** Node limits, from `internal/api/streamvalidate.go`. */
    public const MAX_LABELS = 16;

    public const MAX_LABEL_KEY_BYTES = 64;

    public const MAX_LABEL_VALUE_BYTES = 256;

    /**
     * @param  array<string, mixed>  $fields  only the keys that were set
     */
    private function __construct(private array $fields = []) {}

    public static function make(): self
    {
        return new self;
    }

    /**
     * The named ladder to encode with. Changing it is a `next_session` change: the
     * rendition set and every init segment come from it.
     *
     * A ladder change under a stored `segment_seconds` override can be the grid
     * `422` too (see segmentSeconds()): when the new ladder's keyframe cadence cannot
     * divide the override, `POST` and `PATCH` answer `422 validation_failed` and the
     * one `details[]` entry's `path` is `ladder`, because that is the field the body
     * named (API.md 9.4 and 9.6, since tmesis `v0.2.0`).
     */
    public function ladder(string $ladder): self
    {
        return $this->with('ladder', $ladder);
    }

    /**
     * Target segment duration, 1 to 30 here. Also `next_session`: EXT-X-TARGETDURATION
     * can be grown but never shrunk mid-manifest.
     *
     * The grid rule is the node's, never mirrored here. At the node the EFFECTIVE
     * value (this override, or the ladder's own) must be a multiple of every video
     * rung's `keyframe_seconds` in the EFFECTIVE ladder (this stream's, or the node
     * default), or `POST` and `PATCH` answer `422 validation_failed` with one
     * `details[]` entry on `segment_seconds` and a message naming the rung (API.md
     * 9.4 and 9.6, since tmesis `v0.2.0`). The check runs on the MERGED row after
     * sets and clears, so a cleared override is checked as the ladder's value it
     * falls back to; only the node knows its ladders, so the range is all this
     * builder checks.
     */
    public function segmentSeconds(int $seconds): self
    {
        if ($seconds < 1 || $seconds > 30) {
            throw InvalidConfigurationException::invalid('segment_seconds', 'it must be between 1 and 30');
        }

        return $this->with('segment_seconds', $seconds);
    }

    /**
     * The KEY epoch floor: the node refuses a publish key below it. Raising it is how
     * the control plane invalidates every key already minted for this stream.
     *
     * Null CLEARS the floor. It applies to the next handshake and never disconnects
     * an attached publisher, so raising it mid-show is safe and does nothing until
     * the publisher reconnects.
     */
    public function minKeyEpoch(KeyEpoch|int|null $epoch): self
    {
        if ($epoch instanceof KeyEpoch) {
            $epoch = $epoch->value;
        }

        if (is_int($epoch) && $epoch < 0) {
            throw InvalidConfigurationException::invalid('min_key_epoch', 'it must be 0 or greater');
        }

        return $this->with('min_key_epoch', $epoch);
    }

    /**
     * Per-stream webhook override. Null means the node default from `webhooks.url`.
     *
     * An empty string is refused here rather than sent: the node reads it as a third,
     * undocumented instruction and answers 422.
     */
    public function webhookUrl(?string $url): self
    {
        if ($url === '') {
            throw InvalidConfigurationException::invalid(
                'webhook_url',
                'it must be a URL or null; null means the node default from webhooks.url'
            );
        }

        return $this->with('webhook_url', $url);
    }

    /**
     * Bookkeeping the node stores, echoes, and never interprets.
     *
     * Labels REPLACE wholesale: the node treats `labels` as one leaf, never merged
     * key by key, so this is the whole set and not an addition to it.
     *
     * `labels([])` CLEARS every label: it goes out as `"labels": {}`, which the node
     * stores as the empty set (`internal/node/streamstore.go`, PatchStream). That is
     * the same end state as the `null` the document describes ("Null on a PATCH unsets
     * to {}"), reached by replacement rather than unset. Not calling labels() at all
     * leaves them alone. The empty map is rendered as an object only in
     * toRequestBody(); toArray() keeps the PHP array.
     *
     * In a hook patch (`HookDecision::allow(patch: ...)`) the node applies labels to
     * an UNPROVISIONED stream through CreateStream (tmesis WP5, `gateseam.go`
     * `applyHookPatch`), so an ad hoc publish can be labelled on the handshake. A
     * patch the node cannot apply never fails the publish.
     *
     * @param  array<string, string>  $labels
     */
    public function labels(array $labels): self
    {
        if (count($labels) > self::MAX_LABELS) {
            throw InvalidConfigurationException::invalid('labels', 'at most '.self::MAX_LABELS.' keys');
        }

        foreach ($labels as $key => $value) {
            if (strlen((string) $key) > self::MAX_LABEL_KEY_BYTES) {
                throw InvalidConfigurationException::invalid("labels.{$key}", 'the key is over 64 bytes');
            }

            if (strlen($value) > self::MAX_LABEL_VALUE_BYTES) {
                throw InvalidConfigurationException::invalid("labels.{$key}", 'the value is over 256 bytes');
            }
        }

        return $this->with('labels', $labels);
    }

    /**
     * The destinations this stream forwards to.
     *
     * WRITE ONLY, and a full replacement: an empty array removes the destinations
     * this record provisioned. The read surface is `listRelays()`, where the URLs
     * come back redacted.
     *
     * In a hook patch (`HookDecision::allow(patch: ...)`) the node provisions these
     * on an UNPROVISIONED stream through CreateStream (tmesis WP5, `gateseam.go`
     * `applyHookPatch`): the edge control channel. A patch the node cannot apply
     * never fails the publish; `relay.started` for the id is how to know it landed.
     * The URL is a credential: log `HookDecision::toRedactedArray()`.
     *
     * @param  array<array-key, RelayInput>  $relays  re-indexed, so a map of them still
     *                                                serialises as a JSON array
     */
    public function relays(array $relays): self
    {
        return $this->with('relays', array_map(
            static function (RelayInput $relay): array {
                // The reserved id is unaddressable however it was provisioned: a
                // destination that arrives through this array is a `stream` source
                // relay, and .../relays/stop shadows .../relays/{rid} for it just the
                // same. Refused on every path that can carry one.
                Relays::guardRelayId($relay->id());

                return $relay->toArray();
            },
            array_values($relays),
        ));
    }

    /**
     * Whether the node byte-copies what the publisher sends. A `live` change: on opens
     * a new part now (`recording.started` with `started_by: api`), off finalizes the
     * open one now (`recording.completed` with `reason: api_stop`), on a live session;
     * on a held session at the rejoin (off opens nothing then, on opens the part then
     * as `resume`), never during the hold.
     */
    public function ingestRecordingEnabled(bool $enabled): self
    {
        return $this->withPath('recording.ingest.enabled', $enabled);
    }

    /**
     * How long the node keeps its local copy AFTER the artifact is registered. A
     * `live` change: a sweep policy read on every pass, and it never deletes an
     * unregistered artifact.
     */
    public function ingestRetentionHours(int $hours): self
    {
        if ($hours < 1) {
            throw InvalidConfigurationException::invalid(
                'recording.ingest.retention_hours',
                'it must be 1 or greater: it bounds local disk AFTER the artifact is registered, '
                .'and zero would sweep the file the moment it lands'
            );
        }

        return $this->withPath('recording.ingest.retention_hours', $hours);
    }

    /**
     * Where the finalized artifact is delivered: an ABSOLUTE PATH on the node, or
     * null. A `live` change read at finalize, so it applies to the whole file
     * whenever it lands before the close.
     *
     * Null is sent as JSON null and CLEARS to the node default (the `min_key_epoch`
     * rule: mentioned, and cleared). An empty string and a relative path are refused
     * here rather than sent, with the node's own sentence
     * (`internal/api/streamvalidate.go` `recordingDestinationProblem`).
     *
     * **`s3://` is gone (tmesis `v0.4.0`, Destination WP1, TMESIS-DELIVERY-3).** The
     * node refuses any URL here with `422 validation_failed`, and this refuses it
     * first with the same sentence: bucket delivery is a named storage destination
     * on the node and, since tmesis `v0.5.0`, an artifact transfer plan
     * (`Tmesis::node()->artifacts()->replaceTransferPlan()`). A value stored before `v0.4.0` that still
     * reads `s3://` on a record resolves to a `destination_ref` on the node and its
     * finalized recordings are HELD on the box: no upload, no `recording.registered`,
     * no `recording.failed`, never swept, and `recording.started` /
     * `recording.completed` carry `destination: null` while held. A PATCH to a
     * directory destination registers them on the next pass. So a consumer that
     * provisioned `s3://` destinations migrates them to a path before upgrading the
     * node, or accepts held recordings until it does.
     */
    public function ingestDestination(?string $destination): self
    {
        if ($destination !== null) {
            self::guardDestination($destination);
            $this->guardDestinationPair('recording.ingest.destination_ref');
        }

        return $this->withPath('recording.ingest.destination', $destination);
    }

    /**
     * The NAME of a `storage.destinations` entry on the node the finalized recording
     * is held for, under a told key, as the config key of the same name does. A
     * `live` change read at finalize. Since tmesis v0.6.0.
     *
     * Null is sent as JSON null and CLEARS to the node default. An empty string or one
     * with whitespace is refused here; whether the node configures the name is the
     * node's `422 validation_failed` on `recording.ingest.destination_ref` (D3).
     *
     * It is never set beside ingestDestination(): both non-empty in one spec is
     * refused here with the node's sentence, and a STORED directory beside a sent
     * name is the node's `422` on the merged row. To switch, send the other as null
     * in the same body:
     *
     *     StreamSpec::make()->ingestDestination(null)->ingestDestinationRef('csn-vod')
     */
    public function ingestDestinationRef(?string $ref): self
    {
        $field = 'recording.ingest.destination_ref';

        if ($ref !== null) {
            if ($ref === '' || preg_match('/\s/', $ref) === 1) {
                throw InvalidConfigurationException::invalid(
                    $field,
                    'must name a destination in storage.destinations, or null; null means the node default'
                );
            }

            $this->guardDestinationPair('recording.ingest.destination');
        }

        return $this->withPath($field, $ref);
    }

    /**
     * How long a HELD session of this stream waits for its publisher to rejoin
     * before the node seals it, 1 or more; null is sent as JSON null and means the
     * node's `fallback.end_seconds` (3600 by default). Since tmesis v0.6.0.
     *
     * A `live` change: a PATCH during a hold re-arms the deadline, so raising it
     * extends that hold and lowering it seals at the new deadline (at once if that
     * has passed). `StreamRecord::fallback()->endSeconds()` reads the effective value.
     *
     * `fallback.hold_seconds` (M4), `fallback.chain` and `fallback.slate_asset` (M5)
     * are RESERVED: any value, null included, is `501` naming the path on POST and
     * PATCH, so they are not offered here.
     */
    public function fallbackEndSeconds(?int $seconds): self
    {
        if ($seconds !== null && $seconds < 1) {
            throw InvalidConfigurationException::invalid(
                'fallback.end_seconds',
                "must be at least 1: it is how long a held session waits for its publisher to rejoin before the node seals it; null means the node's fallback.end_seconds"
            );
        }

        return $this->withPath('fallback.end_seconds', $seconds);
    }

    /**
     * Whether the session's encoder runs a preview tap. A `next_session` change: the
     * tap is an output of the session's ffmpeg, so turning it on or off changes the
     * argv and needs a new encoder session.
     */
    public function previewEnabled(bool $enabled): self
    {
        return $this->withPath('preview.enabled', $enabled);
    }

    /**
     * How many WHEP monitors may watch at once. A `live` change read at every join:
     * a lowered cap refuses the next monitor and never kicks one already watching.
     */
    public function previewMaxMonitors(int $max): self
    {
        if ($max < 1) {
            throw InvalidConfigurationException::invalid(
                'preview.max_monitors',
                'must be 1 or greater; turn the preview off with preview.enabled instead'
            );
        }

        return $this->withPath('preview.max_monitors', $max);
    }

    /**
     * Whether the node opens a provider session for this stream and writes the
     * caption rendition. A `next_segment` change: the writer starts or stops at a
     * segment boundary, so a PATCH on a publishing stream answers `200` with the field
     * in `pending`. It is the cost control: a captioning stream streams audio to the
     * provider for the whole session, silence included.
     *
     * The node refuses it with `422` on a node whose `transcription.provider` is
     * `none`, on a passthrough node, and on a ladder with no `audio_only` rung. Those
     * are node facts and are never mirrored here.
     */
    public function captionsEnabled(bool $enabled): self
    {
        return $this->withPath('captions.enabled', $enabled);
    }

    /**
     * Whether both masters carry the `EXT-X-MEDIA TYPE=SUBTITLES` group. A
     * `next_segment` change. The writer runs whenever captions are enabled, so the DVR
     * and the transcript are complete for a show that chose not to advertise live.
     * The node's default is true.
     */
    public function captionsAdvertise(bool $advertise): self
    {
        return $this->withPath('captions.advertise', $advertise);
    }

    /**
     * The STT provider. Null is sent as JSON null and means the node's configured
     * `transcription.provider`. This names the primary; failover applies either way.
     * A non-null value must EQUAL the node's primary or
     * the node answers `422` on `captions.provider`; that is a node fact and is not
     * mirrored here. `deepgram` or `self_hosted` since tmesis v0.9.1, K1/K2. A
     * `next_segment` change: it ends the current STT session and starts a new one at
     * the boundary.
     */
    public function captionsProvider(?string $provider): self
    {
        return $this->withPath('captions.provider', $provider);
    }

    /**
     * The RFC 5646 tags to caption in. v1 accepts exactly ONE; two or more are
     * refused here with the node's own sentence (`internal/api/streamvalidate.go`,
     * `validateCaptions`). An empty list is allowed and means the node's
     * `transcription.language`. A `next_segment` change.
     *
     * A list REPLACES wholesale, like labels.
     *
     * @param  array<array-key, string>  $languages  re-indexed, so it serialises as a
     *                                               JSON array
     */
    public function captionsLanguages(array $languages): self
    {
        if (count($languages) > 1) {
            throw InvalidConfigurationException::invalid(
                'captions.languages',
                'v1 captions one language per stream: send exactly one tag (multi-language is later; the layout is ready for it)'
            );
        }

        return $this->withPath('captions.languages', array_values($languages));
    }

    /**
     * The show's vocabulary the STT provider is told to favour: fighter names, the
     * promotion, the venue, the city (tmesis `v0.8.0`). A `next_segment` change that
     * reaches a live provider session without a gap where the provider supports it,
     * so it is replaceable while live.
     *
     * A list REPLACES the stored one wholesale; `[]` or null CLEARS it; not calling
     * this keeps it. The node trims each term and removes duplicates
     * case-insensitively (keeping the first spelling and the order), and
     * `StreamRecord::captions()->keyterms()` reads back what it stored.
     *
     * Refused here with the node's own sentence: more than 100 terms, a term empty or
     * over 50 characters after trimming, a control character (`Support\Keyterms`).
     * The node's `422` over its PROVIDER's token cap (Deepgram, about 500 tokens) is a
     * node fact and is never mirrored. Once a configured keyterms hook has answered
     * in a live session, its list replaces this one for that session (decision D14).
     *
     * @param  array<array-key, string>|null  $terms  re-indexed, so it serialises as a
     *                                                JSON array
     */
    public function captionsKeyterms(?array $terms): self
    {
        if ($terms !== null && ($problem = Keyterms::problem($terms)) !== null) {
            throw InvalidConfigurationException::invalid('captions.keyterms', $problem.' '.Keyterms::RULE);
        }

        return $this->withPath('captions.keyterms', $terms === null ? null : array_values($terms));
    }

    /**
     * Whether a field was mentioned at all, including mentioned as null.
     *
     * Takes a top-level name (`labels`) or a dotted path into a nested block
     * (`recording.ingest.destination`).
     */
    public function mentions(string $field): bool
    {
        $cursor = $this->fields;

        foreach (explode('.', $field) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    public function mentionsNothing(): bool
    {
        return $this->fields === [];
    }

    /**
     * The fields that were set, and nothing else, as PHP data. toRequestBody() is
     * what goes on the wire (the same fields, with an empty map as an object).
     *
     * A relay URL travels here in full, because the node has to dial it. This and
     * toRequestBody() are the only places in the package that is true of, and it is
     * why nothing logs a body.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    /**
     * The fields as they go on the wire: toArray(), with every empty STRING MAP
     * rendered as a JSON object.
     *
     * PHP encodes `[]` as a JSON array, and the document types `labels` as
     * `oneOf [object, null]`: `"labels": []` is a 422 at the node, where `{}` clears
     * the set. `labels` is the only open map the builder can render empty (the
     * schemas-requests leg derives the list from the request components and holds
     * it to that). toArray() keeps the PHP array for everything that reads the spec
     * as data, FakeTmesis included.
     *
     * @return array<string, mixed>
     */
    public function toRequestBody(): array
    {
        $fields = $this->fields;

        if (array_key_exists('labels', $fields) && $fields['labels'] === []) {
            $fields['labels'] = new \stdClass;
        }

        return $fields;
    }

    /**
     * The body with every relay URL redacted: what a log line or an exception may
     * carry.
     *
     * @return array<string, mixed>
     */
    public function toRedactedArray(): array
    {
        $fields = $this->fields;

        if (isset($fields['relays']) && is_array($fields['relays'])) {
            $fields['relays'] = array_map(
                static function (mixed $relay): mixed {
                    if (is_array($relay) && isset($relay['url']) && is_string($relay['url'])) {
                        $relay['url'] = RelayUrl::redact($relay['url']);
                    }

                    return $relay;
                },
                $fields['relays'],
            );
        }

        return $fields;
    }

    private function with(string $field, mixed $value): self
    {
        $fields = $this->fields;
        $fields[$field] = $value;

        return new self($fields);
    }

    /**
     * Set one leaf of a nested block without disturbing its siblings, so two calls
     * into `recording.ingest` serialise as one object with two keys.
     */
    private function withPath(string $dotted, mixed $value): self
    {
        return new self(self::setPath($this->fields, explode('.', $dotted), $value));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  non-empty-list<string>  $segments
     * @return array<string, mixed>
     */
    private static function setPath(array $fields, array $segments, mixed $value): array
    {
        $head = array_shift($segments);

        if ($segments === []) {
            $fields[$head] = $value;

            return $fields;
        }

        $child = $fields[$head] ?? null;

        /** @var array<string, mixed> $child */
        $child = is_array($child) ? $child : [];

        $fields[$head] = self::setPath($child, $segments, $value);

        return $fields;
    }

    /**
     * The node's two destination refusals since tmesis `v0.4.0`, mirrored from
     * `internal/api/streamvalidate.go` (`recordingDestinationProblem`): empty, and
     * anything that is not an absolute filesystem path. That second sentence is the
     * one an `s3://` URL gets now (four refusals through `v0.3.0`, when `s3://` was
     * a form); since tmesis v0.6.0 it names the other KEY (TMESIS-REC-1: a
     * destination name sent here belongs in `recording.ingest.destination_ref`).
     */
    private static function guardDestination(string $destination): void
    {
        $field = 'recording.ingest.destination';

        if ($destination === '') {
            throw InvalidConfigurationException::invalid(
                $field,
                'must be an absolute path, or null; null means the node default'
            );
        }

        if (! str_starts_with($destination, '/')) {
            throw InvalidConfigurationException::invalid(
                $field,
                'must be an absolute filesystem path; a destination name goes in `recording.ingest.destination_ref`, not here'
            );
        }
    }

    /**
     * The pair rule (tmesis v0.6.0, `internal/api/streamvalidate.go`
     * `DestinationPairRefusal`): a directory and a named destination are never both
     * set. Reported on `destination_ref` whichever was set second, as the node
     * reports it. Only the BODY is checked; a stored value beside a sent one is the
     * node's merged-row `422`.
     */
    private function guardDestinationPair(string $other): void
    {
        $segments = explode('.', $other);
        $cursor = $this->fields;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return;
            }

            $cursor = $cursor[$segment];
        }

        if (! is_string($cursor) || $cursor === '') {
            return;
        }

        throw InvalidConfigurationException::invalid(
            'recording.ingest.destination_ref',
            'cannot be set together with recording.ingest.destination: send the other as null to switch between a directory and a named destination'
        );
    }
}
