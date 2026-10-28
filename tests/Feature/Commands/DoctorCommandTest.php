<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Node\NodeConfig;
use AlexHackney\Tmesis\Enrollment\EnrollmentFacts;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Testing\ArrayNodeRepository;
use AlexHackney\Tmesis\Testing\ArrayNodeResolver;
use AlexHackney\Tmesis\Tests\Doubles\ArrayKeytermsProvider;
use AlexHackney\Tmesis\Tests\Doubles\NotADecider;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The doctor's `http.verify`, `scheme`, `enrollment` and `default node` rows.
 */
const DOCTOR_ENROLMENT_ENV = 'TMESIS_ENROLLMENT_ENABLED';

afterEach(function (): void {
    putenv(DOCTOR_ENROLMENT_ENV);
    unset($_ENV[DOCTOR_ENROLMENT_ENV], $_SERVER[DOCTOR_ENROLMENT_ENV]);

    // The suite leaves nothing in the temp directory.
    foreach (DoctorTemplateDirs::$made as $dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    DoctorTemplateDirs::$made = [];
});

final class DoctorTemplateDirs
{
    /** @var list<string> */
    public static array $made = [];
}

/**
 * The routes load in boot(), so the env is set and the app rebuilt.
 */
function doctorWithEnrollmentRoutes(object $test): void
{
    putenv(DOCTOR_ENROLMENT_ENV.'=true');
    $_ENV[DOCTOR_ENROLMENT_ENV] = $_SERVER[DOCTOR_ENROLMENT_ENV] = 'true';

    (fn () => $this->reloadApplication())->call($test);
}

function doctorTemplatesDir(array $files): string
{
    $dir = sys_get_temp_dir().'/tmesis-doctor-templates-'.bin2hex(random_bytes(4));
    mkdir($dir);
    DoctorTemplateDirs::$made[] = $dir;

    foreach ($files as $file) {
        file_put_contents($dir.'/'.$file, "# template\n");
    }

    return $dir;
}

function bindDoctorFacts(): void
{
    app()->instance(EnrollmentFacts::class, new class implements EnrollmentFacts
    {
        public function values(NodeEnrollment $node): array
        {
            return [];
        }

        public function placeholders(NodeEnrollment $node): array
        {
            return [];
        }
    });
}
function doctorOutput(string $command = 'tmesis:doctor'): string
{
    Artisan::call($command);

    return Artisan::output();
}

it('passes http.verify on the default', function (): void {
    expect(doctorOutput())->toMatch('/pass http\.verify\s+on, the system CA store/');
});

it('passes http.verify on a readable CA bundle, naming the path', function (): void {
    $bundle = (string) tempnam(sys_get_temp_dir(), 'tmesis-ca-');
    config()->set('tmesis.http.verify', $bundle);

    expect(doctorOutput())->toContain("on, the CA bundle [{$bundle}]");

    unlink($bundle);
});

it('fails http.verify on a missing CA bundle, naming the path', function (): void {
    config()->set('tmesis.http.verify', '/nonexistent/tmesis-ca.pem');

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('[/nonexistent/tmesis-ca.pem] is not an absolute path to a readable CA bundle file')
        ->assertFailed();
});

it('fails http.verify false outside local and testing', function (): void {
    $this->app['env'] = 'production';
    config()->set('tmesis.http.verify', false);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('a control plane must not send a bearer to an unverified peer')
        ->assertFailed();
});

it('warns on http.verify false in testing, without failing', function (): void {
    config()->set('tmesis.http.verify', false);

    expect(doctorOutput())->toMatch('/warn http\.verify\s+off: allowed in local and testing only/');
});

it('reports the scheme of each node before the live call', function (string $url, string $level, string $detail): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', $url);
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $output = doctorOutput('tmesis:doctor --live');

    expect($output)->toMatch('/'.$level.' scheme '.preg_quote(TestCase::NODE, '/').'\s+'.preg_quote($detail, '/').'/')
        ->and(strpos($output, 'scheme '.TestCase::NODE))->toBeLessThan(strpos($output, 'live '.TestCase::NODE));
})->with([
    'https' => ['https://edge-atl-01.tsp24.com:7000', 'pass', 'https'],
    'http on 127.0.0.1' => ['http://127.0.0.1:7000', 'pass', 'http on a loopback host'],
    'http on localhost' => ['http://localhost:7000', 'pass', 'http on a loopback host'],
    'http on ::1' => ['http://[::1]:7000', 'pass', 'http on a loopback host'],
    'http on 127.8.0.1' => ['http://127.8.0.1:7000', 'pass', 'http on a loopback host'],
    'http elsewhere, in testing' => [
        'http://10.0.0.9:7000',
        'warn',
        "[http://10.0.0.9]: the admin token would cross the network in clear; the node's api.tls.domain is the fix (allowed in the [testing] environment only)",
    ],
]);

it('only warns for an http node elsewhere in testing, where the call is allowed', function (): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', 'http://10.0.0.9:7000');
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $this->artisan('tmesis:doctor --live')->assertSuccessful();
});

it('fails the scheme of an http node elsewhere outside local and testing, and passes loopback there', function (): void {
    $this->app['env'] = 'production';
    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', 'http://10.0.0.9:7000');
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $this->artisan('tmesis:doctor --live')
        ->expectsOutputToContain("[http://10.0.0.9]: the admin token would cross the network in clear; the node's api.tls.domain is the fix; every admin call to it is refused outside local and testing")
        ->assertFailed();

    config()->set('tmesis.nodes.'.TestCase::NODE.'.url', 'http://127.0.0.1:7000');

    expect(doctorOutput('tmesis:doctor --live'))->toMatch('/pass scheme '.preg_quote(TestCase::NODE, '/').'\s+http on a loopback host/');
});

it('reports no scheme row without --live', function (): void {
    expect(doctorOutput())->not->toContain('scheme '.TestCase::NODE);
});

it('prints no enrollment row when the routes are off and no repository is configured', function (): void {
    expect(doctorOutput())->not->toContain('enrollment');
});

it('fails enrollment when the routes are on with no repository', function (): void {
    doctorWithEnrollmentRoutes($this);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('routes are on and no NodeRepository is bound or named in tmesis.enrollment.repository')
        ->assertFailed();
});

it('fails enrollment when the configured repository is not a repository', function (): void {
    config()->set('tmesis.enrollment.repository', stdClass::class);

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('it must name a class implementing AlexHackney\Tmesis\Enrollment\NodeRepository')
        ->assertFailed();
});

it('passes enrollment with a repository and the routes off', function (): void {
    ArrayNodeRepository::fake();

    expect(doctorOutput())->toMatch('/pass enrollment\s+repository configured, routes off/');
});

it('passes enrollment with the routes on, naming all three urls', function (): void {
    doctorWithEnrollmentRoutes($this);
    ArrayNodeRepository::fake();

    $output = doctorOutput();

    expect($output)->toMatch('/pass enrollment\s+routes on: POST /')
        ->and($output)->toContain(route('tmesis.enroll.register'))
        ->and($output)->toContain(route('tmesis.enroll.install'))
        ->and($output)->toContain(route('tmesis.enroll.config'));
});

it('warns when the templates setting is not a readable directory', function (): void {
    ArrayNodeRepository::fake();
    config()->set('tmesis.enrollment.templates', '/nonexistent/tmesis-templates');

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('[tmesis.enrollment.templates] is [/nonexistent/tmesis-templates], which is missing or not a readable directory')
        ->assertSuccessful();
});

it('warns per role with no template, naming both file names', function (): void {
    ArrayNodeRepository::fake();
    bindDoctorFacts();
    config()->set('tmesis.enrollment.templates', doctorTemplatesDir(['cloud-init.edge.yml', 'config.origin.yml']));

    $output = doctorOutput();

    expect($output)->not->toContain('enrollment template edge')
        ->and($output)->not->toContain('enrollment template origin')
        ->and($output)->toContain('neither cloud-init.transcoder.yml nor config.transcoder.yml')
        ->and($output)->toContain('neither cloud-init.standalone.yml nor config.standalone.yml')
        ->and($output)->not->toContain('enrollment facts');
});

it('warns when templates are configured with no EnrollmentFacts', function (): void {
    ArrayNodeRepository::fake();
    config()->set('tmesis.enrollment.templates', doctorTemplatesDir([
        'cloud-init.edge.yml', 'cloud-init.origin.yml', 'cloud-init.transcoder.yml', 'cloud-init.standalone.yml',
    ]));

    $output = doctorOutput();

    expect($output)->toContain('templates need an EnrollmentFacts; bind one or name it in tmesis.enrollment.facts')
        ->and($output)->not->toContain('enrollment template ');
});

it('warns when the default node is the credential-less stub beside a resolver', function (): void {
    config()->set('tmesis.nodes', ['default' => ['url' => 'http://127.0.0.1:7000']]);
    config()->set('tmesis.default', 'default');
    ArrayNodeResolver::bind([new NodeConfig('ny.in.tsp24.com', '', null, 'wh-ny')]);

    expect(doctorOutput())->toContain(
        'warn default node',
    )->toContain('the default node [default] is the shipped stub with no credentials and is not in the node list; point TMESIS_DEFAULT_NODE at a resolver node');
});

it('passes the default node when a resolver is configured and the default has credentials', function (): void {
    ArrayNodeResolver::bind([new NodeConfig('ny.in.tsp24.com', '', null, 'wh-ny')]);

    expect(doctorOutput())->toMatch('/pass default node\s+\['.preg_quote(TestCase::NODE, '/').'\] is configured/');
});

it('passes the credential-less default node when there is no resolver', function (): void {
    config()->set('tmesis.nodes', ['default' => ['url' => 'http://127.0.0.1:7000']]);
    config()->set('tmesis.default', 'default');

    expect(doctorOutput())->toMatch('/pass default node\s+\[default\] is configured/');
});

it('passes the templates row on the bundled contract templates by default', function (): void {
    config()->set('tmesis.enrollment.repository', ArrayNodeRepository::class);

    $output = doctorOutput();

    expect($output)->toMatch('/pass enrollment templates\s+the contract\'s config.<role>.yml, bundled at the pin/')
        ->and($output)->not->toContain('enrollment template edge')
        ->and($output)->toContain('templates need an EnrollmentFacts');
});

it('fails a keyterms hook secret with surrounding whitespace, naming the key and never the value', function (): void {
    config()->set('tmesis.nodes.'.TestCase::NODE.'.keyterms_hook_secret', "kt_SECRET_value\n");

    $output = doctorOutput();

    expect($output)->toContain('[tmesis.nodes.'.TestCase::NODE.'.keyterms_hook_secret] has leading or trailing whitespace')
        ->not->toContain('kt_SECRET_value');
});

it('passes the keyterms route as disabled by default', function (): void {
    expect(doctorOutput())->toMatch('/pass keyterms route\s+disabled \(hooks\.keyterms\.enabled is false\): GET http:\/\/localhost\/webhooks\/hooks\/keyterms answers a signed request 503 keyterms_disabled/');
});

it('warns when the keyterms route is missing while off, and fails it while on, because a node then gets a 404', function (): void {
    $dropKeytermsRoute = function (): void {
        $routes = new RouteCollection;

        foreach (Route::getRoutes() as $route) {
            if ($route->getName() !== 'tmesis.hooks.keyterms') {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
    };

    $dropKeytermsRoute();
    expect(doctorOutput())->toMatch('/warn keyterms route\s+the route is not registered \(a stale route cache\?\): a node pointed at it gets a 404/');

    $this->withKeytermsHookEnabled();
    $dropKeytermsRoute();
    expect(doctorOutput())->toMatch('/fail keyterms route\s+hooks\.keyterms\.enabled is true but the route is not registered/');
});

it('fails the default keyterms provider on an enabled route, and passes a bound one', function (): void {
    $this->withKeytermsHookEnabled();

    expect(doctorOutput())->toMatch('/pass keyterms route\s+GET http:\/\/localhost\/webhooks\/hooks\/keyterms/')
        ->toMatch('/fail keyterms provider\s+.*NoKeyterms answers none/');

    config()->set('tmesis.hooks.keyterms.provider', ArrayKeytermsProvider::class);

    expect(doctorOutput())->toMatch('/pass keyterms provider\s+.*ArrayKeytermsProvider/');
});

it('fails a keyterms provider that is not one', function (): void {
    $this->withKeytermsHookEnabled();
    config()->set('tmesis.hooks.keyterms.provider', NotADecider::class);

    expect(doctorOutput())->toMatch('/fail keyterms provider\s+.*does not implement/');
});
