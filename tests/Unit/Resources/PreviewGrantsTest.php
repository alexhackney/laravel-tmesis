<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Preview\PreviewGrant;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantInput;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantListResponse;
use AlexHackney\Tmesis\Data\Preview\PreviewGrantPatch;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\NotFoundException;
use AlexHackney\Tmesis\Exceptions\ValidationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates a grant with every family in one body and reads the canonical answer', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/createPreviewGrant.json'), 201)]);

    $grant = Tmesis::node()->previewGrants()->createPreviewGrant(
        PreviewGrantInput::make(['203.0.113.7', '2001:db8::7', '203.0.113.7'], 3600)
            ->forStreams(['show-2041-main'])
            ->withLabels(['panel_session' => 'p-8841']),
    );

    expect($grant)->toBeInstanceOf(PreviewGrant::class)
        ->and($grant->id())->toBe('01J8Z3N6Q4W5E6R7T8Y9V0H1XP')
        ->and($grant->ips())->toBe(['203.0.113.7', '2001:db8::7'])
        ->and($grant->streams())->toBe(['show-2041-main'])
        ->and($grant->coversEveryStream())->toBeFalse()
        ->and($grant->covers('2001:db8::7', 'show-2041-main'))->toBeTrue()
        ->and($grant->covers('2001:db8::7', 'show-2042-main'))->toBeFalse()
        ->and($grant->covers('198.51.100.1'))->toBeFalse()
        ->and($grant->labels())->toBe(['panel_session' => 'p-8841'])
        ->and($grant->createdAtRaw())->toBe('2026-09-27T20:00:00Z')
        ->and($grant->createdAt()->format('H:i'))->toBe('20:00')
        ->and($grant->expiresAtRaw())->toBe('2026-09-27T21:00:00Z')
        ->and($grant->expiresAt()->format('H:i'))->toBe('21:00');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/preview/grants'
        && json_decode($request->body(), true) === [
            'ips' => ['203.0.113.7', '2001:db8::7'],
            'ttl_seconds' => 3600,
            'streams' => ['show-2041-main'],
            'labels' => ['panel_session' => 'p-8841'],
        ]);
});

it('omits streams and labels when not set: every stream, no labels', function (): void {
    expect(PreviewGrantInput::make(['203.0.113.7'], 60)->toArray())->toBe(['ips' => ['203.0.113.7'], 'ttl_seconds' => 60])
        ->and(PreviewGrantInput::make(['203.0.113.7'], 60)->forStreams(null)->withLabels([])->toArray())->toBe(['ips' => ['203.0.113.7'], 'ttl_seconds' => 60]);
});

it('lists the live grants and finds its own by label', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listPreviewGrants.json'))]);

    $list = Tmesis::node()->previewGrants()->listPreviewGrants();

    expect($list->grants())->toHaveCount(2)
        ->and($list->grants()[0]->streams())->toBeNull()
        ->and($list->grants()[0]->coversEveryStream())->toBeTrue()
        ->and($list->grants()[0]->covers('203.0.113.7', 'any-stream'))->toBeTrue()
        ->and($list->withLabels(['panel_session' => 'p-8841']))->toHaveCount(1)
        ->and($list->withLabels(['panel_session' => 'p-8841'])[0]->id())->toBe('01J8Z3P2K7M9Q4W5E6R7T8Y9V0')
        ->and($list->withLabels(['panel_session' => 'nope']))->toBe([])
        ->and($list->toArray())->toHaveKey('grants');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/preview/grants');
});

it('extends a grant on reload by its id', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/updatePreviewGrant.json'))]);

    $grant = Tmesis::node()->previewGrants()->updatePreviewGrant('01J8Z3N6Q4W5E6R7T8Y9V0H1XP', PreviewGrantPatch::make(6000));

    expect($grant->expiresAtRaw())->toBe('2026-09-27T21:40:00Z');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/preview/grants/01J8Z3N6Q4W5E6R7T8Y9V0H1XP'
        && json_decode($request->body(), true) === ['ttl_seconds' => 6000]);
});

it('revokes a grant and answers the grant as it was', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/revokePreviewGrant.json'))]);

    $grant = Tmesis::node()->previewGrants()->revokePreviewGrant('01J8Z3N6Q4W5E6R7T8Y9V0H1XP');

    expect($grant->id())->toBe('01J8Z3N6Q4W5E6R7T8Y9V0H1XP');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/preview/grants/01J8Z3N6Q4W5E6R7T8Y9V0H1XP');
});

it('maps an unknown, expired or revoked grant to not found', function (): void {
    Http::fake(['*' => Http::response(errorBody('not_found', 'no such live grant'), 404)]);

    expect(fn () => Tmesis::node()->previewGrants()->revokePreviewGrant('01J8Z3N6Q4W5E6R7T8Y9V0H1XP'))->toThrow(NotFoundException::class)
        ->and(fn () => Tmesis::node()->previewGrants()->updatePreviewGrant('01J8Z3N6Q4W5E6R7T8Y9V0H1XP', PreviewGrantPatch::make(60)))->toThrow(NotFoundException::class);
});

it('maps the node\'s 422 for what only the node refuses', function (): void {
    Http::fake(['*' => Http::response(errorBody('validation_failed', 'the request has field problems'), 422)]);

    // Multicast is the node's refusal; the builder checks grammar only.
    expect(fn () => Tmesis::node()->previewGrants()->createPreviewGrant(PreviewGrantInput::make(['224.0.0.1'], 60)))
        ->toThrow(ValidationException::class);
});

it('refuses what the node would refuse on its face, with the node\'s sentences', function (Closure $build, string $field, string $message): void {
    try {
        $build();
    } catch (InvalidConfigurationException $e) {
        expect($e->getMessage())->toContain($field)->toContain($message);

        return;
    }

    $this->fail('Expected the builder to refuse.');
})->with([
    'no ips' => [fn () => PreviewGrantInput::make([], 60), 'ips', 'required: at least one address'],
    'seventeen ips' => [fn () => PreviewGrantInput::make(array_map(fn (int $i): string => "203.0.113.{$i}", range(1, 17)), 60), 'ips', 'at most 16 addresses'],
    'a name' => [fn () => PreviewGrantInput::make(['panel.example.com'], 60), 'ips[0]', 'not a name or a CIDR'],
    'a CIDR' => [fn () => PreviewGrantInput::make(['203.0.113.0/24'], 60), 'ips[0]', 'not a name or a CIDR'],
    'a zone' => [fn () => PreviewGrantInput::make(['203.0.113.7', 'fe80::1%eth0'], 60), 'ips[1]', 'not a name or a CIDR'],
    'ttl under a minute' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 59), 'ttl_seconds', 'must be between 60 and 86400'],
    'ttl over a day' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 86401), 'ttl_seconds', 'must be between 60 and 86400'],
    'patch ttl' => [fn () => PreviewGrantPatch::make(0), 'ttl_seconds', 'must be between 60 and 86400'],
    'an empty stream list' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 60)->forStreams([]), 'streams', 'an empty list covers nothing; omit it or send null for every stream'],
    'sixty five streams' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 60)->forStreams(array_map(fn (int $i): string => "s{$i}", range(1, 65))), 'streams', 'at most 64 streams'],
    'a bad stream id' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 60)->forStreams(['ok', 'Show.2041']), 'streams[1]', 'must not contain a dot'],
    'seventeen labels' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 60)->withLabels(array_combine(array_map(fn (int $i): string => "k{$i}", range(1, 17)), array_fill(0, 17, 'v'))), 'labels', 'at most 16 keys'],
    'a long label key' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 60)->withLabels([str_repeat('k', 65) => 'v']), 'labels.', 'key is over 64 bytes'],
    'a long label value' => [fn () => PreviewGrantInput::make(['203.0.113.7'], 60)->withLabels(['k' => str_repeat('v', 257)]), 'labels.k', 'value is over 256 bytes'],
]);

it('keeps what it was built with', function (): void {
    $input = PreviewGrantInput::make(['203.0.113.7'], 600)->forStreams(['a', 'a', 'b'])->withLabels(['panel' => 'p1']);

    expect($input->ips())->toBe(['203.0.113.7'])
        ->and($input->ttlSeconds())->toBe(600)
        ->and($input->streams())->toBe(['a', 'b'])
        ->and($input->labels())->toBe(['panel' => 'p1'])
        ->and(PreviewGrantPatch::make(600)->ttlSeconds())->toBe(600)
        ->and(PreviewGrantPatch::make(600)->toArray())->toBe(['ttl_seconds' => 600]);
});

it('keeps an empty label map an object on the way back out', function (): void {
    $list = PreviewGrantListResponse::fromArray($this->fixtureJson('responses/listPreviewGrants.json'));

    expect(json_encode($list->grants()[0]->toArray()['labels']))->toBe('{}')
        ->and(json_encode($list->toArray()['grants'][0]['labels']))->toBe('{}')
        ->and(json_encode($list->toArray()['grants'][1]['labels']))->toBe('{"panel_session":"p-8841"}')
        ->and($list->grants()[0]->labels())->toBe([]);
});
