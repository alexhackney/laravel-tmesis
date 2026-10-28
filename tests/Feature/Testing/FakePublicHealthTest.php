<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthPublic;
use AlexHackney\Tmesis\Exceptions\ConnectionException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

it('answers a default public health before anything is canned', function (): void {
    $fake = Tmesis::fake();

    $health = Tmesis::node()->getPublicHealth();

    expect($health)->toBeInstanceOf(HealthPublic::class)
        ->and($health->status())->toBe('ok')
        ->and($health->node())->toBe('fake-node');

    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode($health->toArray()), false),
        '/components/schemas/HealthPublic',
        'The fake public health',
    );

    $fake->assertCalled('getHealth', fn (array $args): bool => $args['token'] === false);
});

it('answers only the four liveness fields of the canned health', function (): void {
    // Nothing the node keeps behind the token leaks through the tokenless read.
    Tmesis::fake()->withHealth($this->fixtureJson('responses/getHealth.json'));

    expect(array_keys(Tmesis::node()->getPublicHealth()->toArray()))
        ->toEqualCanonicalizing(['status', 'node', 'version', 'contract_version']);
});

it('records the tokenless read as getHealth with token false, and the full one with token true', function (): void {
    $fake = Tmesis::fake();

    Tmesis::node()->getPublicHealth();
    Tmesis::node()->getHealth();

    $fake->assertCalledCount('getHealth', 2)
        ->assertCalled('getHealth', fn (array $args): bool => $args['token'] === true)
        ->assertCalled('getHealth', fn (array $args): bool => $args['token'] === false);
});

it('downs the probe with throwOn getHealth, as a down node would', function (): void {
    Tmesis::fake()->throwOn('getHealth', new ConnectionException('node is down'));

    expect(fn () => Tmesis::node()->getPublicHealth())->toThrow(ConnectionException::class, 'node is down');
});
