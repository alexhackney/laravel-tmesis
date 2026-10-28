<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\Transport;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * `tmesis.http.verify`: the resolution table, its two refusals, and that the option
 * reaches every admin call, the tokenless probe included.
 */
function verifyBundle(): string
{
    $path = tempnam(sys_get_temp_dir(), 'tmesis-ca-');
    file_put_contents((string) $path, "-----BEGIN CERTIFICATE-----\n-----END CERTIFICATE-----\n");

    return (string) $path;
}

it('resolves a verify value to the Guzzle option', function (mixed $configured, string $environment, bool $expected): void {
    expect(Transport::resolveVerify($configured, $environment))->toBe($expected);
})->with([
    'true' => [true, 'production', true],
    'the string true' => ['true', 'production', true],
    'null, the default' => [null, 'production', true],
    'blank, a Doppler absent key' => ['', 'production', true],
    'false in local' => [false, 'local', false],
    'false in testing' => [false, 'testing', false],
    'the string false in testing' => ['false', 'testing', false],
]);

it('answers a readable absolute CA bundle path as itself', function (): void {
    $bundle = verifyBundle();

    expect(Transport::resolveVerify($bundle, 'production'))->toBe($bundle);

    unlink($bundle);
});

it('refuses false outside local and testing', function (mixed $configured, string $environment): void {
    expect(fn () => Transport::resolveVerify($configured, $environment))->toThrow(
        InvalidConfigurationException::class,
        'a control plane must not send a bearer to an unverified peer',
    );
})->with([
    'false in production' => [false, 'production'],
    'the string false in staging' => ['false', 'staging'],
]);

it('refuses a path that is not an absolute readable file, naming it', function (string $path): void {
    expect(fn () => Transport::resolveVerify($path, 'production'))->toThrow(
        InvalidConfigurationException::class,
        "[{$path}] is not an absolute path to a readable CA bundle file",
    );
})->with([
    'missing' => ['/nonexistent/tmesis-ca.pem'],
    'relative' => ['certs/tmesis-ca.pem'],
    'a directory' => ['/'],
]);

it('refuses a value of the wrong type', function (): void {
    expect(fn () => Transport::resolveVerify(1, 'production'))->toThrow(
        InvalidConfigurationException::class,
        'must be true, false, or an absolute path to a CA bundle',
    );
});

it('passes verify on every admin call', function (mixed $configured, bool|string $expected): void {
    config()->set('tmesis.http.verify', $configured);

    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = $options['verify'] ?? 'unset';

        return Http::response(['status' => 'ok', 'node' => 'n', 'version' => '1', 'contract_version' => 1]);
    });

    Tmesis::node()->getPublicHealth();

    expect($seen)->toBe([$expected]);
})->with([
    'default' => [true, true],
    'off in testing' => [false, false],
]);

it('passes a bundle path as verify, the tokenless probe included', function (): void {
    $bundle = verifyBundle();
    config()->set('tmesis.http.verify', $bundle);

    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = [$options['verify'] ?? 'unset', $request->hasHeader('Authorization')];

        return Http::response(['status' => 'ok', 'node' => 'n', 'version' => '1', 'contract_version' => 1]);
    });

    Tmesis::node()->getPublicHealth();

    expect($seen)->toBe([[$bundle, false]]);

    unlink($bundle);
});

it('refuses to send at all when verify is refused', function (): void {
    config()->set('tmesis.http.verify', '/nonexistent/tmesis-ca.pem');
    Http::fake();

    expect(fn () => Tmesis::node()->getPublicHealth())->toThrow(InvalidConfigurationException::class);

    Http::assertNothingSent();
});
