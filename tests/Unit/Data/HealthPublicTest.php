<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\HealthPublic;

it('reads the four fields of the liveness-only body', function (): void {
    // The docs/API.md 9.1 fence: what the route answers to a request with no bearer.
    $health = HealthPublic::fromArray([
        'status' => 'ok',
        'node' => 'origin-atl-01',
        'version' => '1.4.0',
        'contract_version' => 1,
    ]);

    expect($health->status())->toBe(HealthPublic::STATUS_OK)
        ->and($health->node())->toBe('origin-atl-01')
        ->and($health->version())->toBe('1.4.0')
        ->and($health->contractVersion())->toBe(1);
});

it('is the four-field subset of the full body and tolerates the rest', function (): void {
    // A consumer that probes without a token and a consumer that reads HealthFull see
    // the same four values; the other fourteen fields of the full fixture are ignored,
    // not refused, and come back out of toArray() untouched.
    $full = $this->fixtureJson('responses/getHealth.json');
    $health = HealthPublic::fromArray($full);

    expect($health->status())->toBe($full['status'])
        ->and($health->node())->toBe($full['node'])
        ->and($health->version())->toBe($full['version'])
        ->and($health->contractVersion())->toBe($full['contract_version'])
        ->and($health->toArray())->toBe($full);
});

it('answers empty strings and zero for a body with nothing in it', function (): void {
    $health = HealthPublic::fromArray([]);

    expect($health->status())->toBe('')
        ->and($health->node())->toBe('')
        ->and($health->version())->toBe('')
        ->and($health->contractVersion())->toBe(0);
});
