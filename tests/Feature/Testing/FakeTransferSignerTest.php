<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\ObjectStatus;
use AlexHackney\Tmesis\Data\Artifacts\PartStatus;
use AlexHackney\Tmesis\Data\Artifacts\PlanTargetStatus;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Events\TransferAuthorizationRequired;
use AlexHackney\Tmesis\Testing\FakeTransferSigner;
use AlexHackney\Tmesis\Tests\Doubles\TransferNode;
use AlexHackney\Tmesis\Transfers\RefreshTransferAuthorization;
use AlexHackney\Tmesis\Transfers\TransferAuthorization;
use AlexHackney\Tmesis\Transfers\TransferAuthorizationNeed;
use AlexHackney\Tmesis\Transfers\TransferSigner;
use PHPUnit\Framework\AssertionFailedError;

function fakeSignerNeed(TransferNode $node): TransferAuthorizationNeed
{
    return TransferAuthorizationNeed::make(
        'edge-venue-07',
        $node->toEvent(),
        PlanTargetStatus::fromArray($node->plan['targets'][1]),
        ObjectStatus::fromArray($node->object),
        array_map(static fn (array $part): PartStatus => PartStatus::fromArray($part), $node->parts),
    );
}

function fakeSignerSecret(): TransferAuthorization
{
    return TransferAuthorization::make(put: SignedRequest::make(
        'PUT',
        'https://archive.example.net/backup/k?X-Amz-Signature=fake-signer-secret',
        ['x-amz-security-token' => 'fake-signer-token'],
        '2026-09-22T22:30:00.000Z',
    ));
}

it('binds itself and makes the refresh listener listen', function (): void {
    expect(RefreshTransferAuthorization::signerConfigured(app()))->toBeFalse();

    $fake = FakeTransferSigner::fake();

    expect(app(TransferSigner::class))->toBe($fake)
        ->and(app('events')->getRawListeners()[TransferAuthorizationRequired::class])
        ->toBe([RefreshTransferAuthorization::class]);

    FakeTransferSigner::fake();

    expect(app('events')->getRawListeners()[TransferAuthorizationRequired::class])->toHaveCount(1);
});

it('does not register a second listener when the config already did', function (): void {
    $this->withTransferSigner(FakeTransferSigner::class);

    FakeTransferSigner::fake();

    expect(app('events')->getRawListeners()[TransferAuthorizationRequired::class])->toHaveCount(1);
});

it('records every need and answers null by default', function (): void {
    $fake = new FakeTransferSigner;
    $need = fakeSignerNeed(TransferNode::put());

    expect($fake->sign($need))->toBeNull()
        ->and($fake->needs())->toBe([$need]);

    $fake->assertSigned()
        ->assertSignedObject(TransferNode::OBJECT)
        ->assertSignedCount(1)
        ->assertSigned(fn (TransferAuthorizationNeed $n): bool => $n->requires('put'));
});

it('answers a fixed authorization or a closure over the need', function (): void {
    $fixed = fakeSignerSecret();
    $fake = (new FakeTransferSigner)->answerWith($fixed);

    expect($fake->sign(fakeSignerNeed(TransferNode::put())))->toBe($fixed);

    $fake->answerWith(fn (TransferAuthorizationNeed $need): ?TransferAuthorization => $need->requiresHead() ? $fixed : null);

    expect($fake->sign(fakeSignerNeed(TransferNode::put())))->toBeNull()
        ->and($fake->sign(fakeSignerNeed(TransferNode::probe())))->toBe($fixed);
});

it('signs everything the need asks for with placeholder requests', function (): void {
    $fake = (new FakeTransferSigner)->signEverything();

    $multipart = $fake->sign(fakeSignerNeed(TransferNode::multipart(parts: 4, complete: 1)));
    $probe = $fake->sign(fakeSignerNeed(TransferNode::probe()));
    $put = $fake->sign(fakeSignerNeed(TransferNode::put()));

    expect($multipart?->supplied())->toBe(['parts', 'abort'])
        ->and($multipart?->partNumbers())->toBe([2, 3, 4])
        ->and($multipart?->abort()?->method())->toBe('DELETE')
        ->and($probe?->supplied())->toBe(['head'])
        ->and($probe?->head()?->method())->toBe('HEAD')
        ->and($put?->supplied())->toBe(['put'])
        ->and($put?->put()?->redactedUrl())->toBe('https://fake-signer.invalid');

    $fake->assertSignedHead()
        ->assertSignedHead(TransferNode::OBJECT)
        ->assertSignedParts(TransferNode::OBJECT, [2, 3, 4])
        ->assertSignedCount(3);
});

it('fails its assertions with messages that name no URL or header value', function (Closure $assert, string $expected): void {
    $fake = (new FakeTransferSigner)->answerWith(fakeSignerSecret());
    $fake->sign(fakeSignerNeed(TransferNode::put()));

    try {
        $assert($fake);
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain($expected)
            ->and($e->getMessage())->not->toContain('fake-signer-secret')
            ->and($e->getMessage())->not->toContain('fake-signer-token');

        return;
    }

    $this->fail('Expected the assertion to fail.');
})->with([
    'assertNothingSigned' => [fn (FakeTransferSigner $f) => $f->assertNothingSigned(), 'not to be asked'],
    'assertSignedCount' => [fn (FakeTransferSigner $f) => $f->assertSignedCount(2), 'asked 1 times'],
    'assertSignedHead' => [fn (FakeTransferSigner $f) => $f->assertSignedHead(), 'not asked for a HEAD'],
    'assertSignedObject' => [fn (FakeTransferSigner $f) => $f->assertSignedObject('other'), 'object [other]'],
    'assertSigned' => [fn (FakeTransferSigner $f) => $f->assertSigned(fn (): bool => false), 'matching need'],
    'assertSignedParts' => [fn (FakeTransferSigner $f) => $f->assertSignedParts(TransferNode::OBJECT, [1]), 'parts signed'],
]);

it('records nothing when the listener never asks', function (): void {
    FakeTransferSigner::fake()->assertNothingSigned();
});
