<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\ConfigRenderer;
use AlexHackney\Tmesis\Enrollment\EnrollmentFacts;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRegistration;
use AlexHackney\Tmesis\Enrollment\NodeRepository;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Enrollment\RenderMode;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Enrollment\TemplateValues;
use AlexHackney\Tmesis\Testing\ArrayNodeRepository;
use AlexHackney\Tmesis\Webhooks\WebhookSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

final class RoutesTestFacts implements EnrollmentFacts
{
    /**
     * ONE facts object for both documents: values() fill the config document and
     * placeholders() the app's own template (TemplateKind), so neither refuses the
     * other's.
     *
     * @param  array<string, string>  $placeholders  for an app template that uses them
     */
    public function __construct(private array $placeholders = []) {}

    public function values(NodeEnrollment $node): array
    {
        return ['api.tls.domain' => 'ny.in.tsp24.com']
            + ($node->role === NodeRole::Standalone ? [] : ['webhooks.url' => 'https://tsp.test/api/webhooks/tmesis']);
    }

    public function placeholders(NodeEnrollment $node): array
    {
        return $this->placeholders;
    }
}

const ENROLMENT_ENV = 'TMESIS_ENROLLMENT_ENABLED';

/**
 * Turn the routes on through the env the shipped config reads, then rebuild the app
 * so the provider's boot() sees it.
 */
function withEnrollmentEnabled(object $test): void
{
    putenv(ENROLMENT_ENV.'=true');
    $_ENV[ENROLMENT_ENV] = $_SERVER[ENROLMENT_ENV] = 'true';

    (fn () => $this->reloadApplication())->call($test);
}

/**
 * @return array{0: ArrayNodeRepository, 1: NodeEnrollment, 2: string}
 */
function pendingEdge(): array
{
    $repository = ArrayNodeRepository::fake();
    app()->instance(EnrollmentFacts::class, new RoutesTestFacts);

    [$node, $token] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);
    $repository->enroll($node);

    return [$repository, $node, $token->plain()];
}

function registerBody(string $token): array
{
    return ['token' => $token, 'version' => 'v0.6.0', 'api_url' => 'https://ny.in.tsp24.com:7443'];
}

/**
 * POST raw bytes to the register route, signed the way the box signs: the webhook
 * function over the exact body, with the enrolment's webhook secret.
 *
 * @param  array<string, mixed>|string  $body
 * @param  array<string, string>  $headers  server headers, HTTP_ prefixed
 */
function signedRegister(object $test, array|string $body, ?string $secret, array $headers = []): TestResponse
{
    $raw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
    $timestamp = $headers['HTTP_X_TMESIS_TIMESTAMP'] ?? (string) now()->getTimestamp();

    $server = array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
        'HTTP_X_TMESIS_SIGNATURE' => $secret === null ? '' : WebhookSignature::sign($secret, $timestamp, $raw),
    ], $headers);

    return (fn () => $this->call('POST', route('tmesis.enroll.register'), [], [], [], $server, $raw))->call($test);
}

function install(object $test, ?string $token, array $query = []): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return (fn () => $this->get(route('tmesis.enroll.install', $query), $headers))->call($test);
}

function fetchConfig(object $test, ?string $token, array $query = []): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return (fn () => $this->get(route('tmesis.enroll.config', $query), $headers))->call($test);
}

afterEach(function (): void {
    putenv(ENROLMENT_ENV);
    unset($_ENV[ENROLMENT_ENV], $_SERVER[ENROLMENT_ENV]);
    Carbon::setTestNow();
});

it('registers no route by default', function (): void {
    expect(Route::has('tmesis.enroll.register'))->toBeFalse()
        ->and(Route::has('tmesis.enroll.install'))->toBeFalse()
        ->and(Route::has('tmesis.enroll.config'))->toBeFalse();

    $this->postJson('/webhooks/enroll/register', registerBody('x'))->assertNotFound();
});

it('registers all three routes under the webhook prefix when enabled', function (): void {
    withEnrollmentEnabled($this);

    expect(route('tmesis.enroll.register', absolute: false))->toBe('/webhooks/enroll/register')
        ->and(route('tmesis.enroll.install', absolute: false))->toBe('/webhooks/enroll/install')
        ->and(route('tmesis.enroll.config', absolute: false))->toBe('/webhooks/enroll/config');
});

it('registers a signed request from a pending node and burns its token', function (): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    signedRegister($this, registerBody($token), $node->webhookSecret())->assertNoContent();

    $repository->assertRegistered('ny.in.tsp24.com');
    expect($repository->registration('ny.in.tsp24.com')->version)->toBe('v0.6.0')
        ->and($repository->registration('ny.in.tsp24.com')->apiUrl)->toBe('https://ny.in.tsp24.com:7443');

    signedRegister($this, registerBody($token), $node->webhookSecret())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'unknown_token');
});

it('answers 404 and burns nothing when the enrolment is replaced between the lookup and the write', function (): void {
    withEnrollmentEnabled($this);
    [$repository, $old, $oldToken] = pendingEdge();
    [$new] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge);

    // `tmesis:enroll-node --replace` lands after the route's pending() lookup and
    // before its register(): the old box's request must not register against the
    // replacement or burn its token.
    $interleaving = new class($repository, $new) implements NodeRepository
    {
        public function __construct(private ArrayNodeRepository $inner, private NodeEnrollment $replacement) {}

        public function enroll(NodeEnrollment $node, bool $replace = false): void
        {
            $this->inner->enroll($node, $replace);
        }

        public function pending(string $bootstrapTokenSha256): ?NodeEnrollment
        {
            $found = $this->inner->pending($bootstrapTokenSha256);

            if ($found !== null && $found !== $this->replacement) {
                $this->inner->enroll($this->replacement, replace: true);
            }

            return $found;
        }

        public function register(string $bootstrapTokenSha256, NodeRegistration $registration): bool
        {
            return $this->inner->register($bootstrapTokenSha256, $registration);
        }
    };
    app()->instance(NodeRepository::class, $interleaving);

    $unknown = signedRegister($this, registerBody('not-a-token'), $old->webhookSecret())->json();

    signedRegister($this, registerBody($oldToken), $old->webhookSecret())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'unknown_token')
        ->assertExactJson($unknown);

    $repository->assertNotRegistered('ny.in.tsp24.com');
    expect($repository->enrolled('ny.in.tsp24.com'))->toBe($new)
        ->and($repository->pending($new->bootstrapTokenSha256))->toBe($new);
});

it('verifies the exact bytes received', function (): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    // Whitespace json_encode would never produce: a re-encoded body would not verify.
    $raw = "{ \"token\" : \"{$token}\", \"version\": \"v0.6.0\",\n \"api_url\": \"https://ny.in.tsp24.com:7443\" }";

    signedRegister($this, $raw, $node->webhookSecret())->assertNoContent();
    $repository->assertRegistered('ny.in.tsp24.com');
});

it('refuses a wrong secret, a missing signature, a missing timestamp and a stale one, leaving the token pending', function (?string $secret, array $headers): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    signedRegister($this, registerBody($token), $secret === 'enrolled' ? $node->webhookSecret() : $secret, $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature');

    $repository->assertNotRegistered('ny.in.tsp24.com');
    expect($repository->pending($node->bootstrapTokenSha256))->toBe($node);
})->with([
    'wrong secret' => ['not-the-secret', []],
    'no signature' => [null, []],
    'no timestamp' => ['enrolled', ['HTTP_X_TMESIS_TIMESTAMP' => '']],
    'stale timestamp' => ['enrolled', ['HTTP_X_TMESIS_TIMESTAMP' => (string) (time() - 301)]],
    'unprefixed' => ['enrolled', ['HTTP_X_TMESIS_SIGNATURE' => str_repeat('a', 64)]],
]);

it('refuses a registration signed for a different body', function (): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    $timestamp = (string) now()->getTimestamp();
    $signature = WebhookSignature::sign($node->webhookSecret(), $timestamp, json_encode(registerBody($token)));

    signedRegister($this, array_merge(registerBody($token), ['api_url' => 'https://attacker.test']), null, [
        'HTTP_X_TMESIS_TIMESTAMP' => $timestamp,
        'HTTP_X_TMESIS_SIGNATURE' => $signature,
    ])->assertUnauthorized();

    $repository->assertNotRegistered('ny.in.tsp24.com');
});

it('answers an unknown, missing and expired token alike, before any signature check', function (): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    $unknown = signedRegister($this, registerBody('not-a-token'), $node->webhookSecret())->assertNotFound();
    signedRegister($this, ['version' => 'v0.6.0', 'api_url' => 'https://x'], $node->webhookSecret())->assertNotFound();
    signedRegister($this, 'not json', null)->assertNotFound();

    Carbon::setTestNow(now()->addMinutes(15));
    $expired = signedRegister($this, registerBody($token), $node->webhookSecret())->assertNotFound();

    expect($expired->json())->toBe($unknown->json())
        ->and($unknown->json('error.code'))->toBe('unknown_token');
    $repository->assertNotRegistered('ny.in.tsp24.com');
});

it('refuses a signed registration with no version or a bad field, without burning', function (array $body): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    signedRegister($this, array_merge(registerBody($token), $body), $node->webhookSecret())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    $repository->assertNotRegistered('ny.in.tsp24.com');
    expect($repository->pending($node->bootstrapTokenSha256))->toBe($node);
})->with([
    [['version' => '']],
    [['version' => 7]],
    [['api_url' => 'ny.in.tsp24.com:7443']],
    [['api_url' => 7]],
    [['api_listen' => 7443]],
    [['api_scheme' => 'ftp']],
    [['api_domain' => ['ny']]],
    [['doctor' => 'all green']],
    [['bench' => 4]],
]);

it('registers from what tmesis version --json reports, deriving the admin url', function (array $report, ?string $expected): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    // DEPLOY.md 5.6: the bootstrap posts the version report as it is, plus the token.
    $body = ['token' => $token, 'version' => 'v0.6.0', 'commit' => 'b267f0d', 'platform' => 'linux/amd64'] + $report;

    signedRegister($this, $body, $node->webhookSecret())->assertNoContent();

    $registration = $repository->registration('ny.in.tsp24.com');

    expect($registration->apiUrl)->toBe($expected)
        ->and($registration->isReceiveOnly())->toBe($expected === null)
        ->and($repository->pending($node->bootstrapTokenSha256))->toBeNull();
})->with([
    'the ACME path' => [['api_listen' => ':7443', 'api_scheme' => 'https', 'api_domain' => 'ny.in.tsp24.com'], 'https://ny.in.tsp24.com:7443'],
    'a listen naming a host' => [['api_listen' => '10.0.0.9:7000', 'api_scheme' => 'http'], 'http://10.0.0.9:7000'],
    'a hostless listen, no domain: receive-only' => [['api_listen' => ':7000', 'api_scheme' => 'http'], null],
    'the loopback default: receive-only' => [['api_listen' => '127.0.0.1:7000', 'api_scheme' => 'http'], null],
    'no config loaded: receive-only' => [[], null],
    'an explicit api_url wins' => [['api_url' => 'https://ingest-ny.tsp24.com:7443', 'api_listen' => ':7443', 'api_scheme' => 'https'], 'https://ingest-ny.tsp24.com:7443'],
    'a null api_url is absent' => [['api_url' => null, 'api_listen' => ':7443', 'api_scheme' => 'https', 'api_domain' => 'ny.in.tsp24.com'], 'https://ny.in.tsp24.com:7443'],
]);

it('registers a derived http url to a host that is not loopback as receive-only outside local and testing', function (): void {
    withEnrollmentEnabled($this);
    $this->app['env'] = 'production';
    [$repository, $node, $token] = pendingEdge();

    $body = ['token' => $token, 'version' => 'v0.8.3', 'api_listen' => '10.0.0.9:7000', 'api_scheme' => 'http'];
    signedRegister($this, $body, $node->webhookSecret())->assertNoContent();

    expect($repository->registration('ny.in.tsp24.com')->apiUrl)->toBeNull()
        ->and($repository->registration('ny.in.tsp24.com')->isReceiveOnly())->toBeTrue();
});

it('refuses an explicit http api_url to a host that is not loopback outside local and testing, keeping the token pending', function (): void {
    withEnrollmentEnabled($this);
    $this->app['env'] = 'production';
    [$repository, $node, $token] = pendingEdge();

    signedRegister($this, ['token' => $token, 'version' => 'v0.8.3', 'api_url' => 'http://ingest-ny.tsp24.com:7000'], $node->webhookSecret())
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', "The registration's [api_url] is refused: it is http to a host that is not loopback, and the admin token is never sent in clear outside local and testing; register https (the node's api.tls.domain).");

    expect($repository->pending($node->bootstrapTokenSha256))->toBe($node);

    // Loopback http stays allowed there.
    signedRegister($this, ['token' => $token, 'version' => 'v0.8.3', 'api_url' => 'http://127.0.0.1:7000'], $node->webhookSecret())->assertNoContent();

    expect($repository->registration('ny.in.tsp24.com')->apiUrl)->toBe('http://127.0.0.1:7000');
});

it('records the doctor report and the bench result with the registration, untouched', function (): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();

    $doctor = ['rows' => [['name' => 'srt receive buffer', 'status' => 'WARN']], 'exit' => 1];
    $bench = ['profile' => 'job', 'max_concurrent' => 4];

    signedRegister($this, registerBody($token) + ['doctor' => $doctor, 'bench' => $bench], $node->webhookSecret())
        ->assertNoContent();

    $registration = $repository->registration('ny.in.tsp24.com');

    expect($registration->doctor)->toBe($doctor)
        ->and($registration->bench)->toBe($bench);

    // Both are optional.
    [$repository, $node, $token] = pendingEdge();
    signedRegister($this, registerBody($token), $node->webhookSecret())->assertNoContent();

    expect($repository->registration('ny.in.tsp24.com')->doctor)->toBeNull()
        ->and($repository->registration('ny.in.tsp24.com')->bench)->toBeNull();
});

it('serves the app\'s user-data and the bundled config from ONE facts object, burning nothing', function (): void {
    withEnrollmentEnabled($this);
    $dir = sys_get_temp_dir().'/tmesis-route-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/cloud-init.edge.yml", "#cloud-config\nhostname: \${TMESIS_NODE_NAME}\ntoken: \${TMESIS_ADMIN_TOKEN}\nsecret: \${TMESIS_WEBHOOK_SECRET}\nboot: \${TMESIS_BOOTSTRAP_TOKEN}\nregister: \${TMESIS_ENROLL_REGISTER_URL}\n");
    file_put_contents("{$dir}/config.edge.yml", 'config-only: ${TMESIS_ADMIN_TOKEN}');
    config()->set('tmesis.enrollment.templates', $dir);
    [$repository, $node, $token] = pendingEdge();
    app()->instance(EnrollmentFacts::class, new RoutesTestFacts(['TMESIS_NODE_NAME' => $node->name]));

    $response = install($this, $token)->assertOk();

    // The same facts serve the role's config: the values set, the placeholder fact
    // (which the bundled config does not name) not refused.
    $config = Yaml::parse((string) fetchConfig($this, $token)->assertOk()->getContent());

    expect($config['webhooks']['url'])->toBe('https://tsp.test/api/webhooks/tmesis')
        ->and($config['api']['tls']['domain'])->toBe('ny.in.tsp24.com');

    expect($response->headers->get('Content-Type'))->toStartWith('text/x-shellscript')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toBe("#cloud-config\nhostname: ny.in.tsp24.com\ntoken: {$node->adminToken()}\nsecret: {$node->webhookSecret()}\nboot: {$token}\nregister: http://localhost/webhooks/enroll/register\n")
        ->and($repository->pending($node->bootstrapTokenSha256))->toBe($node);

    install($this, $token)->assertOk();
    signedRegister($this, registerBody($token), $node->webhookSecret())->assertNoContent();
    install($this, $token)->assertNotFound()->assertJsonPath('error.code', 'unknown_token');
});

it('serves each bundled role config without putting enrolment secrets in it or burning the token', function (NodeRole $role): void {
    withEnrollmentEnabled($this);
    $repository = ArrayNodeRepository::fake();
    app()->instance(EnrollmentFacts::class, new RoutesTestFacts);
    [$node, $bootstrap] = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', $role);
    $repository->enroll($node);
    $token = $bootstrap->plain();

    // A caller-supplied cloud-init file must never become the config response.
    $dir = sys_get_temp_dir().'/tmesis-config-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/cloud-init.{$role->value}.yml", 'override: ${TMESIS_ADMIN_TOKEN}');
    config()->set('tmesis.enrollment.templates', $dir);

    $response = fetchConfig($this, $token)->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('text/yaml; charset=utf-8')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($body)->toContain('role: '.$role->value)
        ->toContain('${TMESIS_ADMIN_TOKEN}')
        ->not->toContain('override:')
        ->and($repository->pending($node->bootstrapTokenSha256))->toBe($node);

    foreach ([$node->adminToken(), $node->webhookSecret(), $node->hookSecret(), $token, (string) config('tmesis.keys.secret')] as $secret) {
        expect($body)->not->toContain($secret);
    }

    // Since tmesis v0.8.0 the bundled templates carry plain values, and the facts SET
    // them: the response is the role's template with the name and the app's values
    // written in, and only the secrets left for the node's .env.
    $parsed = Yaml::parse($body);
    $template = ConfigRenderer::read(ConfigRenderer::bundledDirectory()."/config.{$role->value}.yml");

    foreach ((new RoutesTestFacts)->values($node) as $path => $value) {
        expect(data_get($parsed, $path))->toBe($value);
    }

    if (TemplateValues::parse($template)->settable('node.name')) {
        expect(data_get($parsed, 'node.name'))->toBe($node->name);
    }

    if ($role === NodeRole::Origin) {
        expect(data_get($parsed, 'transcription.keyterms_hook.secret'))->toBe('${TMESIS_KEYTERMS_HOOK_SECRET:-}')
            ->and($body)->not->toContain($node->keytermsHookSecret());
    }

    expect($body)->toBe(app(ConfigRenderer::class)->render(
        ConfigRenderer::read(ConfigRenderer::bundledDirectory()."/config.{$role->value}.yml"),
        $node,
        app(EnrollmentFacts::class),
        RenderMode::Config,
    )->reveal());

    signedRegister($this, registerBody($token), $node->webhookSecret())->assertNoContent();
    fetchConfig($this, $token)->assertNotFound()->assertJsonPath('error.code', 'unknown_token');
})->with(NodeRole::cases());

it('serves bundled config even when the configured template directory does not exist', function (): void {
    withEnrollmentEnabled($this);
    config()->set('tmesis.enrollment.templates', '/nonexistent/tmesis-templates');
    [, , $token] = pendingEdge();

    fetchConfig($this, $token)->assertOk()->assertSee('${TMESIS_ADMIN_TOKEN}', false);
    install($this, $token)->assertNotFound()->assertJsonPath('error.code', 'no_template');
});

it('refuses bundled config as install user-data even with a blank stream key secret', function (): void {
    withEnrollmentEnabled($this);
    [$repository, $node, $token] = pendingEdge();
    config()->set('tmesis.keys.secret', '');

    $response = install($this, $token)->assertNotFound()->assertJsonPath('error.code', 'no_template');

    expect($response->json('error.message'))->toContain('cloud-init')
        ->and($response->getContent())->not->toContain($node->adminToken())
        ->not->toContain($node->webhookSecret())
        ->not->toContain($token)
        ->and(fetchConfig($this, $token)->assertOk()->getContent())->toContain('${TMESIS_ADMIN_TOKEN}')
        ->and($repository->pending($node->bootstrapTokenSha256))->toBe($node);
});

it('refuses a configured config-only template on the install route', function (): void {
    withEnrollmentEnabled($this);
    $dir = sys_get_temp_dir().'/tmesis-config-only-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/config.edge.yml", 'token: ${TMESIS_ADMIN_TOKEN}');
    config()->set('tmesis.enrollment.templates', $dir);
    [, $node, $token] = pendingEdge();

    $response = install($this, $token)->assertNotFound()->assertJsonPath('error.code', 'no_template');
    expect($response->getContent())->not->toContain($node->adminToken());
});

it('answers config with the same unknown_token for missing, unknown, burned and expired bearer tokens', function (): void {
    withEnrollmentEnabled($this);
    [, $node, $token] = pendingEdge();

    $unknown = fetchConfig($this, 'not-a-token')->assertNotFound()->assertJsonPath('error.code', 'unknown_token')->json();
    expect(fetchConfig($this, null, ['token' => $token])->assertNotFound()->json())->toBe($unknown);

    signedRegister($this, registerBody($token), $node->webhookSecret())->assertNoContent();
    expect(fetchConfig($this, $token)->assertNotFound()->json())->toBe($unknown);

    [, , $freshToken] = pendingEdge();
    Carbon::setTestNow(now()->addMinutes(16));
    expect(fetchConfig($this, $freshToken)->assertNotFound()->json())->toBe($unknown);
});

it('ignores a token in the query string', function (): void {
    withEnrollmentEnabled($this);
    config()->set('tmesis.enrollment.templates', sys_get_temp_dir());
    [, , $token] = pendingEdge();

    install($this, null, ['token' => $token])->assertNotFound()->assertJsonPath('error.code', 'unknown_token');
});

it('answers install with 404 for an unknown or expired token, or no template', function (): void {
    withEnrollmentEnabled($this);
    config()->set('tmesis.enrollment.templates', '/nonexistent/tmesis-templates');
    [, , $token] = pendingEdge();

    install($this, null)->assertNotFound()->assertJsonPath('error.code', 'unknown_token');
    install($this, 'nope')->assertNotFound()->assertJsonPath('error.code', 'unknown_token');
    install($this, $token)->assertNotFound()->assertJsonPath('error.code', 'no_template');

    Carbon::setTestNow(now()->addMinutes(16));
    install($this, $token)->assertNotFound()->assertJsonPath('error.code', 'unknown_token');
});

it('prints the install line as a curl with the bearer header when the routes are on', function (): void {
    withEnrollmentEnabled($this);
    config()->set('tmesis.enrollment.templates', '/nonexistent/tmesis-templates');
    $repository = ArrayNodeRepository::fake();

    Artisan::call('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com']);
    $output = Artisan::output();

    preg_match('/bootstrap token\s+(\S+)/', $output, $m);

    expect($output)->toContain('curl -fsS -H "Authorization: Bearer '.$m[1].'" http://localhost/webhooks/enroll/install')
        ->and($output)->not->toContain('?token=');
    $repository->assertEnrolled('ny.in.tsp24.com');
});
