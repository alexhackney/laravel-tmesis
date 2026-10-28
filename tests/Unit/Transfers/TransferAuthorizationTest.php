<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\AuthorizationBundle;
use AlexHackney\Tmesis\Data\Artifacts\PartAuthorization;
use AlexHackney\Tmesis\Data\Artifacts\SignedRequest;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Transfers\TransferAuthorization;

function xferSigned(string $method = 'PUT', string $marker = 'x'): SignedRequest
{
    return SignedRequest::make(
        $method,
        'https://archive.example.net/backup/k?X-Amz-Signature=secret-'.$marker,
        ['x-amz-security-token' => 'token-'.$marker],
        '2026-09-22T22:30:00.000Z',
    );
}

/**
 * @return list<PartAuthorization>
 */
function xferParts(int $from, int $to): array
{
    return array_map(static fn (int $n): PartAuthorization => PartAuthorization::make($n, xferSigned('PUT', 'p'.$n)), range($from, $to));
}

it('is one bundle when there are no parts', function (): void {
    $answer = TransferAuthorization::make(head: xferSigned('HEAD'));

    $bundles = $answer->bundles();

    expect($bundles)->toHaveCount(1)
        ->and($bundles[0]->supplied())->toBe(['head'])
        ->and($bundles[0]->parts())->toBeNull()
        ->and($answer->supplied())->toBe(['head'])
        ->and($answer->partNumbers())->toBe([]);
});

it('splits parts at the node\'s cap, every other operation in the first refresh', function (): void {
    $answer = TransferAuthorization::make(parts: xferParts(1, 300), abort: xferSigned('DELETE'));

    $bundles = $answer->bundles();

    expect($bundles)->toHaveCount(3)
        ->and(count($bundles[0]->parts() ?? []))->toBe(AuthorizationBundle::MAX_PARTS)
        ->and($bundles[0]->abort())->not->toBeNull()
        ->and($bundles[0]->parts()[0]->partNumber())->toBe(1)
        ->and(count($bundles[1]->parts() ?? []))->toBe(128)
        ->and($bundles[1]->supplied())->toBe(['parts'])
        ->and($bundles[1]->parts()[0]->partNumber())->toBe(129)
        ->and(count($bundles[2]->parts() ?? []))->toBe(44)
        ->and($bundles[2]->abort())->toBeNull();
});

it('keeps exactly 128 parts in one refresh', function (): void {
    expect(TransferAuthorization::make(parts: xferParts(1, 128))->bundles())->toHaveCount(1);
});

it('orders parts by number whatever order the signer gave', function (): void {
    $answer = TransferAuthorization::make(parts: [...xferParts(5, 6), ...xferParts(1, 2)]);

    expect($answer->partNumbers())->toBe([1, 2, 5, 6]);
});

it('refuses a part signed twice', function (): void {
    TransferAuthorization::make(parts: [...xferParts(1, 2), ...xferParts(2, 3)]);
})->throws(InvalidConfigurationException::class, 'part 2 is signed twice');

it('is empty with nothing supplied, and has no refresh to send', function (): void {
    $answer = TransferAuthorization::make(parts: []);

    expect($answer->isEmpty())->toBeTrue()
        ->and($answer->bundles())->toBe([]);
});

it('never prints a URL or a header value when dumped', function (): void {
    $answer = TransferAuthorization::make(put: xferSigned('PUT', 'put'), parts: xferParts(1, 2));

    ob_start();
    var_dump($answer);
    $dumped = (string) ob_get_clean().print_r($answer, true);

    expect($dumped)->not->toContain('secret-')
        ->and($dumped)->not->toContain('token-')
        ->and($dumped)->toContain('https://archive.example.net');
});
