<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Exceptions\InsecureTransportException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * the admin bearer token is never sent over cleartext to a host that is not
 * loopback, outside the local and testing environments; refused before anything is
 * sent, naming the node and the scheme and host, never the token.
 */
it('names where a url would send the token in clear, or null', function (string $url, ?string $expected): void {
    expect(Transport::cleartextTarget($url))->toBe($expected);
})->with([
    'https' => ['https://edge-atl-01.tsp24.com:7000', null],
    'http on 127.0.0.1' => ['http://127.0.0.1:7000', null],
    'http anywhere in 127.0.0.0/8' => ['http://127.8.0.1:7000', null],
    'http on localhost' => ['http://LOCALHOST:7000', null],
    'http on ::1' => ['http://[::1]:7000', null],
    'http on an IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]:7000', null],
    'http on a private address' => ['http://10.0.0.9:7000/api', 'http://10.0.0.9'],
    'http on a name' => ['HTTP://Edge.tsp24.com:7000', 'http://edge.tsp24.com'],
    'http on the wildcard' => ['http://0.0.0.0:7000', 'http://0.0.0.0'],
    'another scheme' => ['ftp://edge.tsp24.com', 'ftp://edge.tsp24.com'],
]);

it('allows cleartext in local and testing only', function (): void {
    expect(Transport::allowsCleartext('local'))->toBeTrue()
        ->and(Transport::allowsCleartext('testing'))->toBeTrue()
        ->and(Transport::allowsCleartext('production'))->toBeFalse()
        ->and(Transport::allowsCleartext('staging'))->toBeFalse();
});

it('refuses an admin call over http to a host that is not loopback outside local and testing, sending nothing', function (): void {
    $this->app['env'] = 'production';
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', 'http://10.0.0.9:7000');
    Http::fake();

    try {
        Tmesis::node()->getNodeStats();
        $this->fail('sent');
    } catch (InsecureTransportException $e) {
        expect($e->getMessage())->toContain('['.TestCase::NODE.']')
            ->and($e->getMessage())->toContain('[http://10.0.0.9]')
            ->and($e->getMessage())->toContain('api.tls.domain')
            ->and($e->getMessage())->not->toContain('test-token')
            ->and($e->getMessage())->not->toContain(':7000');
    }

    Http::assertNothingSent();
});

it('still sends to https and to http on loopback outside local and testing', function (string $url): void {
    $this->app['env'] = 'production';
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', $url);
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getNodeStats.json'))]);

    Tmesis::node()->getNodeStats();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
})->with(['https://edge-atl-01.tsp24.com:7000', 'http://127.0.0.1:7000', 'http://[::1]:7000', 'http://localhost:7000']);

it('sends to http anywhere in the testing environment, as the reference receiver needs', function (): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', 'http://10.0.0.9:7000');
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getNodeStats.json'))]);

    Tmesis::node()->getNodeStats();

    Http::assertSentCount(1);
});

it('still sends the tokenless liveness probe over http, which carries no credential', function (): void {
    $this->app['env'] = 'production';
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', 'http://10.0.0.9:7000');
    Http::fake(['*' => Http::response(['status' => 'ok', 'node' => 'n', 'version' => '1', 'contract_version' => 1])]);

    Tmesis::node()->getPublicHealth();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});
