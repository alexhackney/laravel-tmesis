<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Hooks\HookDecision;
use AlexHackney\Tmesis\Data\Hooks\HookRequest;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

pest()->group('schemas-events');

it('round-trips the publish.verify request against its schema', function (): void {
    $json = (string) file_get_contents(ContractDir::example('publish.verify'));
    $validator = SchemaValidator::forSchema('publish.verify');

    $validator->assertValid(json_decode($json, false), context: 'The publish.verify fixture');

    /** @var array<string, mixed> $payload */
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $request = HookRequest::fromArray($payload);

    $validator->assertValid(
        json_decode((string) json_encode($request->toArray()), false),
        context: 'The publish.verify round trip'
    );

    expect($request->event())->toBe('publish.verify')
        ->and($request->stream())->toBe('show-2041-main')
        ->and($request->keyEpoch()->value)->toBe(7);
});

it('always renders allow in a decision', function (): void {
    // An omitted allow is a DENY at the node, so the package never omits it.
    expect(HookDecision::allow(false)->toArray())->toBe(['allow' => false])
        ->and(HookDecision::deny()->toArray())->toBe(['allow' => false])
        ->and(HookDecision::allow()->toArray())->toBe(['allow' => true])
        ->and(HookDecision::deny('epoch below min_key_epoch')->toArray())
        ->toBe(['allow' => false, 'reason' => 'epoch below min_key_epoch'])
        ->and(HookDecision::allow(true, null, ['ladder' => 'broadcast-720p'])->toArray())
        ->toBe(['allow' => true, 'patch' => ['ladder' => 'broadcast-720p']]);
});
