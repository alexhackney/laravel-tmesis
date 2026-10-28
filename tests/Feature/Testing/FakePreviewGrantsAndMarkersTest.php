<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Markers\MarkerRecord;
use AlexHackney\Tmesis\Data\Markers\MarkerRequest;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantInput;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch;
use AlexHackney\Tmesis\Events\MarkerPlaced;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @return array<string, mixed>
 */
function liveStreamRecord(string $id = 'show-2041-main', string $state = 'live'): array
{
    return [
        'id' => $id,
        'state' => $state,
        'session' => '01J8ZC8XKWQ4M7T0K3PZ2B9RVN',
        'epoch' => 3,
        'labels' => ['show_id' => '2041'],
    ];
}

it('keeps every grant independent, canonicalises addresses, and lists only live ones', function (): void {
    $fake = Tmesis::fake();
    $grants = Tmesis::node()->previewGrants();

    $first = $grants->createPreviewGrant(PreviewGrantInput::make(['::ffff:203.0.113.7', '2001:0db8:0000:0000:0000:0000:0000:0007'], 600)->forStreams(['show-2041-main']));
    $second = $grants->createPreviewGrant(PreviewGrantInput::make(['203.0.113.7'], 600)->withLabels(['panel' => 'p2']));

    expect($first->id())->not->toBe($second->id())
        ->and($first->ips())->toBe(['203.0.113.7', '2001:db8::7'])
        ->and($first->streams())->toBe(['show-2041-main'])
        ->and($second->streams())->toBeNull()
        ->and($grants->listPreviewGrants()->grants())->toHaveCount(2)
        ->and($fake->grants())->toHaveCount(2);

    $fake->assertGranted('203.0.113.7', 'show-2041-main')
        ->assertGranted('::ffff:203.0.113.7', 'any-other-stream')
        ->assertGranted('2001:db8::7', 'show-2041-main')
        ->assertCalledCount('createPreviewGrant', 2);

    // Revoking one never ends the other.
    $revoked = $grants->revokePreviewGrant($second->id());

    expect($revoked->id())->toBe($second->id())
        ->and($grants->listPreviewGrants()->grants())->toHaveCount(1);

    $fake->assertRevoked($second->id())
        ->assertGranted('203.0.113.7', 'show-2041-main');

    expect(fn () => $fake->assertGranted('203.0.113.7', 'any-other-stream'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertRevoked($first->id()))->toThrow(AssertionFailedError::class);
});

it('moves expires_at on an update, and answers 404 for a revoked or unknown grant', function (): void {
    $fake = Tmesis::fake();
    $grants = Tmesis::node()->previewGrants();

    $grant = $grants->createPreviewGrant(PreviewGrantInput::make(['203.0.113.7'], 60));
    $extended = $grants->updatePreviewGrant($grant->id(), PreviewGrantPatch::make(86400));

    expect($extended->expiresAt() > $grant->expiresAt())->toBeTrue()
        ->and($extended->createdAtRaw())->toBe($grant->createdAtRaw());

    $grants->revokePreviewGrant($grant->id());

    expect(fn () => $grants->updatePreviewGrant($grant->id(), PreviewGrantPatch::make(60)))->toThrow(NotFoundException::class)
        ->and(fn () => $grants->revokePreviewGrant($grant->id()))->toThrow(NotFoundException::class)
        ->and(fn () => $grants->revokePreviewGrant('01J8ZUNKNOWN000000000000000'))->toThrow(NotFoundException::class);

    $fake->assertCalled('updatePreviewGrant', fn (array $args): bool => $args['ttlSeconds'] === 86400);
});

it('queues a marker, answers the same request idempotently, and refuses a different one', function (): void {
    $fake = Tmesis::fake()->withStream(liveStreamRecord());
    $markers = Tmesis::node()->markers();

    $request = MarkerRequest::out('break-2041-3')->durationSeconds(30)->scte35('0xfc30');
    $first = $markers->placeMarker('show-2041-main', $request);
    $again = $markers->placeMarker('show-2041-main', $request);

    expect($first->created)->toBeTrue()
        ->and($first->marker()->isQueued())->toBeTrue()
        ->and($first->marker()->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($first->marker()->class())->toBe(MarkerRequest::DEFAULT_CLASS)
        ->and($first->marker()->scte35())->toBe('FC30')
        ->and($again->created)->toBeFalse()
        ->and($again->marker()->id())->toBe('break-2041-3');

    try {
        $markers->placeMarker('show-2041-main', MarkerRequest::out('break-2041-3')->durationSeconds(60));
        $this->fail('Expected marker_exists.');
    } catch (ConflictException $e) {
        expect($e->code())->toBe('marker_exists')->and($e->status())->toBe(409);
    }

    expect($markers->listMarkers('show-2041-main')->markers())->toHaveCount(1);

    $fake->assertMarkerPlaced('show-2041-main', 'break-2041-3')
        ->assertMarkerPlaced('show-2041-main', 'break-2041-3', fn (MarkerRecord $m): bool => $m->kind() === 'out');

    expect(fn () => $fake->assertMarkerPlaced('show-2041-main', 'nope'))->toThrow(AssertionFailedError::class);
});

it('refuses a marker on a stream that is not live, and 404s an unknown one', function (): void {
    Tmesis::fake()->withStream(liveStreamRecord(state: 'provisioned'));

    try {
        Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::out());
        $this->fail('Expected stream_not_live.');
    } catch (ConflictException $e) {
        expect($e->code())->toBe('stream_not_live');
    }

    expect(fn () => Tmesis::node()->markers()->placeMarker('show-2042-main', MarkerRequest::out()))->toThrow(NotFoundException::class)
        ->and(fn () => Tmesis::node()->markers()->listMarkers('show-2042-main'))->toThrow(NotFoundException::class);
});

it('lands a queued marker as placed and delivers marker.placed', function (): void {
    Event::fake();
    $fake = Tmesis::fake()->withStream(liveStreamRecord());

    $request = MarkerRequest::out();
    Tmesis::node()->markers()->placeMarker('show-2041-main', $request);

    $fake->landMarker('show-2041-main', $request->id(), ['480p', '720p'])->deliverTo($this->app);

    $marker = $fake->markers('show-2041-main')[0];

    expect($marker->isPlaced())->toBeTrue()
        ->and($marker->complete())->toBeTrue()
        ->and($marker->placements())->toHaveCount(2)
        ->and($marker->startDate())->not->toBeNull();

    Event::assertDispatched(MarkerPlaced::class, fn (MarkerPlaced $event): bool => $event->marker() === $request->id()
        && $event->session() === '01J8ZC8XKWQ4M7T0K3PZ2B9RVN'
        && count($event->placements()) === 2);

    // Landed once: a second landing finds nothing queued.
    expect(fn () => $fake->landMarker('show-2041-main', $request->id()))->toThrow(NotFoundException::class);
});

it('records no marker it refused', function (): void {
    $fake = Tmesis::fake()->withStream(liveStreamRecord(state: 'input_lost'));

    expect(fn () => Tmesis::node()->markers()->placeMarker('show-2041-main', MarkerRequest::out()))->toThrow(ConflictException::class)
        ->and($fake->markers('show-2041-main'))->toBe([]);

    $fake->assertCalled('placeMarker', fn (array $args): bool => $args['streamId'] === 'show-2041-main' && $args['kind'] === 'out');
});

it('reaches both resources through a request id too', function (): void {
    Tmesis::fake()->withStream(liveStreamRecord());

    $scoped = Tmesis::node()->withRequestId('req-1');

    expect($scoped->previewGrants()->listPreviewGrants()->grants())->toBe([])
        ->and($scoped->markers()->listMarkers('show-2041-main')->markers())->toBe([]);
});

it('expires a grant on Laravel\'s clock: gone from the list, and a 404 to update or revoke', function (): void {
    $this->freezeTime();
    $fake = Tmesis::fake();
    $grants = Tmesis::node()->previewGrants();

    $grant = $grants->createPreviewGrant(PreviewGrantInput::make(['203.0.113.7'], 60));

    $this->travel(59)->seconds();

    expect($grants->listPreviewGrants()->grants())->toHaveCount(1);

    $this->travel(2)->seconds();

    expect($grants->listPreviewGrants()->grants())->toBe([])
        ->and($fake->grants())->toBe([])
        ->and(fn () => $grants->updatePreviewGrant($grant->id(), PreviewGrantPatch::make(60)))->toThrow(NotFoundException::class)
        ->and(fn () => $grants->revokePreviewGrant($grant->id()))->toThrow(NotFoundException::class);

    expect(fn () => $fake->assertGranted('203.0.113.7'))->toThrow(AssertionFailedError::class);
});

it('extends a grant relative to the frozen clock', function (): void {
    $this->freezeTime();
    Tmesis::fake();
    $grants = Tmesis::node()->previewGrants();

    $created = now()->toImmutable()->utc();
    $grant = $grants->createPreviewGrant(PreviewGrantInput::make(['203.0.113.7'], 600));

    expect($grant->createdAt()->getTimestamp())->toBe($created->getTimestamp())
        ->and($grant->expiresAt()->getTimestamp())->toBe($created->getTimestamp() + 600);

    $this->travel(300)->seconds();

    // Now plus the ttl, earlier than before: the node's rule, on the test clock.
    $extended = $grants->updatePreviewGrant($grant->id(), PreviewGrantPatch::make(120));

    expect($extended->expiresAt()->getTimestamp())->toBe($created->getTimestamp() + 300 + 120)
        ->and($extended->createdAtRaw())->toBe($grant->createdAtRaw());
});
