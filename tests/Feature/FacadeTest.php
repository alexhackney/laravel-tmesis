<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\TmesisClient;
use AlexHackney\Tmesis\Client\TmesisClientInterface;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Keys\KeyEpoch;
use AlexHackney\Tmesis\Keys\StreamKeyMinter;
use AlexHackney\Tmesis\Testing\FakeTmesis;
use Illuminate\Support\Facades\Http;

it('resolves the bound client', function (): void {
    expect(Tmesis::getFacadeRoot())->toBeInstanceOf(TmesisClient::class);
});

it('reads through the facade', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    expect(Tmesis::node()->getHealth()->node())->toBe('origin-atl-01');
});

it('mints the same key as the bound minter', function (): void {
    $expected = app(StreamKeyMinter::class)->mint('show-2041-main', KeyEpoch::fromInt(7));

    expect(Tmesis::mint('show-2041-main', KeyEpoch::fromInt(7))->toString())->toBe($expected->toString());
});

it('swaps the binding when faked', function (): void {
    $fake = Tmesis::fake();

    expect($fake)->toBeInstanceOf(FakeTmesis::class)
        ->and(app(TmesisClientInterface::class))->toBe($fake);
});
