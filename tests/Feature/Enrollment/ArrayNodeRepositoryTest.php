<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\NodeRegistration;
use AlexHackney\Tmesis\Enrollment\NodeRepository;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use AlexHackney\Tmesis\Testing\ArrayNodeRepository;
use PHPUnit\Framework\AssertionFailedError;

function registration(): NodeRegistration
{
    return new NodeRegistration('v0.6.0', 'https://ny.in.tsp24.com:7443', new DateTimeImmutable);
}

it('binds itself', function (): void {
    $repository = ArrayNodeRepository::fake();

    expect(app(NodeRepository::class))->toBe($repository);
});

it('keeps an enrolment pending until it registers, then burns the token', function (): void {
    $repository = new ArrayNodeRepository;
    [$node] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);

    $repository->assertNotEnrolled('ny.in.tsp24.com');
    $repository->enroll($node);

    $repository->assertEnrolled('ny.in.tsp24.com')->assertNotRegistered('ny.in.tsp24.com');
    expect($repository->enrolled('ny.in.tsp24.com'))->toBe($node)
        ->and($repository->enrolled('other'))->toBeNull()
        ->and($repository->pending($node->bootstrapTokenSha256))->toBe($node)
        ->and($repository->pending(hash('sha256', 'nope')))->toBeNull();

    expect($repository->register($node->bootstrapTokenSha256, $registration = registration()))->toBeTrue();

    $repository->assertRegistered('ny.in.tsp24.com');
    expect($repository->pending($node->bootstrapTokenSha256))->toBeNull()
        ->and($repository->registration('ny.in.tsp24.com'))->toBe($registration);
});

it('refuses an existing name without replace', function (): void {
    $repository = new ArrayNodeRepository;
    $repository->enroll(app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge)[0]);

    $repository->enroll(app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge)[0]);
})->throws(EnrollmentException::class, 'already enrolled');

it('replaces, dropping the old token and registration', function (): void {
    $repository = new ArrayNodeRepository;
    [$old] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);
    [$new] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);

    $repository->enroll($old);
    $repository->register($old->bootstrapTokenSha256, registration());
    $repository->enroll($new, replace: true);

    $repository->assertNotRegistered('ny.in.tsp24.com');
    expect($repository->enrolled('ny.in.tsp24.com'))->toBe($new)
        ->and($repository->pending($old->bootstrapTokenSha256))->toBeNull()
        ->and($repository->pending($new->bootstrapTokenSha256))->toBe($new);
});

it('answers false and writes nothing for a token no pending enrolment holds', function (): void {
    $repository = new ArrayNodeRepository;
    [$node] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);
    $repository->enroll($node);

    expect($repository->register(hash('sha256', 'never-minted'), registration()))->toBeFalse();
    $repository->assertNotRegistered('ny.in.tsp24.com');

    // Already registered: the token is burned, so a second use is false too.
    expect($repository->register($node->bootstrapTokenSha256, registration()))->toBeTrue()
        ->and($repository->register($node->bootstrapTokenSha256, registration()))->toBeFalse();
});

it('never registers a replacement enrolment with the token of the one it replaced', function (): void {
    $repository = new ArrayNodeRepository;
    [$old] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);
    [$new] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);

    $repository->enroll($old);
    $repository->enroll($new, replace: true);

    expect($repository->register($old->bootstrapTokenSha256, registration()))->toBeFalse()
        ->and($repository->pending($new->bootstrapTokenSha256))->toBe($new);
    $repository->assertNotRegistered('ny.in.tsp24.com');
});

it('fails its assertions naming the node', function (): void {
    expect(fn () => (new ArrayNodeRepository)->assertEnrolled('ghost'))->toThrow(AssertionFailedError::class, '[ghost] was not enrolled')
        ->and(fn () => (new ArrayNodeRepository)->assertRegistered('ghost'))->toThrow(AssertionFailedError::class, '[ghost] did not register');
});
