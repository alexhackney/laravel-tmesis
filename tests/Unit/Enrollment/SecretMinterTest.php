<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\BootstrapToken;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('mints 32 random bytes as unpadded base64url', function (): void {
    $minter = app(SecretMinter::class);
    $secret = $minter->secret();

    expect($secret)->toHaveLength(43)
        ->and($secret)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(strlen((string) base64_decode(strtr($secret, '-_', '+/').'=', true)))->toBe(32)
        ->and($minter->secret())->not->toBe($secret);
});

it('mints a bootstrap token expiring after the default 15 minutes', function (): void {
    Carbon::setTestNow('2026-09-27T12:00:00Z');

    $token = app(SecretMinter::class)->bootstrapToken();

    expect($token)->toBeInstanceOf(BootstrapToken::class)
        ->and($token->plain())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($token->sha256())->toBe(hash('sha256', $token->plain()))
        ->and($token->sha256())->toMatch('/^[0-9a-f]{64}$/')
        ->and($token->expiresAt->format(DATE_RFC3339))->toBe('2026-09-27T12:15:00+00:00');
});

it('reads the ttl from config', function (): void {
    Carbon::setTestNow('2026-09-27T12:00:00Z');
    config()->set('tmesis.enrollment.bootstrap_ttl_minutes', 60);

    expect(app(SecretMinter::class)->bootstrapToken()->expiresAt->format(DATE_RFC3339))->toBe('2026-09-27T13:00:00+00:00');
});

it('treats a blank ttl as the default', function (): void {
    config()->set('tmesis.enrollment.bootstrap_ttl_minutes', '');

    expect(app(SecretMinter::class)->ttlMinutes())->toBe(15);
});

it('refuses a ttl below a minute, naming the key', function (mixed $ttl): void {
    config()->set('tmesis.enrollment.bootstrap_ttl_minutes', $ttl);

    app(SecretMinter::class)->ttlMinutes();
})->with([0, -5, 'soon'])->throws(InvalidConfigurationException::class, 'tmesis.enrollment.bootstrap_ttl_minutes');

it('mints an enrolment whose hook secret is the webhook secret by default', function (): void {
    Carbon::setTestNow('2026-09-27T12:00:00Z');

    [$node, $token] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge, 'ny', 'vc2-2c-4gb');

    expect($node->name)->toBe('ny.in.tsp24.com')
        ->and($node->role)->toBe(NodeRole::Edge)
        ->and($node->region)->toBe('ny')
        ->and($node->plan)->toBe('vc2-2c-4gb')
        ->and($node->hookSecret())->toBe($node->webhookSecret())
        ->and($node->hasDistinctHookSecret())->toBeFalse()
        ->and($node->keytermsHookSecret())->toBe($node->webhookSecret())
        ->and($node->hasDistinctKeytermsHookSecret())->toBeFalse()
        ->and($node->adminToken())->not->toBe($node->webhookSecret())
        ->and($node->bootstrapTokenSha256)->toBe($token->sha256())
        ->and($node->bootstrapExpiresAt)->toEqual($token->expiresAt)
        ->and($node->enrolledAt->format(DATE_RFC3339))->toBe('2026-09-27T12:00:00+00:00');
});

it('mints a distinct hook secret on request', function (): void {
    [$node] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge, distinctHookSecret: true);

    expect($node->hasDistinctHookSecret())->toBeTrue()
        ->and($node->hookSecret())->toMatch('/^[A-Za-z0-9_-]{43}$/');
});

it('mints a distinct keyterms hook secret on request, on its own switch', function (): void {
    [$node] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Origin, distinctKeytermsHookSecret: true);

    expect($node->hasDistinctKeytermsHookSecret())->toBeTrue()
        ->and($node->hasDistinctHookSecret())->toBeFalse()
        ->and($node->keytermsHookSecret())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($node->keytermsHookSecret())->not->toBe($node->adminToken());
});
