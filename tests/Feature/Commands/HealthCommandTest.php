<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\ErrorBody;
use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Exceptions\ConnectionException;
use AlexHackney\Tmesis\Exceptions\UnavailableException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Health\NodeProbed;
use AlexHackney\Tmesis\Testing\ArrayNodeResolver;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('emits a reachable probe for a node that answers', function (): void {
    Event::fake([NodeProbed::class]);
    Tmesis::fake();

    $this->artisan('tmesis:health --emit')->assertSuccessful();

    Event::assertDispatchedTimes(NodeProbed::class, 1);
    Event::assertDispatched(NodeProbed::class, fn (NodeProbed $probe): bool => $probe->name === TestCase::NODE
        && $probe->reachable
        && $probe->health?->status() === 'ok'
        && $probe->errorClass === null
        && $probe->errorCode === null
        && $probe->elapsedMs >= 0.0
        && $probe->probedAt->getTimezone()->getName() === 'UTC');
});

it('emits an unreachable probe carrying the class and the code', function (): void {
    Event::fake([NodeProbed::class]);
    Tmesis::fake()->throwOn('getHealth', new UnavailableException(
        'draining',
        ErrorBody::fromArray(['code' => 'draining', 'message' => 'draining']),
        503,
    ));

    $this->artisan('tmesis:health --emit')->assertFailed();

    Event::assertDispatched(NodeProbed::class, fn (NodeProbed $probe): bool => $probe->name === TestCase::NODE
        && ! $probe->reachable
        && $probe->health === null
        && $probe->errorClass === UnavailableException::class
        && $probe->errorCode === 'draining');
});

it('emits a null code for a failure with no envelope', function (): void {
    Event::fake([NodeProbed::class]);
    Tmesis::fake()->throwOn('getHealth', new ConnectionException('connection refused'));

    $this->artisan('tmesis:health --emit')->assertFailed();

    Event::assertDispatched(NodeProbed::class, fn (NodeProbed $probe): bool => ! $probe->reachable
        && $probe->errorClass === ConnectionException::class
        && $probe->errorCode === null);
});

it('skips a receive-only node and emits nothing for it', function (): void {
    Event::fake([NodeProbed::class]);
    Tmesis::fake();
    ArrayNodeResolver::bind([new NodeConfig('edge-pull-01', '', null, 'secret')]);

    $this->artisan('tmesis:health --all --emit')
        ->expectsOutputToContain('Skipping [edge-pull-01]: no admin url.')
        ->assertSuccessful();

    Event::assertDispatchedTimes(NodeProbed::class, 1);
    Event::assertNotDispatched(NodeProbed::class, fn (NodeProbed $probe): bool => $probe->name === 'edge-pull-01');
});

it('emits one probe per callable node with --all', function (): void {
    Event::fake([NodeProbed::class]);
    Tmesis::fake();
    ArrayNodeResolver::bind([new NodeConfig('edge-dyn-01', 'http://10.0.0.9:7000', 'dyn-token', 'secret')]);

    $this->artisan('tmesis:health --all --emit')->assertSuccessful();

    Event::assertDispatchedTimes(NodeProbed::class, 2);
    Event::assertDispatched(NodeProbed::class, fn (NodeProbed $probe): bool => $probe->name === 'edge-dyn-01' && $probe->reachable);
});

it('does not emit a configuration failure as a down node', function (): void {
    Event::fake([NodeProbed::class]);

    $this->artisan('tmesis:health --node=nobody --emit')->assertFailed();

    Event::assertNotDispatched(NodeProbed::class);
});

it('dispatches nothing without --emit', function (): void {
    Event::fake([NodeProbed::class]);
    Tmesis::fake();

    $this->artisan('tmesis:health')->assertSuccessful();

    Tmesis::fake()->throwOn('getHealth', new ConnectionException('connection refused'));

    $this->artisan('tmesis:health')->assertFailed();

    Event::assertNotDispatched(NodeProbed::class);
});

it('does not probe the shipped default stub when a resolver supplies the nodes', function (): void {
    // a loopback url and no credential is the untouched config default.
    config()->set('tmesis.nodes.default', ['url' => 'http://127.0.0.1:7000', 'token' => null]);
    Event::fake([NodeProbed::class]);
    Tmesis::fake();
    ArrayNodeResolver::bind([]);

    $this->artisan('tmesis:health --all --emit')->assertSuccessful();

    Event::assertDispatchedTimes(NodeProbed::class, 1);
    Event::assertNotDispatched(NodeProbed::class, fn (NodeProbed $probe): bool => $probe->name === 'default');
});

/*
 * The tmesis v0.9.0 columns (handover 3.4 to 3.6): live capacity, the device
 * sessions, the jobs pause and both encoder pins. The row is one line of output, so
 * these read the whole output rather than one expectation per line.
 */

/**
 * Run tmesis:health against one health body and answer its exit code and output.
 *
 * @param  array<string, mixed>  $body
 * @return array{int, string}
 */
function healthCommandOutput(array $body): array
{
    Http::fake(['*' => Http::response($body)]);

    $code = Artisan::call('tmesis:health');

    return [$code, Artisan::output()];
}

it('shows the live capacity, the device sessions, the captions and both encoders', function (): void {
    [$code, $output] = healthCommandOutput($this->fixtureJson('responses/getHealth.json'));

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('on/measured 3/7 production')
        ->toContain(' 4/12 ')
        ->toContain('captions')
        ->toContain('live auto>h264_nvenc, jobs libx264>libx264')
        ->not->toContain('UNUSABLE');
});

it('shows a dash for the captions on a box with no self-hosted live captions', function (): void {
    // The fixture's live provider is deepgram, so it reports no captioned ceiling.
    [$code, $output] = healthCommandOutput($this->fixtureJson('responses/getHealth.json'));

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('captions')
        ->and($output)->toMatch('/\|\s+4\/12\s+\|\s+-\s+\|/');
});

it('shows the captioned slots used over the ceiling on a benched self-hosted box', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $body['transcription'][0]['provider'] = 'self_hosted';
    $body['live_capacity']['caption_sessions_total'] = 2;
    $body['live_capacity']['caption_sessions_used'] = 1;

    [$code, $output] = healthCommandOutput($body);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toMatch('/\|\s+4\/12\s+\|\s+1\/2\s+\|/');
});

it('shows admission off with no limit as information, not a failure', function (): void {
    [$code, $output] = healthCommandOutput([
        ...$this->fixtureJson('responses/getHealth.json'),
        'live_capacity' => [
            'admission' => 'off', 'source' => 'unmeasured', 'unit_ladder' => 'production',
            'encode_slots_total' => null, 'encode_slots_used' => 2,
            'device_sessions_total' => null, 'device_sessions_used' => 2, 'measured_at' => null,
        ],
    ]);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('off/unmeasured 2/- production')
        ->toContain(' 2/- ')
        ->not->toContain('not usable');
});

it('shows a transcoder as not applicable with no live encoder', function (): void {
    [$code, $output] = healthCommandOutput([
        ...$this->fixtureJson('responses/getHealth.json'),
        'node_role' => 'transcoder',
        'video_engine' => null,
        'live_capacity' => [
            'admission' => 'not_applicable', 'source' => 'not_applicable', 'unit_ladder' => '',
            'encode_slots_total' => null, 'encode_slots_used' => 0,
            'device_sessions_total' => null, 'device_sessions_used' => 0, 'measured_at' => null,
        ],
    ]);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain(' n/a ')
        ->toContain('live -, jobs libx264>libx264');
});

it('shows a jobs pause with its source and end', function (?string $until, string $shown): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $body['jobs']['paused'] = ['until' => $until, 'reason' => 'show: fight night 12', 'source' => 'fleet'];

    [$code, $output] = healthCommandOutput($body);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain($shown);
})->with([
    'with an end' => ['2026-08-28T19:00:00Z', 'fleet until 2026-08-28T19:00:00Z'],
    'until cleared' => [null, 'fleet until cleared'],
]);

it('shows a node with jobs off', function (): void {
    [$code, $output] = healthCommandOutput([
        ...$this->fixtureJson('responses/getHealth.json'),
        'jobs' => null,
        'capacity' => null,
        'jobs_video_engine' => null,
    ]);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('no jobs')
        ->toContain('live auto>h264_nvenc, jobs -');
});

it('warns about a pinned encoder that failed at boot', function (string $field, string $reason, string $workload): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $body['status'] = 'degraded';
    $body['degraded_reasons'] = [$reason];
    $body[$field]['encoder'] = 'h264_qsv';
    $body[$field]['requested'] = 'h264_qsv';
    $body[$field]['usable'] = false;
    $body[$field]['reason'] = 'no /dev/dri render node';

    [$code, $output] = healthCommandOutput($body);

    // Degraded is not unhealthy: the exit code is the same as before v0.9.0.
    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain("{$workload} h264_qsv>h264_qsv UNUSABLE")
        ->toContain(" {$reason} ")
        ->toContain("[origin-atl-01] the pinned {$workload} encoder h264_qsv is not usable: no /dev/dri render node");
})->with([
    'live' => ['video_engine', 'encoder_unavailable', 'live'],
    'jobs' => ['jobs_video_engine', 'jobs_encoder_unavailable', 'jobs'],
]);

it('leaves the colon off an unusable pin with no reason', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $body['video_engine']['encoder'] = 'h264_qsv';
    $body['video_engine']['requested'] = 'h264_qsv';
    $body['video_engine']['usable'] = false;
    $body['video_engine']['reason'] = '';

    [, $output] = healthCommandOutput($body);

    expect($output)->toContain('[origin-atl-01] the pinned live encoder h264_qsv is not usable')
        ->not->toContain('is not usable:');
});

it('warns for unavailable transcription without changing command success', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $body['transcription'][0]['unavailable'] = true;
    $body['transcription'][0]['detail'] = 'provider timeout';

    [$code, $output] = healthCommandOutput($body);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('[origin-atl-01] the live transcription provider deepgram is unavailable: provider timeout');
});

it('does not warn for transcription that is down but not unavailable', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $body['transcription'][0]['state'] = 'down';
    $body['transcription'][0]['unavailable'] = false;

    [$code, $output] = healthCommandOutput($body);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->not->toContain('transcription provider');
});

it('shows a dash for a body with no live capacity', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    unset($body['live_capacity']);

    [$code, $output] = healthCommandOutput($body);

    expect($code)->toBe(Command::SUCCESS)
        ->and($output)->toContain('origin-atl-01')
        ->not->toContain('on/measured')
        ->not->toContain(' 0/- ');
});

it('keeps the error row as wide as the table', function (): void {
    Tmesis::fake()->throwOn('getHealth', new ConnectionException('connection refused'));

    $code = Artisan::call('tmesis:health');

    expect($code)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('connection refused')
        ->toContain('encoders');
});

it('warns for a down fallback provider while keeping command success', function (): void {
    $body = $this->fixtureJson('responses/getHealth.json');
    $body['transcription'][] = ['role' => 'fallback', 'provider' => 'self_hosted', 'endpoint' => 'http://localhost:9000', 'state' => 'down', 'since' => null, 'unavailable' => true, 'detail' => 'connection refused'];
    [$code, $output] = healthCommandOutput($body);
    expect($code)->toBe(Command::SUCCESS)->and($output)->toContain('the fallback transcription provider self_hosted is unavailable');
});
