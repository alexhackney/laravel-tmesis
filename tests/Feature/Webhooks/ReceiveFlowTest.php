<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Events\ArtifactReleased;
use AlexHackney\Tmesis\Events\RecordingRegistered;
use AlexHackney\Tmesis\Events\StreamLive;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Events\StreamSealed;
use AlexHackney\Tmesis\Events\TmesisWebhookReceived;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Tests\TestCase;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

afterEach(fn () => Carbon::setTestNow());

it('accepts a valid delivery and dispatches the typed event once', function (): void {
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');

    $this->signedPost('stream.published', $payload)->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);
    Event::assertDispatched(StreamPublished::class, function (StreamPublished $event) use ($payload): bool {
        return $event->deliveryId() === $payload['delivery']
            && $event->toArray() === $payload
            && $event->stream() === 'show-2041-main'
            && $event->auth()->keyEpoch()->value === 7
            && $event->sessionEpoch()->value === 3;
    });
});

it('answers 204 twice and dispatches once for a replayed delivery', function (): void {
    // Both posts happen in ONE test method on purpose: this is the assertion the
    // terminable middleware exists for. Application::terminate() never clears its
    // callback list, so a container terminating() closure from the first post would
    // fire again on the second.
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');

    $this->signedPost('stream.published', $payload)->assertNoContent();
    $this->signedPost('stream.published', $payload)->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('stores the delivery durably and marks it processed', function (): void {
    $this->usesDatabaseDeduper();
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $this->signedPostRaw('stream.published', (string) $body)->assertNoContent();
    $this->signedPostRaw('stream.published', (string) $body)->assertNoContent();

    $rows = DB::table('tmesis_webhook_deliveries')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->body)->toBe($body)
        ->and($rows[0]->processed_at)->not->toBeNull();

    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('dedupes on the signed body id, not the header', function (): void {
    // The header is NOT covered by the signature and the body is, so a replay with a
    // rewritten header must still dedupe.
    Log::spy();
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');

    $this->signedPost('stream.published', $payload, ['HTTP_X_TMESIS_DELIVERY' => 'HEADER-ONE'])
        ->assertNoContent();
    $this->signedPost('stream.published', $payload, ['HTTP_X_TMESIS_DELIVERY' => 'HEADER-TWO'])
        ->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => ($context['header_delivery'] ?? null) === 'HEADER-ONE'
            && ($context['body_delivery'] ?? null) === $payload['delivery']
    );
});

it('still answers 204 when a listener throws, and keeps the claim', function (): void {
    Exceptions::fake();

    $dispatched = 0;

    Event::listen(StreamPublished::class, function () use (&$dispatched): void {
        $dispatched++;

        throw new RuntimeException('listener exploded');
    });

    $payload = $this->exampleAsTestNode('stream.published');

    // The 204 leaves before the listener runs, so a throwing listener cannot turn a
    // delivered webhook into 24 hours of retries.
    $this->signedPost('stream.published', $payload)->assertNoContent();

    Exceptions::assertReported(RuntimeException::class);

    // The claim is kept: the node never retries an acknowledged delivery, and a
    // duplicate that does arrive is still a duplicate.
    $this->signedPost('stream.published', $payload)->assertNoContent();

    expect($dispatched)->toBe(1);
});

it('keeps the durable row, payload and failure when a listener throws', function (): void {
    $this->usesDatabaseDeduper();
    Exceptions::fake();

    Event::listen(StreamPublished::class, fn () => throw new RuntimeException('listener exploded'));

    $payload = $this->exampleAsTestNode('stream.published');
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $this->signedPostRaw('stream.published', $body)->assertNoContent();

    $row = DB::table('tmesis_webhook_deliveries')->sole();

    expect($row->delivery)->toBe($payload['delivery'])
        ->and($row->body)->toBe($body)
        ->and($row->event)->toBe('stream.published')
        ->and($row->node)->toBe(TestCase::NODE)
        ->and($row->processed_at)->toBeNull()
        ->and((int) $row->failed_attempts)->toBe(1)
        ->and($row->last_error)->toBe('RuntimeException: listener exploded')
        ->and($row->failed_at)->not->toBeNull()
        ->and($row->leased_until)->toBeNull();
});

it('rejects a stale timestamp and accepts the re-signed retry', function (): void {
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');
    $stale = (string) now()->subSeconds(301)->getTimestamp();

    $response = $this->signedPost('stream.published', $payload, ['HTTP_X_TMESIS_TIMESTAMP' => $stale]);

    $response->assertUnauthorized();
    expect($response->json('error.code'))->toBe('stale_timestamp');
    Event::assertNotDispatched(StreamPublished::class);

    // The node re-signs with a fresh timestamp on every attempt.
    $this->signedPost('stream.published', $payload)->assertNoContent();
    Event::assertDispatchedTimes(StreamPublished::class, 1);
});

it('dispatches the base event for an unknown event name', function (): void {
    Event::fake();

    // node.never_registered is a name no milestone will ever claim (v0.6.0);
    // node.capacity_changed and, before rc5, stream.input_lost played this part.
    $this->signedPost('node.never_registered', [
        'event' => 'node.never_registered',
        'delivery' => '01J8ZCF2N8R4VKX7QM3WB5TDH9',
        'occurred_at' => '2026-08-28T20:03:11.550Z',
        'node' => TestCase::NODE,
        'stream' => 'show-2041-main',
    ])->assertNoContent();

    Event::assertDispatched(TmesisWebhookReceived::class, function (TmesisWebhookReceived $event): bool {
        return $event::class === TmesisWebhookReceived::class
            && $event->event() === 'node.never_registered'
            && $event->isKnownEvent() === false;
    });
});

it('tolerates an unknown field', function (): void {
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');
    $payload['x_future_field'] = 1;

    $this->signedPost('stream.published', $payload)->assertNoContent();

    Event::assertDispatched(StreamPublished::class, fn (StreamPublished $e): bool => $e->toArray()['x_future_field'] === 1);
});

it('answers 204 for a body that does not decode, and dispatches nothing', function (): void {
    Log::spy();
    Event::fake();

    // Signed by the node, so it is not an attack; a non-2xx would only buy 24 hours
    // of retries of a body that will never decode.
    $this->signedPostRaw('stream.published', 'not json at all')->assertNoContent();

    Event::assertNotDispatched(StreamPublished::class);
    Event::assertNotDispatched(TmesisWebhookReceived::class);
    Log::shouldHaveReceived('warning');
});

it('takes both events of an out-of-order pair and orders them by occurred_at', function (): void {
    Event::fake();

    // Arrival order is meaningless: delivery is unordered.
    $this->signedPost('stream.sealed', $this->exampleAsTestNode('stream.sealed'))->assertNoContent();
    $this->signedPost('stream.live', $this->exampleAsTestNode('stream.live'))->assertNoContent();

    Event::assertDispatchedTimes(StreamSealed::class, 1);
    Event::assertDispatchedTimes(StreamLive::class, 1);

    $live = TmesisWebhookReceived::fromArray($this->fixtureJson('contract/docs/contract/examples/stream.live.json'));
    $sealed = TmesisWebhookReceived::fromArray($this->fixtureJson('contract/docs/contract/examples/stream.sealed.json'));

    $ordered = collect([$sealed, $live])
        ->sortBy(fn (TmesisWebhookReceived $e) => $e->occurredAt())
        ->values();

    expect($ordered[0]->event())->toBe('stream.live')
        ->and($ordered[1]->event())->toBe('stream.sealed');
});

it('rejects a tampered body', function (): void {
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = (string) now()->getTimestamp();

    $tampered = str_replace('show-2041-main', 'show-2041-mai_', $body);

    $this->call('POST', route('tmesis.webhooks.receive'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TMESIS_EVENT' => 'stream.published',
        'HTTP_X_TMESIS_DELIVERY' => $payload['delivery'],
        'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
        'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign(TestCase::WEBHOOK_SECRET, $timestamp, $body),
        'HTTP_X_TMESIS_NODE' => TestCase::NODE,
    ], $tampered)->assertUnauthorized();

    Event::assertNotDispatched(StreamPublished::class);
});

it('has the recording.registered row committed before the 204 is returned', function (): void {
    // Decision D13: the receiver 2xx IS the registration, and the node deletes its
    // local copy on it. The row must exist by the time the response is built.
    $this->usesDatabaseDeduper();

    $rowAtDispatch = null;

    Event::listen(RecordingRegistered::class, function () use (&$rowAtDispatch): void {
        $rowAtDispatch = DB::table('tmesis_webhook_deliveries')->count();
    });

    $payload = $this->exampleAsTestNode('recording.registered');

    $this->signedPost('recording.registered', $payload)->assertNoContent();

    expect($rowAtDispatch)->toBe(1)
        ->and(DB::table('tmesis_webhook_deliveries')->value('processed_at'))->not->toBeNull();
});

it('dispatches the typed artifact and transfer events end to end', function (): void {
    Event::fake();

    $released = $this->exampleAsTestNode('artifact.released');
    $needed = $this->exampleAsTestNode('transfer.authorization_required');

    $this->signedPost('artifact.released', $released)->assertNoContent();
    $this->signedPost('transfer.authorization_required', $needed)->assertNoContent();

    Event::assertDispatchedTimes(ArtifactReleased::class, 1);
    Event::assertDispatched(ArtifactReleased::class, function (ArtifactReleased $event) use ($released): bool {
        return $event->toArray() === $released
            && $event->identity()->generation() === '01J8ZCK5R7V2QNX8RW4TKB6PD2'
            && $event->action() === ArtifactReleased::ACTION_OVERRIDE;
    });

    Event::assertDispatched(TransferAuthorizationRequired::class, function (TransferAuthorizationRequired $event): bool {
        return $event->objectKey() === 'backup/2026/show/720p/seg_000412.m4s'
            && $event->requiredOperations() === ['parts', 'abort']
            && $event->delivery()?->event === 'transfer.authorization_required';
    });
});

it('logs loudly and still answers 204 when a delivery fails its bundled schema', function (): void {
    config()->set('tmesis.contract.validate_incoming', true);

    Log::spy();

    $payload = $this->exampleAsTestNode('stream.published');
    unset($payload['protocol']);

    $this->signedPost('stream.published', $payload)->assertNoContent();

    // A pinned schema is additionalProperties:false, so a newer node WILL fail it.
    // That is a repin signal for the operator, not a reason to refuse a delivery and
    // buy 24 hours of retries.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => $message === 'Tmesis webhook body does not validate against its bundled schema.')
        ->once();
});

it('validates a good delivery quietly', function (): void {
    config()->set('tmesis.contract.validate_incoming', true);

    Log::spy();

    $this->signedPost('stream.published', $this->exampleAsTestNode('stream.published'))->assertNoContent();

    Log::shouldNotHaveReceived('error');
});

it('validates an empty object as an object, from the raw bytes', function (): void {
    config()->set('tmesis.contract.validate_incoming', true);

    Log::spy();

    // `labels: {}` is valid on the wire. Decoded to a PHP array and re-encoded it
    // becomes `[]`, which fails `type: object`, so validation reads the raw body.
    $body = json_encode(
        ['labels' => new stdClass] + $this->exampleAsTestNode('stream.live'),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    expect($body)->toContain('"labels":{}');

    $this->signedPostRaw('stream.live', $body)->assertNoContent();

    Log::shouldNotHaveReceived('error');
});

it('builds no schema path out of a rewritten event header', function (): void {
    config()->set('tmesis.contract.validate_incoming', true);

    Log::spy();

    // `X-Tmesis-Event` is OUTSIDE the signature, which covers the timestamp and the
    // raw body, so a replay inside the 300 second window can rewrite it. It still
    // answers 204, and it still never decides which file gets opened.
    $this->signedPost(
        '../../../../../../etc/passwd',
        ['delivery' => '01J8ZCV6R2K8NQX4WM7TVB5PD9', 'occurred_at' => '2026-08-28T19:26:03.118Z'],
    )->assertNoContent();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_starts_with($message, 'Tmesis cannot validate incoming bodies:'))
        ->once();
});

it('skips validation for an event this build has no schema for', function (): void {
    config()->set('tmesis.contract.validate_incoming', true);

    Log::spy();

    // The catalog is additive, so a name from a newer node is expected traffic and
    // not something to shout about.
    $this->signedPost('caption.ready', ['delivery' => '01J8ZCV6R2K8NQX4WM7TVB5PD9', 'occurred_at' => '2026-08-28T19:26:03.118Z'])
        ->assertNoContent();

    Log::shouldNotHaveReceived('error');
});

it('verifies against the header node and never compares it with the body node', function (): void {
    Event::fake();

    // The raw example names origin-atl-01 while the harness signs as TestCase::NODE.
    // The node never sends that, but the receiver's rule is deliberate: X-Tmesis-Node
    // picks the secret, the signature over the raw body is the authentication, and
    // the body's node is carried through untouched rather than cross-checked.
    $payload = $this->fixtureJson('contract/docs/contract/examples/stream.published.json');

    expect($payload['node'])->not->toBe(TestCase::NODE);

    $this->signedPost('stream.published', $payload)->assertNoContent();

    Event::assertDispatched(StreamPublished::class, fn (StreamPublished $event): bool => $event->node() === 'origin-atl-01');
});
