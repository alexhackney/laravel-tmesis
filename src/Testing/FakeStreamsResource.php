<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Testing;

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
use AlexHackney\Tmesis\Resources\Streams;
use DateTimeInterface;

/**
 * Stream reads and writes, answered from FakeTmesis's canned data.
 *
 * A write CHANGES that data, so a consumer's test can create a stream and then read
 * it back, kick it and see the session gone, seal it and see the state. A fake whose
 * writes went nowhere would pass tests that the node would fail.
 *
 * A recorded call carries the spec with every relay URL REDACTED, so the call log
 * cannot hold a credential.
 */
class FakeStreamsResource extends Streams
{
    public function __construct(private readonly FakeTmesis $fake, private readonly string $node)
    {
        // Deliberately does not call parent::__construct(): there is no transport.
    }

    public function listStreams(?string $state = null): StreamList
    {
        $this->fake->record('listStreams', $this->node, ['state' => $state]);

        return $this->fake->streamList($state);
    }

    public function getStream(string $id, bool $includePreview = false, bool $includeCaptions = false): StreamRecord
    {
        $this->fake->record('getStream', $this->node, [
            'id' => $id,
            'include' => self::includeTokens($includePreview, $includeCaptions),
        ]);

        return $this->fake->stream($id, $includePreview, $includeCaptions);
    }

    public function getStreamTranscript(string $id, ?string $lang = null, ?int $since = null): TranscriptResponse
    {
        // The same guard the real resource runs, before the record.
        self::guardTranscriptSince($since);

        $this->fake->record('getStreamTranscript', $this->node, [
            'id' => $id,
            'format' => null,
            'lang' => $lang,
            'since' => $since,
        ]);

        return $this->fake->streamTranscript($id, $lang, $since);
    }

    public function getStreamTranscriptText(string $id, string $format, ?string $lang = null, ?int $since = null): string
    {
        self::guardTranscriptFormat($format);
        self::guardTranscriptSince($since);

        $this->fake->record('getStreamTranscript', $this->node, [
            'id' => $id,
            'format' => $format,
            'lang' => $lang,
            'since' => $since,
        ]);

        return $this->fake->streamTranscriptText($id, $format, $lang);
    }

    public function getStreamStats(string $id): StreamStats
    {
        $this->fake->record('getStreamStats', $this->node, ['id' => $id]);

        return StreamStats::fromArray($this->fake->streamStats($id));
    }

    public function getStreamEvents(
        string $id,
        ?DateTimeInterface $since = null,
        ?DateTimeInterface $until = null,
        ?int $limit = null,
        ?string $order = null,
    ): StreamEventList {
        // The same guard the real resource runs, before the record: an order the
        // node does not have is a 422 there.
        if ($order !== null && ! in_array($order, self::ORDERS, true)) {
            throw InvalidConfigurationException::invalid('order', 'it must be one of '.implode(', ', self::ORDERS));
        }

        $this->fake->record('getStreamEvents', $this->node, [
            'id' => $id,
            'since' => $since?->format(DATE_ATOM),
            'until' => $until?->format(DATE_ATOM),
            'limit' => $limit,
            'order' => $order,
        ]);

        return StreamEventList::fromArray($this->fake->streamEvents($id, $order));
    }

    public function createStream(string $id, ?StreamSpec $spec = null): StreamRecord
    {
        $spec ??= StreamSpec::make();

        $this->fake->record('createStream', $this->node, ['id' => $id, 'spec' => $spec->toRedactedArray()]);

        return $this->fake->createdStream($id, $spec);
    }

    /**
     * @return array{0: StreamRecord, 1: bool}
     */
    public function createStreamWithOutcome(string $id, ?StreamSpec $spec = null): array
    {
        $created = ! $this->fake->knowsStream($id);

        return [$this->createStream($id, $spec), $created];
    }

    public function updateStream(string $id, StreamSpec $spec): PatchResponse
    {
        // Before the record, and the same guard the real resource runs: a patch that
        // changes nothing is a 422 at the node, and a fake that accepted it would
        // green a test the node fails.
        self::guardPatch($spec);

        $this->fake->record('updateStream', $this->node, ['id' => $id, 'spec' => $spec->toRedactedArray()]);

        return $this->fake->patchedStream($id, $spec);
    }

    public function deleteStream(string $id, ?int $graceSeconds = null, ?bool $retainConfig = null): SealResponse
    {
        $this->fake->record('deleteStream', $this->node, [
            'id' => $id,
            'grace_seconds' => $graceSeconds,
            'retain_config' => $retainConfig,
        ]);

        return $this->fake->sealedStream($id, $graceSeconds);
    }

    public function kickStream(string $id): SessionActionResponse
    {
        $this->fake->record('kickStream', $this->node, ['id' => $id]);

        return $this->fake->endedSession($id);
    }

    public function takeoverStream(string $id): SessionActionResponse
    {
        $this->fake->record('takeoverStream', $this->node, ['id' => $id]);

        return $this->fake->endedSession($id);
    }

    /**
     * @param  array<array-key, string>  $streams
     */
    public function pushKeyRevocation(array $streams): RevocationResponse
    {
        $this->fake->record('pushKeyRevocation', $this->node, ['streams' => array_values($streams)]);

        return $this->fake->replacedRevocations($streams);
    }
}
