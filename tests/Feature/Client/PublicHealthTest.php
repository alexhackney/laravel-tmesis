<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthPublic;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\ConnectionException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Exceptions\ServerException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * The docs/API.md 9.1 fence: what the route answers to a request with no bearer.
 *
 * @return array<string, mixed>
 */
function publicHealthBody(): array
{
    return [
        'status' => 'ok',
        'node' => 'origin-atl-01',
        'version' => '1.4.0',
        'contract_version' => 1,
    ];
}

/**
 * @return array<string, mixed>
 */
function publicHealthError(string $code, string $message): array
{
    return ['error' => [
        'code' => $code,
        'message' => $message,
        'details' => null,
        'request_id' => 'req_01J8ZC',
        'milestone' => null,
    ]];
}

it('probes the health route with no bearer token and decodes HealthPublic', function (): void {
    Http::fake(['127.0.0.1:7000/api/v1/health' => Http::response(publicHealthBody())]);

    $health = Tmesis::node()->getPublicHealth();

    expect($health)->toBeInstanceOf(HealthPublic::class)
        ->and($health->status())->toBe(HealthPublic::STATUS_OK)
        ->and($health->node())->toBe('origin-atl-01')
        ->and($health->version())->toBe('1.4.0')
        ->and($health->contractVersion())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1:7000/api/v1/health'
        && ! $request->hasHeader('Authorization')
        && $request->hasHeader('Accept', 'application/json'));
});

it('answers a body that validates against the HealthPublic component', function (): void {
    Http::fake(['*' => Http::response(publicHealthBody())]);

    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode(Tmesis::node()->getPublicHealth()->toArray()), false),
        '/components/schemas/HealthPublic',
        'The getPublicHealth() round trip',
    );
});

it('reaches the resource method and the shortcut alike', function (): void {
    Http::fake(['*' => Http::response(publicHealthBody())]);

    Tmesis::node()->node()->getPublicHealth();
    Tmesis::node()->getPublicHealth();

    Http::assertSentCount(2);
});

it('probes a node configured with no token at all', function (): void {
    // A probe-only consumer (a load balancer check, a status page) has the URL and
    // nothing else. The token is never read, so a blank one is not an error here,
    // where it is on every other call.
    config()->set('tmesis.nodes.'.TestCase::NODE.'.token', '');
    Http::fake(['*' => Http::response(publicHealthBody())]);

    expect(Tmesis::node()->getPublicHealth()->status())->toBe('ok')
        ->and(fn () => Tmesis::node()->getHealth())->toThrow(InvalidConfigurationException::class);

    Http::assertSentCount(1);
});

it('still refuses a blank url before sending anything', function (): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', '');
    Http::fake();

    expect(fn () => Tmesis::node()->getPublicHealth())
        ->toThrow(InvalidConfigurationException::class, 'tmesis.nodes.'.TestCase::NODE.'.url');

    Http::assertNothingSent();
});

it('probes a node that is not in config', function (): void {
    Http::fake(['10.0.0.9:7000/*' => Http::response(publicHealthBody())]);

    Tmesis::connect(new NodeConfig('discovered-01', 'http://10.0.0.9:7000/', 'discovered-token'))->getPublicHealth();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.0.0.9:7000/api/v1/health'
        && ! $request->hasHeader('Authorization'));
});

it('keeps the request id and still sends no token', function (): void {
    Http::fake(['*' => Http::response(publicHealthBody())]);

    Tmesis::node()->withRequestId('req_mine')->node()->getPublicHealth();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Request-Id', 'req_mine')
        && ! $request->hasHeader('Authorization'));
});

it('leaves the authenticated read authenticated after a probe', function (): void {
    // withoutToken() is a clone; the transport it came from keeps its bearer.
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $node = Tmesis::node()->node();
    $node->getPublicHealth();
    $node->getHealth();

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2)
        ->and($recorded[0][0]->hasHeader('Authorization'))->toBeFalse()
        ->and($recorded[1][0]->hasHeader('Authorization', 'Bearer test-token'))->toBeTrue();
});

it('maps a 503 on the probe to UnavailableException naming why', function (string $code): void {
    // not_ready is the one code the node emits here; draining and journal_unavailable
    // are reserved on this route (TMESIS-API-4). The mapping holds for all three.
    Http::fake(['*' => Http::response(publicHealthError($code, 'hold off'), 503)]);

    try {
        Tmesis::node()->getPublicHealth();
        $this->fail('Expected an UnavailableException.');
    } catch (UnavailableException $e) {
        expect($e->code())->toBe($code);
    }
})->with(['not_ready', 'draining', 'journal_unavailable']);

it('maps a 500 on the probe to ServerException', function (): void {
    Http::fake(['*' => Http::response(publicHealthError('internal', 'boom'), 500)]);

    expect(fn () => Tmesis::node()->getPublicHealth())->toThrow(ServerException::class);
});

it('wraps a transport failure on the probe', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 7'));

    expect(fn () => Tmesis::node()->getPublicHealth())
        ->toThrow(ConnectionException::class, 'Could not reach the Tmesis node');
});

it('sends no Authorization on the probe even when the host app sets one on every request', function (string $how): void {
    // A consuming app can put a credential on every request its Http factory builds.
    // The tokenless read must not carry the HOST's credential to the node either.
    Http::fake(['*' => Http::response(publicHealthBody())]);

    match ($how) {
        'global options header' => Http::globalOptions(['headers' => ['Authorization' => 'Bearer host-secret']]),
        'global options basic auth' => Http::globalOptions(['auth' => ['host-user', 'host-pass']]),
        'global request middleware' => Http::globalRequestMiddleware(
            fn (RequestInterface $request) => $request->withHeader('Authorization', 'Bearer host-middleware')
        ),
    };

    Tmesis::node()->getPublicHealth();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    Http::assertSentCount(1);
})->with(['global options header', 'global options basic auth', 'global request middleware']);

it('still sends its own bearer over a host-level Authorization on the authenticated read', function (): void {
    // withToken() replaces the header a host's global options set; only the tokenless
    // read strips. (A host's global REQUEST MIDDLEWARE runs after withToken() and is the
    // host's own decision, so it is not asserted either way here.)
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);
    Http::globalOptions(['headers' => ['Authorization' => 'Bearer host-secret']]);

    Tmesis::node()->getHealth();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer test-token']);
});
