<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\EnrollmentFacts;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Testing\ArrayNodeRepository;
use Illuminate\Support\Facades\Artisan;

/*
 * F2's tie to the installer bootstrap: `tmesis:enroll-node --fleet` mints a
 * join token bound to the enrolment's name and role into the user-data, which runs
 * `install.sh ... --no-start`, then `tmesis join`, then the live and job benches, then
 * `systemctl start tmesis tmesis-update.path` (the tmesis v0.9.0 order, handover 6.1).
 */

final class FleetUserDataFacts implements EnrollmentFacts
{
    public function values(NodeEnrollment $node): array
    {
        return [];
    }

    public function placeholders(NodeEnrollment $node): array
    {
        return [];
    }
}

const FLEET_USER_DATA = "#!/bin/sh\n"
    ."install.sh --role edge --no-start\n"
    ."tmesis join \"\${TMESIS_FLEET_ENROLL_URL}\" --token \"\${TMESIS_FLEET_JOIN_TOKEN}\"\n"
    ."systemd-run --wait --pipe --collect --quiet --unit=tmesis-bench --uid=tmesis --gid=tmesis /opt/tmesis/current/tmesis bench --profile live --store --if-live --timeout 80m --config /etc/tmesis/config.yml\n"
    ."tmesis bench --profile job\n"
    ."systemctl start tmesis tmesis-update.path\n";

function fleetTemplate(string $contents = FLEET_USER_DATA): string
{
    $path = sys_get_temp_dir().'/tmesis-fleet-'.bin2hex(random_bytes(4)).'.yml';
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function (): void {
    $this->withFleet();
    $this->repository = ArrayNodeRepository::fake();
    app()->instance(EnrollmentFacts::class, new FleetUserDataFacts);
});

it('renders the join token and the enroll URL into the user-data, and makes the token live', function (): void {
    $out = sys_get_temp_dir().'/tmesis-fleet-out-'.bin2hex(random_bytes(4)).'.sh';

    $code = Artisan::call('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => fleetTemplate(), '--mode' => 'user-data', '--fleet' => true, '--out' => $out]);
    $output = Artisan::output();

    expect($code)->toBe(0);

    $document = (string) file_get_contents($out);
    preg_match('/--token "(tmj_[A-Za-z0-9_-]+)"/', $document, $m);

    expect($m)->toHaveCount(2)
        ->and($document)->toContain('tmesis join "'.route('tmesis.fleet.enroll').'"')
        ->and($output)->toContain('fleet join token '.$m[1]);

    // The v0.9.0 order, printed: join, then the benches while stopped, then the start.
    $join = strpos($output, 'join (stopped)');
    $live = strpos($output, 'tmesis bench --profile live --store --if-live');
    $job = strpos($output, 'tmesis bench --profile job');
    $start = strpos($output, 'systemctl start tmesis tmesis-update.path');

    expect($join)->toBeInt()
        ->and($live)->toBeGreaterThan($join)
        ->and($job)->toBeGreaterThan($live)
        ->and($start)->toBeGreaterThan($job)
        ->and($output)->toContain('DEPLOY.md 6.5');

    $row = DB::table('tmesis_fleet_join_tokens')->first();
    expect($row->token_sha256)->toBe(hash('sha256', $m[1]))
        ->and($row->name)->toBe('ny.in.tsp24.com')
        ->and($row->role)->toBe('edge');
});

it('refuses --fleet when the fleet is not enabled, storing nothing', function (): void {
    // withFleet() set the flag the app is rebuilt from; turn it off for this rebuild.
    $this->fleetEnabled = false;
    $this->reloadApplication();
    $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    $repository = ArrayNodeRepository::fake();
    app()->instance(EnrollmentFacts::class, new FleetUserDataFacts);

    $code = Artisan::call('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => fleetTemplate(), '--mode' => 'user-data', '--fleet' => true]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('tmesis.fleet.enabled')
        ->and(DB::table('tmesis_fleet_join_tokens')->count())->toBe(0);

    $repository->assertNotEnrolled('ny.in.tsp24.com');
});

it('refuses a user-data template that names the join token when --fleet was not given', function (): void {
    $code = Artisan::call('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => fleetTemplate(), '--mode' => 'user-data']);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('--fleet')
        ->and(DB::table('tmesis_fleet_join_tokens')->count())->toBe(0);
});

it('leaves the join token out of a config render entirely', function (): void {
    $code = Artisan::call('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => fleetTemplate("node:\n  name: \"\"\n"), '--mode' => 'config', '--fleet' => true]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('  name: "ny.in.tsp24.com"')
        ->and(Artisan::output())->not->toContain('--token "tmj_');
});

it('puts a fresh, live join token into the install route\'s user-data when the template names one', function (): void {
    putenv('TMESIS_ENROLLMENT_ENABLED=true');
    $_ENV['TMESIS_ENROLLMENT_ENABLED'] = $_SERVER['TMESIS_ENROLLMENT_ENABLED'] = 'true';

    try {
        $this->reloadApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $dir = sys_get_temp_dir().'/tmesis-fleet-tpl-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/cloud-init.edge.yml", FLEET_USER_DATA);
        config()->set('tmesis.enrollment.templates', $dir);

        $repository = ArrayNodeRepository::fake();
        app()->instance(EnrollmentFacts::class, new FleetUserDataFacts);
        [$node, $bootstrap] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);
        $repository->enroll($node);

        $document = $this->get(route('tmesis.enroll.install'), ['Authorization' => 'Bearer '.$bootstrap->plain()])->assertOk()->getContent();

        preg_match('/--token "(tmj_[A-Za-z0-9_-]+)"/', (string) $document, $m);

        expect($m)->toHaveCount(2)
            ->and(DB::table('tmesis_fleet_join_tokens')->where('token_sha256', hash('sha256', $m[1]))->value('name'))->toBe('ny.in.tsp24.com');
    } finally {
        putenv('TMESIS_ENROLLMENT_ENABLED');
        unset($_ENV['TMESIS_ENROLLMENT_ENABLED'], $_SERVER['TMESIS_ENROLLMENT_ENABLED']);
    }
});
