<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\ErrorResponse;
use Illuminate\Http\Client\Response;

function httpResponse(string $body, int $status = 500): Response
{
    return new Response(new GuzzleHttp\Psr7\Response($status, [], $body));
}

it('decodes the envelope', function (): void {
    $error = ErrorResponse::fromArray(['error' => [
        'code' => 'validation_failed',
        'message' => 'invalid',
        'details' => [['path' => 'ladder', 'message' => 'unknown ladder']],
        'request_id' => 'req_1',
        'milestone' => null,
    ]])->error();

    expect($error->code())->toBe('validation_failed')
        ->and($error->message())->toBe('invalid')
        ->and($error->requestId())->toBe('req_1')
        // Present and null. required means present, not non-null.
        ->and($error->milestone())->toBeNull()
        ->and($error->details())->toHaveCount(1)
        ->and($error->details()[0]->path())->toBe('ladder');
});

it('returns an empty details list when the body carries null', function (): void {
    expect(ErrorResponse::fromArray(['error' => ['code' => 'internal', 'details' => null]])->error()->details())
        ->toBe([]);
});

it('returns null for a body that is not the envelope', function (string $body): void {
    // A proxy's HTML page or an empty body must not become a decoding failure that
    // replaces the real error.
    expect(ErrorResponse::tryFromResponse(httpResponse($body)))->toBeNull();
})->with([
    'empty' => [''],
    'html' => ['<html><body>502 Bad Gateway</body></html>'],
    'json without error' => ['{"message":"nope"}'],
    'json array' => ['[1,2,3]'],
]);

it('decodes a real envelope from a response', function (): void {
    $body = '{"error":{"code":"not_found","message":"no such stream","details":null,"request_id":"req_2","milestone":null}}';

    $decoded = ErrorResponse::tryFromResponse(httpResponse($body, 404));

    expect($decoded)->not->toBeNull()
        ->and($decoded->error()->code())->toBe('not_found')
        ->and($decoded->toArray())->toBe(json_decode($body, true));
});
