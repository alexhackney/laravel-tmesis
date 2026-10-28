<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * The doctor's `--live` encoder pin rows (tmesis v0.9.0, handover 3.4): a pin that
 * failed at boot is a WARN naming the pin and its requested encoder, never a FAIL.
 */

/**
 * Run tmesis:doctor --live against one health body and answer its exit code and
 * output.
 *
 * @param  array<string, mixed>  $health
 * @return array{int, string}
 */
function doctorLiveWithHealth(array $health): array
{
    Http::fake(['*' => Http::response($health)]);

    $code = Artisan::call('tmesis:doctor --live');

    return [$code, Artisan::output()];
}

/**
 * The fixture with one encoder block's pin failed.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function withFailedPin(array $body, string $field, string $requested): array
{
    $body[$field]['encoder'] = $requested;
    $body[$field]['requested'] = $requested;
    $body[$field]['usable'] = false;
    $body[$field]['reason'] = 'no /dev/dri render node';

    return $body;
}

it('prints no encoder row when both encoders are usable', function (): void {
    [$code, $output] = doctorLiveWithHealth($this->fixtureJson('responses/getHealth.json'));

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('live '.TestCase::NODE)
        ->not->toContain('encoder '.TestCase::NODE);
});

it('warns about a live pin that failed at boot', function (): void {
    [$code, $output] = doctorLiveWithHealth(withFailedPin($this->fixtureJson('responses/getHealth.json'), 'video_engine', 'h264_qsv'));

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toMatch('/warn encoder '.preg_quote(TestCase::NODE, '/').'\s+'
            .preg_quote('[live encode.video_encoder] pinned to h264_qsv is not usable: no /dev/dri render node', '/').'/')
        ->not->toContain('jobs.video_encoder')
        ->not->toMatch('/fail encoder/');
});

it('warns about a jobs pin that failed at boot', function (): void {
    [$code, $output] = doctorLiveWithHealth(withFailedPin($this->fixtureJson('responses/getHealth.json'), 'jobs_video_engine', 'h264_nvenc'));

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toMatch('/warn encoder '.preg_quote(TestCase::NODE, '/').'\s+'
            .preg_quote('[jobs jobs.video_encoder] pinned to h264_nvenc is not usable: no /dev/dri render node', '/').'/')
        ->not->toContain('encode.video_encoder]');
});

it('warns once per failed pin when both failed', function (): void {
    $body = withFailedPin($this->fixtureJson('responses/getHealth.json'), 'video_engine', 'h264_qsv');

    [, $output] = doctorLiveWithHealth(withFailedPin($body, 'jobs_video_engine', 'h264_qsv'));

    expect(substr_count($output, 'warn encoder '.TestCase::NODE))->toBe(2);
});

it('leaves the reason off when the node gave none', function (): void {
    $body = withFailedPin($this->fixtureJson('responses/getHealth.json'), 'video_engine', 'libx264');
    $body['video_engine']['reason'] = '';

    [, $output] = doctorLiveWithHealth($body);

    expect($output)->toMatch('/\[live encode\.video_encoder\] pinned to libx264 is not usable\s*$/m');
});

it('prints no encoder row when neither encoder is reported', function (): void {
    [$code, $output] = doctorLiveWithHealth([
        ...$this->fixtureJson('responses/getHealth.json'),
        'video_engine' => null,
        'jobs_video_engine' => null,
    ]);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('live '.TestCase::NODE)
        ->not->toContain('encoder '.TestCase::NODE);
});

it('prints no encoder row for a node that did not answer', function (): void {
    Http::fake(['*' => Http::response(['error' => [
        'code' => 'draining', 'message' => 'draining', 'details' => null,
        'request_id' => 'r', 'milestone' => null,
    ]], 503)]);

    Artisan::call('tmesis:doctor --live');

    expect(Artisan::output())->not->toContain('encoder '.TestCase::NODE);
});
