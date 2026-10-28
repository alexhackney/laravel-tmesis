<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Markers\MarkerRecord;
use AlexHackney\Tmesis\Data\Markers\MarkerRequest;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('queues a marker on a 202 and says this call created it', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/placeMarker.json'), 202)]);

    $response = Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::out('break-2041-3')->durationSeconds(30));
    $marker = $response->marker();

    expect($response->created)->toBeTrue()
        ->and($marker->id())->toBe('break-2041-3')
        ->and($marker->stream())->toBe('show-2041-main')
        ->and($marker->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($marker->kind())->toBe(MarkerRequest::KIND_OUT)
        ->and($marker->class())->toBe(MarkerRequest::DEFAULT_CLASS)
        ->and($marker->atRaw())->toBeNull()
        ->and($marker->at())->toBeNull()
        ->and($marker->durationSeconds())->toBe(30.0)
        ->and($marker->scte35())->toBeNull()
        ->and($marker->state())->toBe(MarkerRecord::STATE_QUEUED)
        ->and($marker->isQueued())->toBeTrue()
        ->and($marker->isPlaced())->toBeFalse()
        ->and($marker->isExpired())->toBeFalse()
        ->and($marker->startDate())->toBeNull()
        ->and($marker->placedAt())->toBeNull()
        ->and($marker->complete())->toBeFalse()
        ->and($marker->placements())->toBe([])
        ->and($marker->createdAt()->format('s.v'))->toBe('51.370')
        ->and($response->toArray())->toHaveKey('marker');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/markers'
        && json_decode($request->body(), true) === ['kind' => 'out', 'id' => 'break-2041-3', 'duration_seconds' => 30]);
});

it('reads the idempotent 200 as not created', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/placeMarker.json'), 200)]);

    expect(Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::out('break-2041-3'))->created)->toBeFalse();
});

it('maps both 409s to a conflict, told apart by code', function (string $code): void {
    Http::fake(['*' => Http::response(errorBody($code, 'refused'), 409)]);

    try {
        Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::out());
    } catch (ConflictException $e) {
        expect($e->code())->toBe($code);

        return;
    }

    $this->fail('Expected a ConflictException.');
})->with(['stream_not_live', 'marker_exists']);

it('lists every marker, placed with its placements, queued without', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listMarkers.json'))]);

    $list = Tmesis::node()->markers()->listMarkers('show-2041-main');
    [$out, $in] = $list->markers();

    expect($out->isPlaced())->toBeTrue()
        ->and($out->complete())->toBeTrue()
        ->and($out->startDateRaw())->toBe('2026-08-28T19:26:54.006Z')
        ->and($out->startDate()?->format('v'))->toBe('006')
        ->and($out->placedAtRaw())->toBe('2026-08-28T19:26:54.212Z')
        ->and($out->placedAt())->not->toBeNull()
        ->and($out->placements())->toHaveCount(3)
        ->and($out->placements()[2]->rendition())->toBe('audio')
        ->and($out->placements()[2]->segmentIndex())->toBe(143)
        ->and($out->placements()[2]->mediaSequence())->toBe(143)
        ->and($out->placements()[2]->programDateTimeRaw())->toBe('2026-08-28T19:26:47.994Z')
        ->and($out->placements()[2]->programDateTime()->format('v'))->toBe('994')
        ->and($out->placements()[2]->toArray())->toHaveKey('rendition')
        ->and($in->kind())->toBe(MarkerRequest::KIND_IN)
        ->and($in->at()?->format('s'))->toBe('24')
        ->and($in->scte35())->toStartWith('FC3020')
        ->and($in->toArray())->toHaveKey('id')
        ->and($list->find('break-2041-3-in')?->kind())->toBe('in')
        ->and($list->find('break-2041-3', '01J8ZC8XKWQ4M7T0K3PZ2B9RVN')?->kind())->toBe('out')
        ->and($list->find('break-2041-3', 'another-session'))->toBeNull()
        ->and($list->find('nope'))->toBeNull()
        ->and($list->toArray())->toHaveKey('markers');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/streams/show-2041-main/markers');
});

it('maps no such stream to not found', function (): void {
    Http::fake(['*' => Http::response(errorBody('not_found', 'no such stream'), 404)]);

    expect(fn () => Tmesis::node()->markers()->listMarkers('show-2041-main'))->toThrow(NotFoundException::class);
});

it('mints a ULID id so the caller can correlate marker.placed, and keeps a given one', function (): void {
    $minted = MarkerRequest::out();

    expect($minted->id())->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($minted->kind())->toBe('out')
        ->and($minted->toArray())->toBe(['kind' => 'out', 'id' => $minted->id()])
        ->and(MarkerRequest::in('break-in')->toArray())->toBe(['kind' => 'in', 'id' => 'break-in'])
        ->and(MarkerRequest::make('out', 'x')->id())->toBe('x');
});

it('renders every optional field only when set, with null at as now', function (): void {
    $request = MarkerRequest::in('b-in')
        ->at(new DateTimeImmutable('2026-08-28T19:27:24.006Z'))
        ->withClass('com.example.ads')
        ->durationSeconds(29.97)
        ->scte35('0xfc30');

    expect($request->toArray())->toBe([
        'kind' => 'in',
        'id' => 'b-in',
        'at' => '2026-08-28T19:27:24.006Z',
        'class' => 'com.example.ads',
        'duration_seconds' => 29.97,
        'scte35' => '0xfc30',
    ])
        ->and(MarkerRequest::out('b')->at(null)->toArray())->toBe(['kind' => 'out', 'id' => 'b', 'at' => null])
        ->and(MarkerRequest::out('b')->at('2026-08-28T19:27:24+00:00')->toArray()['at'])->toBe('2026-08-28T19:27:24+00:00');
});

it('refuses what the node refuses on its face, with the node\'s sentences', function (Closure $build, string $field, string $message): void {
    expect($build)->toThrow(InvalidConfigurationException::class, "[{$field}] is invalid: {$message}");
})->with([
    'kind' => [fn () => MarkerRequest::make('splice'), 'kind', 'must be out or in'],
    'id charset' => [fn () => MarkerRequest::out('break 3'), 'id', 'must match ^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$'],
    'id leading dash' => [fn () => MarkerRequest::out('-break'), 'id', 'must match'],
    'class charset' => [fn () => MarkerRequest::out()->withClass('com/example'), 'class', 'must match ^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'],
    'at' => [fn () => MarkerRequest::out()->at('tomorrow'), 'at', 'must be an RFC3339 date-time'],
    'duration negative' => [fn () => MarkerRequest::out()->durationSeconds(-1), 'duration_seconds', 'must be 0 to 86400'],
    'duration over a day' => [fn () => MarkerRequest::out()->durationSeconds(86400.5), 'duration_seconds', 'must be 0 to 86400'],
    'scte35 not hex' => [fn () => MarkerRequest::out()->scte35('0xZZ'), 'scte35', 'must be hex (an even number of hex digits, 0x optional)'],
    'scte35 odd' => [fn () => MarkerRequest::out()->scte35('FC3'), 'scte35', 'must be hex'],
    'scte35 empty' => [fn () => MarkerRequest::out()->scte35('0x'), 'scte35', 'must be hex'],
    'scte35 too long' => [fn () => MarkerRequest::out()->scte35(str_repeat('AB', 4100)), 'scte35', 'must be at most 4099 bytes, the largest splice_info_section'],
]);
