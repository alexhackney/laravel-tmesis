<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;
use AlexHackney\Tmesis\Data\Streams\PendingChange;
use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The decoded JSON body of a faked request.
 *
 * @return array<string, mixed>
 */
function sentBody(Request $request): array
{
    /** @var array<string, mixed> */
    return json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR) ?: [];
}

it('provisions a stream and sends only the fields the spec set', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createStream.json'), 201)]);

    $record = Tmesis::node()->streams()->createStream(
        'show-2041-main',
        StreamSpec::make()
            ->ladder('broadcast-720p')
            ->segmentSeconds(6)
            ->minKeyEpoch(KeyEpoch::fromInt(7))
            ->labels(['show_id' => '2041', 'site' => 'csn']),
    );

    expect($record->id())->toBe('show-2041-main')
        ->and($record->state())->toBe('provisioned')
        ->and($record->minKeyEpoch()?->value)->toBe(7);

    Http::assertSent(function (Request $request): bool {
        $body = sentBody($request);

        return $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1:7000/api/v1/streams'
            && $body === [
                'id' => 'show-2041-main',
                'ladder' => 'broadcast-720p',
                'segment_seconds' => 6,
                'min_key_epoch' => 7,
                'labels' => ['show_id' => '2041', 'site' => 'csn'],
            ];
    });
});

it('reports whether a create provisioned anything, from the status code', function (int $status, bool $created) {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createStream.json'), $status)]);

    [$record, $wasCreated] = Tmesis::node()->streams()->createStreamWithOutcome('show-2041-main');

    expect($wasCreated)->toBe($created)
        ->and($record->id())->toBe('show-2041-main');
})->with([
    '201 provisioned it' => [201, true],
    '200 found it already there' => [200, false],
]);

it('raises a conflict when the same id is posted with a different config', function (): void {
    Http::fake(['*' => Http::response(errorBody('stream_exists', 'a stream with this id already exists'), 409)]);

    expect(fn () => Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->ladder('other')))
        ->toThrow(ConflictException::class);
});

it('patches a stream and reports applied, pending and unchanged apart', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/updateStream.json'))]);

    $patch = Tmesis::node()->streams()->updateStream(
        'show-2041-main',
        StreamSpec::make()->segmentSeconds(4)->labels(['show_id' => '2041'])->webhookUrl('https://hooks.example.com/tmesis'),
    );

    expect($patch->version())->toBe(5)
        ->and($patch->applied())->toBe(['labels' => 'live', 'webhook_url' => 'live'])
        ->and($patch->unchanged())->toBe(['ladder'])
        ->and($patch->auditId())->toBe('01J8ZCE9M3K7QW2XVR5NB8TDH4')
        ->and($patch->changedAnything())->toBeTrue()
        ->and($patch->pending())->toHaveKey('segment_seconds');

    // A pending change is NOT applied. Reading the 200 as "it is set" is how a
    // control plane comes to believe a ladder switched mid-show.
    $pending = $patch->pending()['segment_seconds'];

    expect($pending)->toBeInstanceOf(PendingChange::class)
        ->and($pending->when())->toBe(PendingChange::WHEN_NEXT_SESSION)
        ->and($pending->current())->toBe(6)
        ->and($pending->requested())->toBe(4)
        ->and($pending->reason())->toContain('keyframe cadence');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main'
        && sentBody($request) === [
            'segment_seconds' => 4,
            'labels' => ['show_id' => '2041'],
            'webhook_url' => 'https://hooks.example.com/tmesis',
        ]);
});

it('sends an explicit null for a field the spec cleared', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/updateStream.json'))]);

    Tmesis::node()->streams()->updateStream(
        'show-2041-main',
        StreamSpec::make()->minKeyEpoch(null)->webhookUrl(null),
    );

    // null is MENTIONED and clears the field; an omitted field is not mentioned at
    // all. A builder that could not tell them apart could never remove a floor.
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"min_key_epoch":null')
        && str_contains($request->body(), '"webhook_url":null'));
});

it('refuses a patch that mentions nothing before sending it', function (): void {
    Http::fake();

    expect(fn () => Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()))
        ->toThrow(InvalidConfigurationException::class, 'mentions no fields');

    Http::assertNothingSent();
});

it('surfaces a milestone field on a patch as NotImplementedException', function (): void {
    Http::fake(['*' => Http::response(
        errorBody('not_implemented', 'this build does not implement dvr_window_seconds; it lands at M4', 'M4'),
        501
    )]);

    // The builder does not offer dvr_window_seconds at all, so reaching this needs a
    // hand-written body. The mapping still has to carry the milestone.
    try {
        Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->ladder('broadcast-720p'));
        $this->fail('a 501 did not raise NotImplementedException');
    } catch (NotImplementedException $e) {
        expect($e->milestone())->toBe('M4');
    }
});

it('seals a stream through the query string and reads the effective values back', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/deleteStream.json'), 202)]);

    $seal = Tmesis::node()->streams()->deleteStream('show-2041-main', graceSeconds: 30, retainConfig: true);

    expect($seal->state())->toBe('draining')
        ->and($seal->graceSeconds())->toBe(10)
        ->and($seal->seal())->toBeTrue()
        ->and($seal->started)->toBeTrue();

    // grace_seconds is a QUERY parameter, not a body, and the answer's 10 is the
    // EFFECTIVE value rather than the 30 that was asked for.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'grace_seconds=30')
        && str_contains($request->url(), 'retain_config=true')
        && $request->body() === '');
});

it('reads a 200 on a seal as a retry of one already under way', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/deleteStream.json'), 200)]);

    expect(Tmesis::node()->streams()->deleteStream('show-2041-main')->started)->toBeFalse();
});

it('refuses a grace outside the node range before sending it', function (int $grace) {
    Http::fake();

    expect(fn () => Tmesis::node()->streams()->deleteStream('show-2041-main', graceSeconds: $grace))
        ->toThrow(InvalidConfigurationException::class, 'grace_seconds');

    Http::assertNothingSent();
})->with(['negative' => [-1], 'over the cap' => [301]]);

it('kicks a session and sends no request body', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/kickStream.json'), 202)]);

    $action = Tmesis::node()->streams()->kickStream('show-2041-main');

    expect($action->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($action->endedASession())->toBeTrue()
        ->and($action->state())->toBe('provisioned');

    // The route reads no body. Sending {} would be saying something the contract does
    // not define.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/kick'
        && $request->body() === '');
});

it('treats a kick with nobody publishing as a success', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/takeoverStream.json'), 202)]);

    $action = Tmesis::node()->streams()->takeoverStream('show-2041-main');

    expect($action->session())->toBeNull()
        ->and($action->endedASession())->toBeFalse();
});

it('raises not found for a session action on an unknown stream', function (): void {
    Http::fake(['*' => Http::response(errorBody('not_found', 'no such stream on this node'), 404)]);

    expect(fn () => Tmesis::node()->streams()->kickStream('nobody-here'))->toThrow(NotFoundException::class);
});

it('replaces the revocation list wholesale', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/pushKeyRevocation.json'))]);

    $response = Tmesis::node()->streams()->pushKeyRevocation(['show-2041-main', 'show-2042-main', 'show-2043-main']);

    expect($response->count())->toBe(3);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:7000/api/v1/keys/revocations'
        && sentBody($request) === ['streams' => ['show-2041-main', 'show-2042-main', 'show-2043-main']]);
});

it('clears the revocation list with an empty array, not with an object', function (): void {
    Http::fake(['*' => Http::response(['count' => 0])]);

    expect(Tmesis::node()->streams()->pushKeyRevocation([])->count())->toBe(0);

    // [] and {} are different instructions on the wire, and a map that lost its keys
    // would serialise as the wrong one.
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"streams":[]'));
});

it('adds a relay and reads the redacted record back', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createRelay.json'), 201)]);

    $record = Tmesis::node()->relays()->createRelay(
        'show-2041-main',
        RelayInput::make('srt://origin-atl-01.internal:8890?passphrase=hunter2&streamid=publish/live'),
    );

    expect($record->url())->toBe('srt://origin-atl-01.internal:8890')
        ->and($record->state())->toBe('connected');

    // The URL goes out IN FULL: the node has to dial it.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->body(), 'passphrase=hunter2'));
});

it('refuses a relay id the node could never address', function (): void {
    Http::fake();

    expect(fn () => Tmesis::node()->relays()->createRelay(
        'show-2041-main',
        RelayInput::make('srt://backup.example.com:8890', id: 'stop'),
    ))->toThrow(InvalidConfigurationException::class, 'reserved');

    Http::assertNothingSent();
});

it('parks a relay with a patch that mentions only enabled', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/updateRelay.json'))]);

    $record = Tmesis::node()->relays()->updateRelay('show-2041-main', '01J8ZCC1H6V9DQ2NR5TXB4KM7W', RelayPatch::make(enabled: false));

    expect($record->enabled())->toBeFalse()
        ->and($record->state())->toBe('stopped');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && sentBody($request) === ['enabled' => false]);
});

it('refuses a relay patch that mentions nothing', function (): void {
    Http::fake();

    expect(fn () => Tmesis::node()->relays()->updateRelay('show-2041-main', 'r1', RelayPatch::make()))
        ->toThrow(InvalidConfigurationException::class, 'mentions neither');

    Http::assertNothingSent();
});

it('deletes a relay and gets the same shape both stop paths answer with', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/deleteRelay.json'))]);

    // Not the record that was removed: the openapi document and the shipped handler
    // both answer RelayStopResponse here, and the build plan's table was wrong.
    expect(Tmesis::node()->relays()->deleteRelay('show-2041-main', '01J8ZCC1H6V9DQ2NR5TXB4KM7W')->stopped())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/relays/01J8ZCC1H6V9DQ2NR5TXB4KM7W');
});

it('raises a conflict when the destination comes from the node config file', function (): void {
    Http::fake(['*' => Http::response(errorBody('relay_immutable', 'this destination comes from the node config file'), 409)]);

    expect(fn () => Tmesis::node()->relays()->deleteRelay('show-2041-main', 'static-1'))
        ->toThrow(ConflictException::class);
});

it('stops every destination on a stream', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/stopRelays.json'))]);

    expect(Tmesis::node()->relays()->stopRelays('show-2041-main')->stopped())->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/relays/stop'
        && $request->body() === '');
});

it('raises a validation error with the details the node named', function (): void {
    Http::fake(['*' => Http::response([
        'error' => [
            'code' => 'validation_failed',
            'message' => 'the request has field problems',
            'details' => [['path' => 'segment_seconds', 'message' => 'must be between 1 and 30']],
            'request_id' => 'req_01J8ZC',
            'milestone' => null,
        ],
    ], 422)]);

    try {
        Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->ladder('broadcast-720p'));
        $this->fail('a 422 did not raise ValidationException');
    } catch (ValidationException $e) {
        expect($e->details())->toHaveCount(1)
            ->and($e->details()[0]->path())->toBe('segment_seconds');
    }
});

it('sends the recording and preview blocks nested, exactly as the node reads them', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createStream.json'), 201)]);

    $record = Tmesis::node()->streams()->createStream(
        'show-2041-main',
        StreamSpec::make()
            ->ingestRecordingEnabled(true)
            ->ingestRetentionHours(72)
            ->ingestDestination('/mnt/archive/ingest')
            ->previewMaxMonitors(20),
    );

    // The record carries the EFFECTIVE values, resolved through the node's config.
    expect($record->recording()->ingest()->enabled())->toBeTrue()
        ->and($record->recording()->ingest()->destination())->toBe('/mnt/archive/ingest')
        ->and($record->preview()->maxMonitors())->toBe(50)
        ->and($record->previewMonitor())->toBeNull();

    Http::assertSent(fn (Request $request): bool => sentBody($request) === [
        'id' => 'show-2041-main',
        'recording' => ['ingest' => ['enabled' => true, 'retention_hours' => 72, 'destination' => '/mnt/archive/ingest']],
        'preview' => ['max_monitors' => 20],
    ]);
});

it('raises a validation error naming the dotted path the node refused', function (): void {
    Http::fake(['*' => Http::response([
        'error' => [
            'code' => 'validation_failed',
            'message' => 'the request has field problems',
            'details' => [[
                'path' => 'recording.ingest.destination',
                'message' => 'the directory is not writable by the tmesis user',
            ]],
            'request_id' => 'req_01J8ZC',
            'milestone' => null,
        ],
    ], 422)]);

    try {
        // A shape the builder accepts and the node still refuses (a directory the box
        // cannot write, say; the sentence above is hand-written, the point is the
        // path): the refusal has to arrive with its path. An s3:// URL was the example
        // through v0.3.0; the builder refuses that itself since v0.4.0.
        Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->ingestDestination('/srv/nobody'));
        $this->fail('a 422 did not raise ValidationException');
    } catch (ValidationException $e) {
        expect($e->details()[0]->path())->toBe('recording.ingest.destination');
    }
});

it('surfaces recording.master as the 422 unknown field it is since tmesis v0.8.0', function (): void {
    // It was 501 naming M4 through v0.7.x; the master is a REQUEST now
    // (Artifacts::createMaster()), and recording.master on a stream body, any value,
    // `{}` and null included, is a 422 unknown field. The builder never renders it, so
    // reaching this needs a hand-written body; the mapping carries the path.
    Http::fake(['*' => Http::response(['error' => [
        'code' => 'validation_failed',
        'message' => 'the request has field problems',
        'details' => [['path' => 'recording.master', 'message' => 'unknown field']],
        'request_id' => 'req_01J8ZC',
        'milestone' => null,
    ]], 422)]);

    try {
        Tmesis::node()->streams()->updateStream('show-2041-main', StreamSpec::make()->ladder('broadcast-720p'));
        $this->fail('a 422 did not raise ValidationException');
    } catch (ValidationException $e) {
        expect($e->details()[0]->path())->toBe('recording.master');
    }

    expect(StreamSpec::make()->ingestRecordingEnabled(true)->mentions('recording.master'))->toBeFalse();
});
