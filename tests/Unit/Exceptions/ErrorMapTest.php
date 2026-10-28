<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\ErrorResponse;
use AlexHackney\Tmesis\Exceptions\AuthenticationException;
use AlexHackney\Tmesis\Exceptions\BadRequestException;
use AlexHackney\Tmesis\Exceptions\ClientException;
use AlexHackney\Tmesis\Exceptions\ConflictException;
use AlexHackney\Tmesis\Exceptions\EncoderUnavailableException;
use AlexHackney\Tmesis\Exceptions\ErrorMap;
use AlexHackney\Tmesis\Exceptions\FleetOwnedException;
use AlexHackney\Tmesis\Exceptions\MethodNotAllowedException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\NotImplementedException;
use AlexHackney\Tmesis\Exceptions\PayloadTooLargeException;
use AlexHackney\Tmesis\Exceptions\RateLimitException;
use AlexHackney\Tmesis\Exceptions\ServerException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Exceptions\UnsupportedMediaTypeException;
use AlexHackney\Tmesis\Exceptions\ValidationException;

function envelope(string $code, string $message = 'boom', mixed $details = null, ?string $milestone = null): ErrorResponse
{
    return ErrorResponse::fromArray(['error' => [
        'code' => $code,
        'message' => $message,
        'details' => $details,
        'request_id' => 'req_01J8ZC',
        'milestone' => $milestone,
    ]]);
}

it('maps every code in the frozen table', function (string $code, int $status, string $class): void {
    $e = ErrorMap::toException($status, envelope($code));

    expect($e)->toBeInstanceOf($class)
        ->and($e->code())->toBe($code)
        ->and($e->status())->toBe($status)
        ->and($e->requestId())->toBe('req_01J8ZC')
        ->and($e->getMessage())->toBe('boom');
})->with([
    ['bad_request', 400, BadRequestException::class],
    ['unauthorized', 401, AuthenticationException::class],
    ['not_found', 404, NotFoundException::class],
    ['method_not_allowed', 405, MethodNotAllowedException::class],
    ['stream_exists', 409, ConflictException::class],
    ['stream_sealed', 409, ConflictException::class],
    ['stream_busy', 409, ConflictException::class],
    ['stream_not_live', 409, ConflictException::class],
    ['lease_held_elsewhere', 409, ConflictException::class],
    ['job_not_cancellable', 409, ConflictException::class],
    // The transcode job codes, tmesis v0.6.0 (API.md 9.10).
    ['destination_busy', 409, ConflictException::class],
    ['source_not_supported', 422, ValidationException::class],
    ['output_not_supported', 422, ValidationException::class],
    ['destination_not_supported', 422, ValidationException::class],
    ['queue_full', 429, RateLimitException::class],
    ['role_unsupported', 409, ConflictException::class],
    // Emitted by the relay routes and described in openapi.json, but absent from the
    // docs/API.md 6.2 table at v0.1.0-rc2. Mapped on the artifact's authority.
    ['relay_immutable', 409, ConflictException::class],
    // The nine artifact codes, tmesis v0.5.0 (API.md 6.2 and 12a).
    ['artifact_not_finalized', 409, ConflictException::class],
    ['artifact_identity_mismatch', 409, ConflictException::class],
    ['transfer_plan_stale', 409, ConflictException::class],
    ['transfer_identity_mismatch', 409, ConflictException::class],
    ['transfer_incomplete', 409, ConflictException::class],
    ['request_conflict', 409, ConflictException::class],
    ['release_conflict', 409, ConflictException::class],
    ['artifact_retiring', 409, ConflictException::class],
    ['artifact_reconciliation_required', 409, ConflictException::class],
    // The purge and master codes, tmesis v0.8.0 (API.md 12a.5b and 12a.8).
    ['artifact_held', 409, ConflictException::class],
    ['master_disabled', 409, ConflictException::class],
    ['invalid_source', 422, ValidationException::class],
    ['sources_incompatible', 422, ValidationException::class],
    ['master_unavailable', 503, UnavailableException::class],
    ['payload_too_large', 413, PayloadTooLargeException::class],
    ['unsupported_media_type', 415, UnsupportedMediaTypeException::class],
    ['validation_failed', 422, ValidationException::class],
    ['immutable_field', 422, ValidationException::class],
    ['rate_limited', 429, RateLimitException::class],
    ['internal', 500, ServerException::class],
    ['not_implemented', 501, NotImplementedException::class],
    ['draining', 503, UnavailableException::class],
    ['not_ready', 503, UnavailableException::class],
    ['journal_unavailable', 503, UnavailableException::class],
    // tmesis v0.9.0: the enrolled box's pause routes (and the v0.7.0 stream and relay
    // refusals), and the job submit's failed encoder pin (handover 3.5 and 3.7).
    ['fleet_owned', 409, FleetOwnedException::class],
    ['encoder_unavailable', 503, EncoderUnavailableException::class],
]);

it('keeps the two named subclasses catchable as their status class', function (): void {
    expect(ErrorMap::toException(409, envelope('fleet_owned')))->toBeInstanceOf(ConflictException::class)
        ->and(ErrorMap::toException(503, envelope('encoder_unavailable')))->toBeInstanceOf(UnavailableException::class)
        // A sibling code on the same status stays the plain class.
        ->and(ErrorMap::toException(409, envelope('stream_busy')))->not->toBeInstanceOf(FleetOwnedException::class)
        ->and(ErrorMap::toException(503, envelope('draining')))->not->toBeInstanceOf(EncoderUnavailableException::class);
});

it('reads Retry-After onto an EncoderUnavailableException too, though the node sends none', function (): void {
    $e = ErrorMap::toException(503, envelope('encoder_unavailable'), ['Retry-After' => '5']);

    expect($e)->toBeInstanceOf(EncoderUnavailableException::class)
        ->and($e instanceof EncoderUnavailableException ? $e->retryAfter() : null)->toBe(5)
        ->and($e instanceof EncoderUnavailableException && $e->retryableHere())->toBeFalse();
});

it('names the fleet-owned paths, and none when the node sent no details', function (): void {
    $owned = ErrorMap::toException(409, envelope('fleet_owned', details: [
        ['path' => 'jobs_pause', 'message' => "is owned by the fleet's desired state (jobs_pause)"],
    ]));
    $bare = ErrorMap::toException(409, envelope('fleet_owned'));

    expect($owned instanceof FleetOwnedException ? $owned->ownedPaths() : null)->toBe(['jobs_pause'])
        ->and($bare instanceof FleetOwnedException ? $bare->ownedPaths() : null)->toBe([]);
});

it('maps an unknown code by status, because the code set is open', function (): void {
    expect(ErrorMap::toException(409, envelope('some_future_conflict')))
        ->toBeInstanceOf(ConflictException::class)
        ->and(ErrorMap::toException(503, envelope('some_future_reason')))
        ->toBeInstanceOf(UnavailableException::class);
});

it('maps an unmapped 4xx to ClientException and an unmapped 5xx to ServerException', function (): void {
    expect(ErrorMap::toException(418, envelope('teapot')))->toBeInstanceOf(ClientException::class)
        ->and(ErrorMap::toException(502, null))->toBeInstanceOf(ServerException::class);
});

it('always produces NotImplementedException on a 501 and surfaces the milestone', function (): void {
    $withMilestone = ErrorMap::toException(501, envelope('not_implemented', 'not built', null, 'M3'));

    expect($withMilestone)->toBeInstanceOf(NotImplementedException::class)
        ->and($withMilestone->milestone())->toBe('M3');

    $bare = ErrorMap::toException(501, null);

    expect($bare)->toBeInstanceOf(NotImplementedException::class)
        ->and($bare->milestone())->toBeNull()
        ->and($bare->getMessage())->toBe('The Tmesis node answered 501.');

    // 501 outranks the code: a node answering 501 is telling us the route is not
    // built, whatever code it puts beside it.
    expect(ErrorMap::toException(501, envelope('not_found')))
        ->toBeInstanceOf(NotImplementedException::class);
});

it('carries the details of a 422', function (): void {
    $e = ErrorMap::toException(422, envelope('validation_failed', 'invalid', [
        ['path' => 'captions', 'message' => 'unknown field'],
    ]));

    expect($e)->toBeInstanceOf(ValidationException::class);
    /** @var ValidationException $e */
    expect($e->details())->toHaveCount(1)
        ->and($e->details()[0]->path())->toBe('captions')
        // There is no unknown_field code: a caller branches on the message.
        ->and($e->details()[0]->message())->toBe('unknown field');
});

it('reads retry-after on the statuses that carry one', function (): void {
    $rate = ErrorMap::toException(429, envelope('rate_limited'), ['Retry-After' => ['30']]);
    $busy = ErrorMap::toException(503, envelope('draining'), ['retry-after' => '5']);

    expect($rate)->toBeInstanceOf(RateLimitException::class)
        ->and($busy)->toBeInstanceOf(UnavailableException::class);

    /** @var RateLimitException $rate */
    /** @var UnavailableException $busy */
    expect($rate->retryAfter())->toBe(30)->and($busy->retryAfter())->toBe(5);
});

it('preserves the node headers', function (): void {
    $e = ErrorMap::toException(404, envelope('not_found'), [
        'X-Tmesis-Node' => ['origin-atl-01'],
        'X-Tmesis-Version' => ['1.4.0'],
    ]);

    expect($e->node())->toBe('origin-atl-01')->and($e->nodeVersion())->toBe('1.4.0');
});

it('falls back to the X-Request-Id header when the body has no request_id', function (): void {
    $e = ErrorMap::toException(404, ErrorResponse::fromArray(['error' => ['code' => 'not_found']]), [
        'X-Request-Id' => ['req_header'],
    ]);

    expect($e->requestId())->toBe('req_header');
});
