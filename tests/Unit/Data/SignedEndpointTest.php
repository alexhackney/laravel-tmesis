<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Artifacts\MasterRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanRequest;
use AlexHackney\Tmesis\Data\Artifacts\PlanTarget;
use AlexHackney\Tmesis\Data\Artifacts\SignedDestination;
use AlexHackney\Tmesis\Data\Jobs\JobSignedTarget;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Facades\Tmesis;

/*
 * A signed endpoint is refused on the node's grammar (internal/objectkey EndpointProblem,
 * the same sentence on the plan, master and job routes) BEFORE it can reach a request, a
 * fake's call log or an exception. Review finding 1 (2026-10-06): the builders took any
 * string, so a URL with a password or a presigned signature was recorded by the fakes.
 */

const SECRET_ENDPOINT = 'https://user:PASSWORD@s3.example/?X-Amz-Signature=SECRET';

it('refuses what the node refuses, with its sentence and never the value', function (string $endpoint, string $shape): void {
    try {
        $shape === 'job' ? JobSignedTarget::make('us-east-1', 'vod', $endpoint) : SignedDestination::make('us-east-1', 'vod', $endpoint);
        $this->fail("[{$endpoint}] was accepted.");
    } catch (InvalidConfigurationException $e) {
        expect($e->getMessage())->toContain('must be an absolute http or https URL without userinfo, query or fragment')
            ->and($e->getMessage())->not->toContain('PASSWORD')
            ->and($e->getMessage())->not->toContain('SECRET')
            ->and($e->getMessage())->not->toContain('s3.example');
    }
})->with([
    'userinfo and a query' => SECRET_ENDPOINT,
    'empty userinfo' => 'https://@s3.example',
    'a bare ?' => 'https://s3.example?',
    'a bare #' => 'https://s3.example#',
    'a fragment' => 'https://s3.example/#x',
    'no scheme' => 's3.example',
    'not http' => 'ftp://s3.example',
    'no host' => 'https://',
    'empty' => '',
])->with(['plan and master' => 'signed', 'job' => 'job']);

it('takes what the node takes', function (string $endpoint): void {
    expect(SignedDestination::make('us-east-1', 'vod', $endpoint)->endpoint())->toBe($endpoint)
        ->and(JobSignedTarget::make('us-east-1', 'vod', $endpoint)->endpoint())->toBe($endpoint);
})->with(['https://s3.example', 'http://minio:9000', 'https://s3.example/base', 'HTTPS://S3.EXAMPLE', 'https://s3.example/a@b']);

it('records nothing on the fake for a master or a plan with a credential-bearing endpoint', function (): void {
    $fake = Tmesis::fake();

    expect(fn () => Tmesis::node()->artifacts()->createMaster('show-2041-main', MasterRequest::make(
        ['01J8ZCK5R7V2QNX8RW4TKB6PD2'],
        PlanTarget::recordingMaster(SignedDestination::make('us-east-1', 'vod', SECRET_ENDPOINT), 'k.mp4'),
    )))->toThrow(InvalidConfigurationException::class)
        ->and(fn () => Tmesis::node()->artifacts()->replaceTransferPlan('show-2041-main', '01J8ZCK5R7V2QNX8RW4TKB6PD2', PlanRequest::make(str_repeat('ab', 32), 0)
            ->withTarget(PlanTarget::hlsSealed(SignedDestination::make('us-east-1', 'vod', SECRET_ENDPOINT), 'p'))))
        ->toThrow(InvalidConfigurationException::class);

    $fake->assertNothingCalled();
    expect(json_encode($fake->calls()))->not->toContain('PASSWORD');
});
