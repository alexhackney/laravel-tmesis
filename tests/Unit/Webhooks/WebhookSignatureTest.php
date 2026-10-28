<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Tests\TestCase;
use AlexHackney\Tmesis\Webhooks\VerifyTmesisSignature;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

afterEach(fn () => Carbon::setTestNow());

it('joins the signed string with exactly one 0x2E', function (): void {
    expect(WebhookSignature::signedString('1787944321', '{}'))->toBe('1787944321.{}')
        ->and(WebhookSignature::signedString('1787944321', ''))->toBe('1787944321.')
        ->and(strlen(WebhookSignature::signedString('1787944321', '')))->toBe(11);
});

it('signs an empty body', function (): void {
    expect(WebhookSignature::sign(TestCase::WEBHOOK_SECRET, '1787944321', ''))
        ->toBe('sha256=c5c63ab9e13c88c4bf62b0ba95800a31027aa8910b56ef347a1e89a31e508bca');
});

it('rejects an unrecognised prefix before computing a MAC', function (): void {
    $signature = WebhookSignature::sign(TestCase::WEBHOOK_SECRET, '1787944321', '{}');
    $bare = substr($signature, strlen('sha256='));

    expect(WebhookSignature::verify(TestCase::WEBHOOK_SECRET, '1787944321', '{}', $bare))->toBeFalse()
        ->and(WebhookSignature::verify(TestCase::WEBHOOK_SECRET, '1787944321', '{}', strtoupper($signature)))->toBeFalse()
        ->and(WebhookSignature::verify(TestCase::WEBHOOK_SECRET, '1787944321', '{}', $signature))->toBeTrue();
});

it('accepts the window at 300 seconds and rejects it at 301', function (): void {
    $body = ['event' => 'node.online', 'delivery' => '01J8ZCV6R2K8NQX4WM7TVB5PD9'];

    Carbon::setTestNow(Carbon::createFromTimestampUTC(1787944321 + 300));
    $this->signedPost('node.online', $body, ['HTTP_X_TMESIS_TIMESTAMP' => '1787944321'])
        ->assertNoContent();

    Carbon::setTestNow(Carbon::createFromTimestampUTC(1787944321 + 301));
    $response = $this->signedPost('node.online', $body, ['HTTP_X_TMESIS_TIMESTAMP' => '1787944321']);

    $response->assertUnauthorized();
    expect($response->json('error.code'))->toBe('stale_timestamp');
});

it('verifies the raw bytes, so a re-encoded body fails', function (): void {
    $body = '{"event":"node.online","delivery":"01J8ZCV6R2K8NQX4WM7TVB5PD9","contract_version":1}';
    $reordered = '{"delivery":"01J8ZCV6R2K8NQX4WM7TVB5PD9","event":"node.online","contract_version":1}';

    $timestamp = (string) now()->getTimestamp();

    // Signed over the original bytes, posted with the re-encoded ones.
    $this->signedPostRaw('node.online', $reordered, [
        'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
        'HTTP_X_TMESIS_SIGNATURE' => WebhookSignature::sign(TestCase::WEBHOOK_SECRET, $timestamp, $body),
    ])->assertUnauthorized();
});

it('answers bad_signature for an unknown node and logs unknown_node', function (): void {
    Log::spy();

    $response = $this->signedPost('node.online', ['event' => 'node.online'], [
        'HTTP_X_TMESIS_NODE' => 'nobody',
    ]);

    $response->assertUnauthorized();
    // The response must not reveal which node names are configured.
    expect($response->json('error.code'))->toBe('bad_signature');

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['reason'] === 'unknown_node'
    );
});

it('rejects a missing signature and a non-numeric timestamp by name', function (): void {
    $body = ['event' => 'node.online'];

    $missing = $this->signedPost('node.online', $body, ['HTTP_X_TMESIS_SIGNATURE' => '']);
    $missing->assertUnauthorized();
    expect($missing->json('error.code'))->toBe('missing_signature');

    $bad = $this->signedPost('node.online', $body, ['HTTP_X_TMESIS_TIMESTAMP' => 'not-a-number']);
    $bad->assertUnauthorized();
    expect($bad->json('error.code'))->toBe('bad_timestamp');
});

it('dispatches nothing from terminate() when nothing was stashed', function (): void {
    Event::fake();

    $middleware = app(VerifyTmesisSignature::class);
    $request = Request::create('/webhooks/tmesis', 'POST');

    $middleware->terminate($request, new Response);

    Event::assertNothingDispatched();
});
