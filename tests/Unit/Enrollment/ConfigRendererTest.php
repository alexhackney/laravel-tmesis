<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\BootstrapToken;
use AlexHackney\Tmesis\Enrollment\ConfigRenderer;
use AlexHackney\Tmesis\Enrollment\EnrollmentFacts;
use AlexHackney\Tmesis\Enrollment\EnrollmentSecrets;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Enrollment\RenderedDocument;
use AlexHackney\Tmesis\Enrollment\RenderMode;
use AlexHackney\Tmesis\Enrollment\SecretMinter;
use AlexHackney\Tmesis\Enrollment\TemplateKind;
use AlexHackney\Tmesis\Enrollment\TemplateValues;
use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * @param  array<string, mixed>  $placeholders
 * @param  array<string, mixed>  $values
 */
function facts(array $placeholders, array $values = []): EnrollmentFacts
{
    return new class($placeholders, $values) implements EnrollmentFacts
    {
        /**
         * @param  array<string, mixed>  $placeholders
         * @param  array<string, mixed>  $values
         */
        public function __construct(private array $placeholders, private array $values) {}

        public function values(NodeEnrollment $node): array
        {
            /** @var array<string, string> */
            return $this->values;
        }

        public function placeholders(NodeEnrollment $node): array
        {
            /** @var array<string, string> */
            return $this->placeholders;
        }
    };
}

function edge(): NodeEnrollment
{
    return app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Edge)[0];
}

function configRender(string $template, array $facts = [], ?NodeEnrollment $node = null, array $values = []): string
{
    return app(ConfigRenderer::class)->render($template, $node ?? edge(), facts($facts, $values), RenderMode::Config)->reveal();
}

function templateDir(array $files): string
{
    $dir = sys_get_temp_dir().'/tmesis-templates-'.bin2hex(random_bytes(4));
    mkdir($dir);

    foreach ($files as $name => $contents) {
        file_put_contents("{$dir}/{$name}", $contents);
    }

    return $dir;
}

const SECRETS_TEMPLATE = "token: \${TMESIS_ADMIN_TOKEN}\nsecret: \${TMESIS_WEBHOOK_SECRET}\nhook: \${TMESIS_HOOK_SECRET:-x}\nkeyterms: \${TMESIS_KEYTERMS_HOOK_SECRET:-}\nkeys: \${TMESIS_STREAM_KEY_SECRET}\nboot: \${TMESIS_BOOTSTRAP_TOKEN}\n";

it('substitutes facts', function (): void {
    $out = configRender(
        "webhooks:\n  url: \${TMESIS_WEBHOOK_URL}\napi:\n  tls:\n    domain: \${TMESIS_API_TLS_DOMAIN}\n",
        ['TMESIS_WEBHOOK_URL' => 'https://app.example.com/api/webhooks/tmesis', 'TMESIS_API_TLS_DOMAIN' => 'ny.in.tsp24.com'],
    );

    expect($out)->toBe("webhooks:\n  url: https://app.example.com/api/webhooks/tmesis\napi:\n  tls:\n    domain: ny.in.tsp24.com\n");
});

it('leaves the reserved placeholders in place in config mode', function (): void {
    $node = edge();
    $document = app(ConfigRenderer::class)->render(SECRETS_TEMPLATE, $node, facts([]), RenderMode::Config);

    expect($document)->toBeInstanceOf(RenderedDocument::class)
        ->and($document->mode)->toBe(RenderMode::Config)
        ->and($document->carriesSecrets())->toBeFalse()
        ->and($document->reveal())->toBe(SECRETS_TEMPLATE)
        ->not->toContain($node->adminToken());
});

it('substitutes the secrets and the bootstrap token in user-data mode', function (): void {
    $node = edge();
    $token = new BootstrapToken('boot_plain', new DateTimeImmutable('+15 minutes'));

    $document = app(ConfigRenderer::class)->render(SECRETS_TEMPLATE, $node, facts([]), RenderMode::UserData, $token);

    expect($document->carriesSecrets())->toBeTrue()
        ->and($document->reveal())->toBe(
            "token: {$node->adminToken()}\nsecret: {$node->webhookSecret()}\nhook: {$node->hookSecret()}\nkeyterms: {$node->keytermsHookSecret()}\nkeys: sk_tmesis_docs_example\nboot: boot_plain\n"
        );
});

it('needs a token for a user-data template that names one', function (): void {
    app(ConfigRenderer::class)->render('boot: ${TMESIS_BOOTSTRAP_TOKEN}', edge(), facts([]), RenderMode::UserData);
})->throws(EnrollmentException::class, 'no bootstrap token was given');

it('renders user-data that names no token without one', function (): void {
    $node = edge();

    expect(app(ConfigRenderer::class)->render('t: ${TMESIS_ADMIN_TOKEN}', $node, facts([]), RenderMode::UserData)->reveal())
        ->toBe('t: '.$node->adminToken());
});

it('refuses a user-data render of a blank stream key secret, naming the key', function (): void {
    config()->set('tmesis.keys.secret', '');

    app(ConfigRenderer::class)->render('k: ${TMESIS_STREAM_KEY_SECRET}', edge(), facts([]), RenderMode::UserData);
})->throws(InvalidConfigurationException::class, '[tmesis.keys.secret]');

it('fills a defaulted placeholder from the facts first, then its default', function (): void {
    expect(configRender('listen: ${TMESIS_API_LISTEN:-:7000}'))->toBe('listen: :7000')
        ->and(configRender('listen: ${TMESIS_API_LISTEN:-:7000}', ['TMESIS_API_LISTEN' => ':7443']))->toBe('listen: :7443')
        ->and(configRender('empty: "${TMESIS_EMPTY:-}"'))->toBe('empty: ""');
});

it('refuses an unfilled placeholder, naming it and not the document', function (): void {
    try {
        configRender("secret-ish line\nurl: \${TMESIS_DELIVERY_ORIGIN_DOMAIN}\n");
        $this->fail('rendered');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain('${TMESIS_DELIVERY_ORIGIN_DOMAIN}')
            ->not->toContain('secret-ish line');
    }
});

it('reserves the eleven .env secrets of tmesis v0.9.2, the bootstrap token and the fleet join token', function (): void {
    // TMESIS_FLEET_JOIN_TOKEN since v0.7.0 (plan-v0.7.0-fleet.md F2): user-data
    // only, never a fact, never in .env or config.yml. TMESIS_KEYTERMS_HOOK_SECRET
    // (minted) and TMESIS_SRT_PASSPHRASE (supplied) since v0.8.0. The two transcription
    // keys the package does not mint, TMESIS_TRANSCRIPTION_BATCH_API_KEY (v0.9.1) and
    // TMESIS_TRANSCRIPTION_FALLBACK_API_KEY (v0.9.2), are supplied too: exactly the
    // secrets build/packaging/.env.example lists at the tmesis tag.
    expect(ConfigRenderer::reserved())->toBe([
        'TMESIS_ADMIN_TOKEN',
        'TMESIS_WEBHOOK_SECRET',
        'TMESIS_HOOK_SECRET',
        'TMESIS_KEYTERMS_HOOK_SECRET',
        'TMESIS_STREAM_KEY_SECRET',
        'TMESIS_TRANSCRIPTION_API_KEY',
        'TMESIS_TRANSCRIPTION_BATCH_API_KEY',
        'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY',
        'TMESIS_LIVE_ACCESS_KEY',
        'TMESIS_LIVE_SECRET_KEY',
        'TMESIS_SRT_PASSPHRASE',
        'TMESIS_BOOTSTRAP_TOKEN',
        'TMESIS_FLEET_JOIN_TOKEN',
    ]);
});

it('refuses a fact with a reserved name', function (string $name): void {
    configRender('x', [$name => 'leak']);
})->with(fn (): array => ConfigRenderer::reserved())
    ->throws(EnrollmentException::class, 'which is reserved');

it('refuses a fact naming either transcription key by name, without its value', function (string $name): void {
    try {
        configRender('x', [$name => 'leak_value']);
        $this->fail('rendered');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain("[{$name}]", 'which is reserved')
            ->not->toContain('leak_value');
    }
})->with(['TMESIS_TRANSCRIPTION_BATCH_API_KEY', 'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY']);

it('takes both transcription keys from the secrets seam, each wrapped in a SensitiveParameterValue', function (): void {
    $supplied = app(ConfigRenderer::class)->suppliedSecrets(edge(), secretsSeam([
        'TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET',
        'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => 'FB_SECRET',
    ]));

    expect($supplied)->toHaveKeys(['TMESIS_TRANSCRIPTION_BATCH_API_KEY', 'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY'])
        ->and($supplied['TMESIS_TRANSCRIPTION_BATCH_API_KEY'])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($supplied['TMESIS_TRANSCRIPTION_BATCH_API_KEY']->getValue())->toBe('BK_SECRET')
        ->and($supplied['TMESIS_TRANSCRIPTION_FALLBACK_API_KEY'])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($supplied['TMESIS_TRANSCRIPTION_FALLBACK_API_KEY']->getValue())->toBe('FB_SECRET');
});

it('names both transcription keys among the supplied names when the seam answers a name it may not supply', function (): void {
    try {
        app(ConfigRenderer::class)->suppliedSecrets(edge(), secretsSeam(['TMESIS_ADMIN_TOKEN' => 'leak_value']));
        $this->fail('accepted');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain('TMESIS_TRANSCRIPTION_BATCH_API_KEY', 'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY')
            ->not->toContain('leak_value');
    }
});

const TRANSCRIPTION_TEMPLATE = "batch: \"\${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}\"\nfallback: \"\${TMESIS_TRANSCRIPTION_FALLBACK_API_KEY:-}\"\n";

it('fills a transcription key an app template names from the seam in user-data mode, and the template default for one it leaves out', function (): void {
    $document = app(ConfigRenderer::class)->render(
        TRANSCRIPTION_TEMPLATE,
        edge(),
        facts([]),
        RenderMode::UserData,
        secrets: secretsSeam(['TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => 'FB_SECRET']),
    );

    expect($document->reveal())->toBe("batch: \"\"\nfallback: \"FB_SECRET\"\n");
});

it('leaves both transcription keys as placeholders for the node\'s .env in config mode, whatever the seam answers', function (): void {
    $document = app(ConfigRenderer::class)->render(
        TRANSCRIPTION_TEMPLATE,
        edge(),
        facts([]),
        RenderMode::Config,
        secrets: secretsSeam(['TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => 'FB_SECRET']),
    );

    expect($document->reveal())->toBe(TRANSCRIPTION_TEMPLATE)
        ->and($document->reveal())->not->toContain('FB_SECRET');
});

const TRANSCRIPTION_ROLES_TEMPLATE = "transcription:\n  provider: none\n  batch_provider: \"\"\n  batch_api_key: \"\${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}\"\n  fallback_provider: none\n  fallback_api_key: \"\${TMESIS_TRANSCRIPTION_FALLBACK_API_KEY:-}\"\n";

it('renders a self_hosted batch key empty in both modes, whatever the seam supplies', function (string $template, array $values): void {
    $secrets = secretsSeam(['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET']);

    $config = app(ConfigRenderer::class)->render($template, edge(), facts([], $values), RenderMode::Config, secrets: $secrets)->reveal();
    $userData = app(ConfigRenderer::class)->render($template, edge(), facts([], $values), RenderMode::UserData, secrets: $secrets)->reveal();

    expect($config)->toContain('batch_api_key: ""')
        ->not->toContain('TMESIS_TRANSCRIPTION_BATCH_API_KEY')
        ->and($userData)->toContain('batch_api_key: ""')
        ->not->toContain('BK_SECRET')
        ->not->toContain('${');
})->with([
    'a fact sets the batch role' => [TRANSCRIPTION_ROLES_TEMPLATE, ['transcription.batch_provider' => 'self_hosted']],
    'a fact sets the provider the batch role inherits' => [TRANSCRIPTION_ROLES_TEMPLATE, ['transcription.provider' => 'self_hosted']],
    'the template names the batch role' => [str_replace('provider: none', 'provider: self_hosted', TRANSCRIPTION_ROLES_TEMPLATE), []],
]);

it('renders a self_hosted fallback key empty in both modes, whatever the seam supplies', function (string $template, array $values): void {
    $secrets = secretsSeam(['TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => 'FB_SECRET']);

    $config = app(ConfigRenderer::class)->render($template, edge(), facts([], $values), RenderMode::Config, secrets: $secrets)->reveal();
    $userData = app(ConfigRenderer::class)->render($template, edge(), facts([], $values), RenderMode::UserData, secrets: $secrets)->reveal();

    expect($config)->toContain('fallback_api_key: ""')
        ->not->toContain('TMESIS_TRANSCRIPTION_FALLBACK_API_KEY')
        ->and($userData)->toContain('fallback_api_key: ""')
        ->not->toContain('FB_SECRET')
        ->not->toContain('${');
})->with([
    'a fact sets the fallback role' => [TRANSCRIPTION_ROLES_TEMPLATE, ['transcription.fallback_provider' => 'self_hosted']],
    'the template names the fallback role' => [str_replace('fallback_provider: none', 'fallback_provider: self_hosted', TRANSCRIPTION_ROLES_TEMPLATE), []],
]);

function appRoles(string $provider, string $batch, string $fallback = 'none'): string
{
    return "transcription:\n  provider: {$provider}\n  batch_provider: {$batch}\n  batch_api_key: \"\${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}\"\n  fallback_provider: {$fallback}\n  fallback_api_key: \"\${TMESIS_TRANSCRIPTION_FALLBACK_API_KEY:-}\"\n  api_key: \"\${TMESIS_TRANSCRIPTION_API_KEY:-}\"\n";
}

/**
 * @param  array<string, string>  $placeholders
 */
function appRender(string $template, RenderMode $mode, array $placeholders = [], array $seam = []): string
{
    return app(ConfigRenderer::class)->render($template, edge(), facts($placeholders), $mode, secrets: secretsSeam($seam), kind: TemplateKind::App)->reveal();
}

it('reads a role an app template writes as a placeholder by its resolved value', function (string $template, array $placeholders, string $key, string $line): void {
    $seam = [$key => 'ROLE_SECRET'];

    foreach ([RenderMode::Config, RenderMode::UserData] as $mode) {
        expect(appRender($template, $mode, $placeholders, $seam))->toContain($line)
            ->not->toContain('ROLE_SECRET')
            ->not->toContain($key);
    }
})->with([
    'a batch role fact' => [appRoles('deepgram', '"${BATCH_ROLE}"'), ['BATCH_ROLE' => 'self_hosted'], 'TMESIS_TRANSCRIPTION_BATCH_API_KEY', 'batch_api_key: ""'],
    'a fallback role default' => [appRoles('deepgram', 'deepgram', '"${FALLBACK_ROLE:-self_hosted}"'), [], 'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY', 'fallback_api_key: ""'],
]);

it('keeps a batch key under an explicit role it cannot read, whatever the provider is', function (string $batch): void {
    $document = appRender(appRoles('self_hosted', $batch), RenderMode::UserData, seam: ['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET']);

    expect($document)->toContain('batch_api_key: "BK_SECRET"');
})->with([
    'a tagged scalar' => ['!!str deepgram'],
    'an anchored scalar' => ['&role deepgram'],
    'a double-quoted scalar with an escape' => ['"self\x5Fhosted"'],
]);

it('follows the provider for an absent or empty batch role, and reads quoted forms decoded', function (): void {
    $seam = ['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET'];
    $absent = "transcription:\n  provider: self_hosted\n  batch_api_key: \"\${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}\"\n";

    expect(appRender($absent, RenderMode::UserData, seam: $seam))->toContain('batch_api_key: ""')->not->toContain('BK_SECRET')
        ->and(appRender(appRoles('self_hosted', "''"), RenderMode::UserData, seam: $seam))->not->toContain('BK_SECRET')
        ->and(appRender(appRoles('deepgram', '"self_hosted"'), RenderMode::UserData, seam: $seam))->not->toContain('BK_SECRET')
        ->and(appRender(appRoles('self_hosted', 'deepgram'), RenderMode::UserData, seam: $seam))->toContain('batch_api_key: "BK_SECRET"');
});

it('follows the provider for a batch role written as an empty scalar, in both modes', function (string $batch): void {
    foreach ([RenderMode::Config, RenderMode::UserData] as $mode) {
        expect(appRender(appRoles('self_hosted', $batch), $mode, seam: ['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET']))
            ->toContain('batch_api_key: ""')
            ->not->toContain('BK_SECRET')
            ->not->toContain('TMESIS_TRANSCRIPTION_BATCH_API_KEY');
    }
})->with([
    'a bare key' => [''],
    'a comment only' => ['# follows provider'],
    'a placeholder resolving to empty' => ['${BATCH_ROLE:-}'],
]);

it('keeps the batch key under a batch role that is a mapping with children', function (): void {
    $template = "transcription:\n  provider: self_hosted\n  batch_provider:\n    name: self_hosted\n  batch_api_key: \"\${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}\"\n";

    expect(appRender($template, RenderMode::UserData, seam: ['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET']))->toContain('batch_api_key: "BK_SECRET"');
});

it('renders the live transcription key empty under a self_hosted provider in both modes, and keeps it otherwise', function (): void {
    $seam = ['TMESIS_TRANSCRIPTION_API_KEY' => 'LIVE_SECRET'];
    $selfHosted = appRoles('self_hosted', '""');

    expect(appRender($selfHosted, RenderMode::UserData, seam: $seam))->toContain('api_key: ""')->not->toContain('LIVE_SECRET')
        ->and(appRender($selfHosted, RenderMode::Config, seam: $seam))->toContain('  api_key: ""')->not->toContain('TMESIS_TRANSCRIPTION_API_KEY')
        ->and(appRender(appRoles('deepgram', '""'), RenderMode::UserData, seam: $seam))->toContain('api_key: "LIVE_SECRET"')
        ->and(appRender(appRoles('deepgram', '""'), RenderMode::Config, seam: $seam))->toContain('${TMESIS_TRANSCRIPTION_API_KEY:-}');
});

it('carries a deepgram batch key read back exactly in user-data mode, and keeps the reference in config mode', function (): void {
    $key = 'bk "#\\$ & q: x';
    $secrets = secretsSeam(['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => $key]);
    $values = ['transcription.batch_provider' => 'deepgram'];

    $userData = app(ConfigRenderer::class)->render(TRANSCRIPTION_ROLES_TEMPLATE, edge(), facts([], $values), RenderMode::UserData, secrets: $secrets)->reveal();
    $config = app(ConfigRenderer::class)->render(TRANSCRIPTION_ROLES_TEMPLATE, edge(), facts([], $values), RenderMode::Config, secrets: $secrets)->reveal();

    expect(Yaml::parse($userData)['transcription']['batch_api_key'])->toBe($key)
        ->and($config)->toContain('batch_api_key: "${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}"')
        ->not->toContain($key);
});

it('refuses a fact that is not a string', function (): void {
    configRender('x', ['TMESIS_RECORDING_RETENTION_HOURS' => 72]);
})->throws(EnrollmentException::class, '[TMESIS_RECORDING_RETENTION_HOURS] must be a string');

it('supplies the enrolment route urls while the routes exist, facts overriding them', function (): void {
    $template = 'r: ${TMESIS_ENROLL_REGISTER_URL} i: ${TMESIS_ENROLL_INSTALL_URL} c: ${TMESIS_ENROLL_CONFIG_URL}';

    expect(fn () => configRender($template))->toThrow(EnrollmentException::class, 'TMESIS_ENROLL_REGISTER_URL');

    Route::post('/webhooks/enroll/register', fn () => null)->name('tmesis.enroll.register');
    Route::get('/webhooks/enroll/install', fn () => null)->name('tmesis.enroll.install');
    Route::get('/webhooks/enroll/config', fn () => null)->name('tmesis.enroll.config');
    app('router')->getRoutes()->refreshNameLookups();

    expect(configRender($template))->toBe('r: http://localhost/webhooks/enroll/register i: http://localhost/webhooks/enroll/install c: http://localhost/webhooks/enroll/config')
        ->and(configRender($template, ['TMESIS_ENROLL_REGISTER_URL' => 'https://tsp.test/r']))
        ->toBe('r: https://tsp.test/r i: http://localhost/webhooks/enroll/install c: http://localhost/webhooks/enroll/config')
        ->and(configRender($template, ['TMESIS_ENROLL_CONFIG_URL' => 'https://tsp.test/c']))
        ->toBe('r: http://localhost/webhooks/enroll/register i: http://localhost/webhooks/enroll/install c: https://tsp.test/c');
});

it('renders a role from the configured directory, cloud-init first, in the mode its file says', function (): void {
    $dir = templateDir([
        'cloud-init.edge.yml' => 'cloud: ${TMESIS_NODE_NAME} ${TMESIS_ADMIN_TOKEN}',
        'config.edge.yml' => 'config',
        'config.origin.yml' => 'origin: ${TMESIS_NODE_NAME} ${TMESIS_ADMIN_TOKEN}',
    ]);
    config()->set('tmesis.enrollment.templates', $dir.'/');

    $renderer = app(ConfigRenderer::class);
    $facts = facts(['TMESIS_NODE_NAME' => 'ny.in.tsp24.com']);
    $node = edge();

    $edge = $renderer->renderRole(NodeRole::Edge, $node, $facts);
    $origin = $renderer->renderRole('origin', $node, $facts);

    expect($renderer->directory())->toBe($dir)
        ->and($renderer->templatePath('edge'))->toBe("{$dir}/cloud-init.edge.yml")
        ->and($edge->mode)->toBe(RenderMode::UserData)
        ->and($edge->reveal())->toBe('cloud: ny.in.tsp24.com '.$node->adminToken())
        ->and($origin->mode)->toBe(RenderMode::Config)
        ->and($origin->reveal())->toBe('origin: ny.in.tsp24.com ${TMESIS_ADMIN_TOKEN}')
        ->and($renderer->templatePath(NodeRole::Transcoder))->toBeNull()
        ->and(fn () => $renderer->renderRole('transcoder', $node, $facts))->toThrow(EnrollmentException::class, 'cloud-init.transcoder.yml');
});

it('picks the mode by file name', function (): void {
    expect(RenderMode::forPath('/t/cloud-init.edge.yml'))->toBe(RenderMode::UserData)
        ->and(RenderMode::forPath('/t/config.edge.yml'))->toBe(RenderMode::Config)
        ->and(RenderMode::forPath('/cloud-init.d/edge.yml'))->toBe(RenderMode::Config);
});

it('defaults to the bundled contract templates when no directory is configured', function (?string $dir): void {
    config()->set('tmesis.enrollment.templates', $dir);
    $renderer = app(ConfigRenderer::class);

    expect($renderer->directory())->toBe(ConfigRenderer::bundledDirectory())
        ->and(ConfigRenderer::bundledDirectory())->toEndWith('tests/Fixtures/contract/docs/contract/templates')
        ->and($renderer->templatePath('edge'))->toBe(ConfigRenderer::bundledDirectory().'/config.edge.yml');
})->with([null, '']);

it('has no template when the configured directory does not exist, never falling back to the bundle', function (): void {
    config()->set('tmesis.enrollment.templates', '/nonexistent/tmesis-templates');
    $renderer = app(ConfigRenderer::class);

    expect($renderer->directory())->toBeNull()
        ->and($renderer->templatePath('edge'))->toBeNull()
        ->and(fn () => $renderer->renderRole('edge', edge(), facts([])))->toThrow(EnrollmentException::class, 'set tmesis.enrollment.templates');
});

it('refuses a role that is not one', function (): void {
    app(ConfigRenderer::class)->templatePath('relay');
})->throws(EnrollmentException::class, 'is not one of edge, origin, transcoder, standalone');

it('refuses an unreadable template file', function (): void {
    ConfigRenderer::read('/nonexistent/cloud-init.edge.yml');
})->throws(EnrollmentException::class, 'cannot be read');

it('never shows a rendered document in a dump, cast, export, encoding or log', function (): void {
    $document = new RenderedDocument('token: doc_SECRET_value', RenderMode::UserData);

    ob_start();
    var_dump($document);
    $dumped = (string) ob_get_clean();

    $path = sys_get_temp_dir().'/tmesis-doc-'.bin2hex(random_bytes(4)).'.log';
    config()->set('logging.channels.doc', ['driver' => 'single', 'path' => $path]);
    Log::channel('doc')->info('rendered', ['document' => $document]);
    $logged = (string) file_get_contents($path);
    @unlink($path);

    foreach ([$dumped, print_r($document, true), var_export($document, true), (string) json_encode($document), print_r((array) $document, true), $logged] as $shown) {
        expect($shown)->not->toContain('SECRET');
    }

    expect($dumped)->toContain('user-data')
        ->and($document->reveal())->toBe('token: doc_SECRET_value')
        ->and(fn () => serialize($document))->toThrow(Exception::class);
});

/**
 * @param  array<array-key, mixed>  $answer
 */
function secretsSeam(array $answer): EnrollmentSecrets
{
    return new class($answer) implements EnrollmentSecrets
    {
        /** @param array<array-key, mixed> $answer */
        public function __construct(private array $answer) {}

        public function secrets(NodeEnrollment $node): array
        {
            /** @var array<string, string> */
            return $this->answer;
        }
    };
}

const SUPPLIED_TEMPLATE = "stt: \"\${TMESIS_TRANSCRIPTION_API_KEY:-}\"\nak: \"\${TMESIS_LIVE_ACCESS_KEY:-}\"\nsk: \"\${TMESIS_LIVE_SECRET_KEY:-}\"\n";

it('fills the three supplied secrets from the seam in user-data mode, and the template default for one it leaves out', function (): void {
    $document = app(ConfigRenderer::class)->render(
        SUPPLIED_TEMPLATE,
        edge(),
        facts([]),
        RenderMode::UserData,
        secrets: secretsSeam(['TMESIS_LIVE_ACCESS_KEY' => 'AK_live', 'TMESIS_LIVE_SECRET_KEY' => 'SK_live/+=']),
    );

    expect($document->reveal())->toBe("stt: \"\"\nak: \"AK_live\"\nsk: \"SK_live/+=\"\n");
});

it('takes the template default for every supplied secret when there is no seam', function (): void {
    expect(app(ConfigRenderer::class)->render(SUPPLIED_TEMPLATE, edge(), facts([]), RenderMode::UserData)->reveal())
        ->toBe("stt: \"\"\nak: \"\"\nsk: \"\"\n");
});

it('refuses a supplied secret with no seam answer and no default', function (): void {
    app(ConfigRenderer::class)->render('k: ${TMESIS_LIVE_SECRET_KEY}', edge(), facts([]), RenderMode::UserData);
})->throws(EnrollmentException::class, '${TMESIS_LIVE_SECRET_KEY}');

it('leaves the supplied secrets verbatim in config mode and never asks the seam', function (): void {
    $seam = new class implements EnrollmentSecrets
    {
        public function secrets(NodeEnrollment $node): array
        {
            throw new RuntimeException('config mode asked for the secrets');
        }
    };

    expect(app(ConfigRenderer::class)->render(SUPPLIED_TEMPLATE, edge(), facts([]), RenderMode::Config, secrets: $seam)->reveal())
        ->toBe(SUPPLIED_TEMPLATE);
});

it('refuses a seam answer that is not one of the three, or not a string, naming it and never the value', function (array $answer, string $named): void {
    try {
        app(ConfigRenderer::class)->render(SUPPLIED_TEMPLATE, edge(), facts([]), RenderMode::UserData, secrets: secretsSeam($answer));
        $this->fail('rendered');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain("[{$named}]")
            ->not->toContain('leak_value');
    }
})->with([
    'a minted name' => [['TMESIS_ADMIN_TOKEN' => 'leak_value'], 'TMESIS_ADMIN_TOKEN'],
    'an unknown name' => [['TMESIS_DEEPGRAM' => 'leak_value'], 'TMESIS_DEEPGRAM'],
    'not a string' => [['TMESIS_LIVE_ACCESS_KEY' => ['leak_value']], 'TMESIS_LIVE_ACCESS_KEY'],
]);

it('wraps what the seam answers so a dump shows nothing of it', function (): void {
    $supplied = app(ConfigRenderer::class)->suppliedSecrets(edge(), secretsSeam(['TMESIS_LIVE_SECRET_KEY' => 'SK_SECRET_value']));

    expect($supplied['TMESIS_LIVE_SECRET_KEY'])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and(print_r($supplied, true))->not->toContain('SK_SECRET_value')
        ->and(var_export($supplied, true))->not->toContain('SK_SECRET_value')
        ->and((string) json_encode($supplied))->not->toContain('SK_SECRET_value')
        ->and(app(ConfigRenderer::class)->suppliedSecrets(edge(), null))->toBe([]);
});

/**
 * Every plain value a v0.8.0 role template carries that an app would set (handover
 * 3.7: what were `${TMESIS_*}` facts through v0.7.x), keyed by config path, for the
 * role. `node.name` is not here: the renderer fills it from the enrolment.
 *
 * @return array<string, string|int|bool|list<string>>
 */
function everyValue(string $role): array
{
    $api = ['api.listen' => ':7443', 'api.tls.domain' => 'ny.in.tsp24.com'];
    $webhooks = ['webhooks.url' => 'https://app.example.com/api/webhooks/tmesis'];
    $hook = ['ingest.verify_hook.url' => 'https://app.example.com/api/webhooks/hooks/publish'];
    $live = [
        'delivery.mode' => 'both',
        'delivery.live_destination' => 'live',
        'delivery.origin.domain' => 'ny.live.tsp24.com',
        'storage.destinations.live.bucket' => 'tsp-live',
        'storage.destinations.live.region' => 'ny',
        'storage.destinations.live.endpoint' => 'ny.storage.example.com',
        'storage.destinations.live.prefix' => 'live/',
        'storage.destinations.live.credentials' => 'live',
    ];

    return match ($role) {
        'edge' => $api + $webhooks + $hook + [
            'relays.0.url' => 'rtmp://origin-atl-01.tsp24.com:1935/src',
            'ingest.keys.open' => false,
            'recording.ingest.retention_hours' => 48,
        ],
        'origin' => $api + $webhooks + $hook + $live + [
            'transcription.provider' => 'deepgram',
            'transcription.keyterms_hook.url' => 'https://app.example.com/api/webhooks/hooks/keyterms',
            'preview.access' => 'granted',
            'preview.ice.public_ips' => ['203.0.113.7', '2001:db8::7'],
        ],
        'standalone' => $api + $live + [
            'preview.ice.public_ips' => ['203.0.113.7'],
        ],
        default => $api + $webhooks,
    };
}

it('renders every shipped role template in both modes with every value, changing exactly those values and nothing else', function (string $role): void {
    Log::spy();
    config()->set('tmesis.enrollment.templates', null);

    $renderer = app(ConfigRenderer::class);
    $node = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::from($role))[0];
    $values = everyValue($role);
    $facts = facts([], $values);
    $token = new BootstrapToken('boot_plain', new DateTimeImmutable('+15 minutes'));
    $seam = secretsSeam(['TMESIS_TRANSCRIPTION_API_KEY' => 'dg_SECRET', 'TMESIS_LIVE_ACCESS_KEY' => 'AK_SECRET', 'TMESIS_LIVE_SECRET_KEY' => 'SK_SECRET']);

    $template = ConfigRenderer::read((string) $renderer->templatePath($role));
    $config = $renderer->renderRole($role, $node, $facts, $token, secrets: $seam);
    $userData = $renderer->renderRole($role, $node, $facts, $token, RenderMode::UserData, $seam);

    // The document, parsed, is the template parsed with exactly these values set (and
    // node.name, where the role's template carries it, from the enrolment).
    $expected = Yaml::parse($template);
    $carriesName = TemplateValues::parse($template)->settable('node.name');

    foreach ($values + ($carriesName ? ['node.name' => $node->name] : []) as $path => $value) {
        data_set($expected, $path, $value);
    }

    expect($config->mode)->toBe(RenderMode::Config)
        ->and(Yaml::parse($config->reveal()))->toBe($expected);

    // The text is the template's, line for line: every comment and blank line kept,
    // and only the lines holding a value set differ.
    $before = explode("\n", $template);
    $after = explode("\n", $config->reveal());
    $changed = array_keys(array_diff_assoc($after, $before));

    expect(count($after))->toBe(count($before))
        ->and(count($changed))->toBeLessThanOrEqual(count($values) + 1);

    foreach ($changed as $line) {
        expect(trim($before[$line]))->not->toStartWith('#');
    }

    // Config mode: every ${ left is a reserved name, verbatim, exactly as the template
    // wrote it, and no secret is in the document.
    preg_match_all('/\$\{[^}]*\}/', $config->reveal(), $left);
    preg_match_all('/\$\{((?:'.implode('|', ConfigRenderer::reserved()).'))(?::-[^}]*)?\}/', $template, $reserved);

    expect($left[0])->toBe($reserved[0]);

    foreach ([$node->adminToken(), $node->webhookSecret(), 'dg_SECRET', 'AK_SECRET', 'SK_SECRET', 'boot_plain'] as $secret) {
        expect($config->reveal())->not->toContain($secret);
    }

    // User-data mode, the caller's choice over a config.<role>.yml: nothing left, and
    // the same values.
    expect($userData->mode)->toBe(RenderMode::UserData)
        ->and($userData->reveal())->not->toContain('${')
        ->and($userData->reveal())->toContain($node->adminToken());

    foreach ($values as $path => $value) {
        expect(data_get(Yaml::parse($userData->reveal()), $path))->toBe($value);
    }

    if (str_contains($template, 'TMESIS_LIVE_ACCESS_KEY')) {
        expect($userData->reveal())->toContain('AK_SECRET')->toContain('SK_SECRET');
    }

    if (str_contains($template, 'TMESIS_KEYTERMS_HOOK_SECRET')) {
        expect(data_get(Yaml::parse($userData->reveal()), 'transcription.keyterms_hook.secret'))->toBe($node->keytermsHookSecret());
    }

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
})->with(['edge', 'origin', 'standalone', 'transcoder']);

it('refuses a value the role template does not carry, naming the path and the role', function (string $role, string $path): void {
    config()->set('tmesis.enrollment.templates', null);
    $node = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::from($role))[0];

    app(ConfigRenderer::class)->renderRole($role, $node, facts([], [$path => 'x']));
})->with([
    'an origin-only key on an edge' => ['edge', 'transcription.keyterms_hook.url'],
    'node.name on the standalone, which carries none' => ['standalone', 'node.name'],
    'a typo' => ['origin', 'webhooks.uri'],
    'past a relay that is not there' => ['edge', 'relays.1.url'],
])->throws(EnrollmentException::class, 'does not carry');

it('refuses a value aimed at a mapping or a block sequence', function (string $path): void {
    configRender("webhooks: { url: \"\" }\nrelays:\n  - { url: x }\napi:\n  tls:\n    domain: \"\"\n", [], null, [$path => 'x']);
})->with(['webhooks', 'relays', 'api.tls', 'relays.0'])
    ->throws(EnrollmentException::class, 'not a single value');

it('refuses a value aimed at a key the template fills from a reserved secret', function (string $path): void {
    config()->set('tmesis.enrollment.templates', null);
    $node = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Origin)[0];

    app(ConfigRenderer::class)->renderRole('origin', $node, facts([], [$path => 'leak']));
})->with([
    'webhooks.secret',
    'api.admin_token',
    'ingest.verify_hook.secret',
    'transcription.api_key',
    'transcription.keyterms_hook.secret',
    'storage.credentials.live.access_key',
    'ingest.keys.verify_secrets.0.secret',
])->throws(EnrollmentException::class, 'reserved .env secret');

it('refuses a value that is not a scalar or a list of scalars, or that carries ${, naming the path and never the value', function (mixed $value): void {
    try {
        configRender("webhooks: { url: \"\" }\n", [], null, ['webhooks.url' => $value]);
        $this->fail('rendered');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain('[webhooks.url]')->not->toContain('leak_value');
    }
})->with([
    'null' => [null],
    'a map' => [['a' => 'leak_value']],
    'a nested list' => [[['leak_value']]],
    'an interpolation' => ['https://x/${TMESIS_ADMIN_TOKEN}/leak_value'],
    'infinity' => [INF],
    'an object' => [new stdClass],
]);

it('refuses a path that is not spelled as one', function (string $path): void {
    configRender("a: 1\n", [], null, [$path => 'x']);
})->with(['', 'a..b', '.a', 'a.', 'a b', 'TMESIS_WEBHOOK_URL.$x'])
    ->throws(EnrollmentException::class, 'is not a config path');

it('refuses two values that overlap', function (): void {
    configRender("ice: { public_ips: [\"a\"] }\n", [], null, ['ice.public_ips' => ['b'], 'ice.public_ips.0' => 'c']);
})->throws(EnrollmentException::class, 'overlap');

it('writes values as YAML the node reads back exactly, keeping each line\'s comment', function (): void {
    $template = "node:\n  name: \"\"\nd:\n  domain: \"\"  # the delivery name: install.sh --domain writes it here\n  open: true # stays\n  n: 1\n  f: 0.5\n  list: []\n  word: x\n";
    $values = ['d.domain' => "say \"yes\" \\ no: #1\nnext", 'd.open' => false, 'd.n' => 72, 'd.f' => 2.0, 'd.list' => ['a', 'b c', 1, true], 'd.word' => 'yes'];

    $out = configRender($template, [], null, $values);

    expect($out)->toContain("  domain: \"say \\\"yes\\\" \\\\ no: #1\\nnext\"  # the delivery name: install.sh --domain writes it here\n")
        ->toContain("  open: false # stays\n")
        ->toContain("  f: 2.0\n")
        ->toContain("  list: [\"a\", \"b c\", 1, true]\n")
        ->toContain("  word: \"yes\"\n")
        ->and(Yaml::parse($out)['d'])->toBe($values ? [
            'domain' => "say \"yes\" \\ no: #1\nnext",
            'open' => false,
            'n' => 72,
            'f' => 2.0,
            'list' => ['a', 'b c', 1, true],
            'word' => 'yes',
        ] : []);
});

it('fills node.name from the enrolment, unless a value sets it or the template interpolates it', function (): void {
    $node = edge();

    expect(Yaml::parse(configRender("node: { name: \"\", role: edge }\n", [], $node)))->toBe(['node' => ['name' => $node->name, 'role' => 'edge']])
        ->and(Yaml::parse(configRender("node:\n  name: \"\"\n", [], $node, ['node.name' => 'other.in.tsp24.com'])))->toBe(['node' => ['name' => 'other.in.tsp24.com']])
        ->and(configRender("node:\n  name: \${TMESIS_NODE_NAME}\n", ['TMESIS_NODE_NAME' => 'mine'], $node))->toBe("node:\n  name: mine\n")
        ->and(configRender("other: 1\n", [], $node))->toBe("other: 1\n");
});

it('refuses a placeholder fact the template does not use, pointing at values()', function (): void {
    configRender("webhooks: { url: \"\" }\n", ['TMESIS_WEBHOOK_URL' => 'https://tsp.test/hook']);
})->throws(EnrollmentException::class, 'Since tmesis v0.8.0 the bundled templates carry plain values');

it('refuses a v0.7-shaped facts class against a v0.8 role template, so nothing is dropped silently', function (): void {
    config()->set('tmesis.enrollment.templates', null);
    $node = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::Origin)[0];

    app(ConfigRenderer::class)->renderRole('origin', $node, facts(['TMESIS_NODE_NAME' => $node->name, 'TMESIS_WEBHOOK_URL' => 'https://tsp.test/w']));
})->throws(EnrollmentException::class, '[TMESIS_NODE_NAME], which the template does not use');

it('answers which values a role template carries', function (): void {
    config()->set('tmesis.enrollment.templates', null);
    $renderer = app(ConfigRenderer::class);

    expect($renderer->templateCarries('origin', 'transcription.keyterms_hook.url'))->toBeTrue()
        ->and($renderer->templateCarries('edge', 'transcription.keyterms_hook.url'))->toBeFalse()
        ->and($renderer->templateCarries('edge', 'relays.0.url'))->toBeTrue()
        ->and($renderer->templateCarries('origin', 'webhooks'))->toBeFalse()
        ->and($renderer->templateUses('origin', 'TMESIS_KEYTERMS_HOOK_SECRET'))->toBeTrue()
        ->and($renderer->templateUses('origin', 'TMESIS_WEBHOOK_URL'))->toBeFalse();
});

it('escapes every character the node\'s yaml.v3 would fold or refuse inside a double-quoted value', function (string $char, string $escape): void {
    // Probed against gopkg.in/yaml.v3 v3.0.1 (the node's parser): a raw NEL in a
    // double-quoted scalar is a LINE BREAK there and folds to a space, and a raw C1
    // control, DEL or U+FFFE/U+FFFF is "control characters are not allowed".
    $out = configRender("w: \"\"\n", [], null, ['w' => "a{$char}b"]);

    expect($out)->toBe("w: \"a{$escape}b\"\n")
        ->and(Yaml::parse($out))->toBe(['w' => "a{$char}b"]);
})->with([
    'NEL' => ["\u{0085}", '\u0085'],
    'DEL' => ["\u{007F}", '\u007F'],
    'the first C1 control' => ["\u{0080}", '\u0080'],
    'the last C1 control' => ["\u{009F}", '\u009F'],
    'U+FFFE' => ["\u{FFFE}", '\uFFFE'],
    'U+FFFF' => ["\u{FFFF}", '\uFFFF'],
    'LINE SEPARATOR' => ["\u{2028}", '\u2028'],
    'PARAGRAPH SEPARATOR' => ["\u{2029}", '\u2029'],
    'a C0 control' => ["\u{0001}", '\u0001'],
]);

it('keeps printable Unicode as it is', function (): void {
    expect(configRender("w: \"\"\n", [], null, ['w' => "é ü 中 😀 \u{00A0}"]))->toBe("w: \"é ü 中 😀 \u{00A0}\"\n");
});

it('refuses a value that is not valid UTF-8, naming the path', function (): void {
    configRender("w: \"\"\n", [], null, ['w' => "\xff\xfe"]);
})->throws(EnrollmentException::class, '[w]');

it('refuses a value inside or at a span the renderer cannot read exactly, naming it and leaving the template unchanged', function (string $template, string $path, string $named): void {
    try {
        configRender($template, [], null, [$path => 'new']);
        $this->fail('rendered');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain("[{$path}]")->toContain("[{$named}]");
    }
})->with([
    'a multi-line plain scalar' => ["a:\n  value: first\n    second\n", 'a.value', 'a.value'],
    'inside a sequence item\'s block scalar' => ["a:\n  - |\n    key: content\n", 'a.0.key', 'a.0'],
    'a nested block sequence item' => ["a:\n  - - one\n    - two\n", 'a.0', 'a.0'],
    'inside a flow collection it could not read' => ["a: {\n  foo.bar: x,\n  url: old\n}\n", 'a.url', 'a'],
]);

it('refuses a root path that only exists inside an unreadable span as one the template does not carry', function (): void {
    configRender("a: {\n  foo.bar: x,\n  url: old\n}\n", [], null, ['url' => 'new']);
})->throws(EnrollmentException::class, 'does not carry');

/**
 * What the node reads from a user-data render's secret slots: the Config-mode render
 * parsed, with each leaf that is exactly one `${NAME}` (or `${NAME:-x}`) replaced by
 * that name's value.
 *
 * @param  array<string, string>  $secrets
 */
function withSecrets(mixed $tree, array $secrets): mixed
{
    if (is_array($tree)) {
        return array_map(fn (mixed $leaf): mixed => withSecrets($leaf, $secrets), $tree);
    }

    if (is_string($tree) && preg_match('/^\$\{([A-Z_]+)(?::-[^}]*)?\}$/', $tree, $match) === 1 && isset($secrets[$match[1]])) {
        return $secrets[$match[1]];
    }

    return $tree;
}

const HOSTILE_SECRETS = [
    'TMESIS_STREAM_KEY_SECRET' => 'abc #tail',
    'TMESIS_LIVE_ACCESS_KEY' => '*ak: "quoted" \\back',
    'TMESIS_LIVE_SECRET_KEY' => 'sk\\$HOME "x" #y',
    'TMESIS_TRANSCRIPTION_API_KEY' => '\\$ & "tok": #',
    'TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'bk\\$ "q": #x',
    'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => 'fb: \\back "y" #z',
];

it('writes hostile secrets into every shipped role template so the node reads each back exactly', function (string $role): void {
    config()->set('tmesis.enrollment.templates', null);
    config()->set('tmesis.keys.secret', HOSTILE_SECRETS['TMESIS_STREAM_KEY_SECRET']);

    $renderer = app(ConfigRenderer::class);
    $node = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::from($role))[0];
    $supplied = array_diff_key(HOSTILE_SECRETS, ['TMESIS_STREAM_KEY_SECRET' => true]);

    $config = $renderer->renderRole($role, $node, facts([]), mode: RenderMode::Config);
    $userData = $renderer->renderRole($role, $node, facts([]), mode: RenderMode::UserData, secrets: secretsSeam($supplied))->reveal();

    $expected = withSecrets(Yaml::parse($config->reveal()), HOSTILE_SECRETS + [
        'TMESIS_ADMIN_TOKEN' => $node->adminToken(),
        'TMESIS_WEBHOOK_SECRET' => $node->webhookSecret(),
        'TMESIS_HOOK_SECRET' => $node->hookSecret(),
        'TMESIS_KEYTERMS_HOOK_SECRET' => $node->keytermsHookSecret(),
    ]);

    expect(Yaml::parse($userData))->toBe($expected)
        // The node interpolates the file again at boot: no `${`, and no `\$` its pass
        // would consume (every backslash a secret carries is written escaped, `\\`).
        ->and($userData)->not->toContain('${')
        ->and(preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\\$/', $userData))->toBe(0);
})->with(['edge', 'origin', 'standalone', 'transcoder']);

it('writes a secret plain where the node would, and quotes a whole bare scalar that needs it', function (): void {
    config()->set('tmesis.keys.secret', 'plain-Secret_1/+=');
    $node = edge();

    expect(app(ConfigRenderer::class)->render("a: \${TMESIS_STREAM_KEY_SECRET}\nb: [ \${TMESIS_STREAM_KEY_SECRET} ]\n", $node, facts([]), RenderMode::UserData)->reveal())
        ->toBe("a: plain-Secret_1/+=\nb: [ plain-Secret_1/+= ]\n");

    config()->set('tmesis.keys.secret', 'k: v');

    expect(app(ConfigRenderer::class)->render("a: \${TMESIS_STREAM_KEY_SECRET} # c\nb: { s: \${TMESIS_STREAM_KEY_SECRET} }\n", $node, facts([]), RenderMode::UserData)->reveal())
        ->toBe("a: \"k: v\" # c\nb: { s: \"k: v\" }\n");
});

it('doubles a quote for a single-quoted slot and refuses a value one cannot hold', function (): void {
    expect(app(ConfigRenderer::class)->render("sk: '\${TMESIS_LIVE_SECRET_KEY}'\n", edge(), facts([]), RenderMode::UserData, secrets: secretsSeam(['TMESIS_LIVE_SECRET_KEY' => "it's #1"]))->reveal())
        ->toBe("sk: 'it''s #1'\n");

    app(ConfigRenderer::class)->render("sk: '\${TMESIS_LIVE_SECRET_KEY}'\n", edge(), facts([]), RenderMode::UserData, secrets: secretsSeam(['TMESIS_LIVE_SECRET_KEY' => "line\nbreak"]));
})->throws(EnrollmentException::class, 'single-quoted');

it('refuses, naming the variable and never the value, a secret it cannot place exactly', function (string $template, string $value, string $why): void {
    try {
        app(ConfigRenderer::class)->render($template, edge(), facts([]), RenderMode::UserData, secrets: secretsSeam(['TMESIS_LIVE_SECRET_KEY' => $value]));
        $this->fail('rendered');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain('TMESIS_LIVE_SECRET_KEY')
            ->and($e->getMessage())->toContain($why)
            ->and($e->getMessage())->not->toContain('VALUE');
    }
})->with([
    'part of a plain scalar' => ["url: https://x/\${TMESIS_LIVE_SECRET_KEY}\n", 'VALUE #x', 'part of an unquoted scalar'],
    'inside a block scalar' => ["write_files:\n  - content: |\n      KEY=\${TMESIS_LIVE_SECRET_KEY}\n", 'VALUE "x"', 'block scalar'],
    'a nested expansion' => ["sk: \"\${TMESIS_LIVE_SECRET_KEY}\"\n", 'VALUE${HOME}', 'interpolate again'],
    'not UTF-8' => ["sk: \"\${TMESIS_LIVE_SECRET_KEY}\"\n", "VALUE\xC3", 'UTF-8'],
]);

it('writes an inert secret into a block scalar as it is', function (): void {
    expect(app(ConfigRenderer::class)->render("write_files:\n  - content: |\n      KEY=\${TMESIS_LIVE_SECRET_KEY}\n      'odd quote\nnext: \"\${TMESIS_LIVE_ACCESS_KEY}\"\n", edge(), facts([]), RenderMode::UserData, secrets: secretsSeam(['TMESIS_LIVE_SECRET_KEY' => 'SK/+=_-', 'TMESIS_LIVE_ACCESS_KEY' => 'a"b']))->reveal())
        ->toBe("write_files:\n  - content: |\n      KEY=SK/+=_-\n      'odd quote\nnext: \"a\\\"b\"\n");
});

it('renders every shipped role with the README\'s example facts', function (string $role): void {
    config()->set('tmesis.enrollment.templates', null);
    $node = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::from($role))[0];

    // The README's EnrollmentFacts::values() example, verbatim in its branches.
    $values = ['api.listen' => ':7443', 'api.tls.domain' => $node->name];
    $webhooks = 'https://app.example.com/api/webhooks/tmesis';
    $publishHook = 'https://app.example.com/api/webhooks/hooks/publish';
    $values = match ($node->role) {
        NodeRole::Origin => $values + [
            'webhooks.url' => $webhooks,
            'ingest.verify_hook.url' => $publishHook,
            'delivery.origin.domain' => 'live.tsp24.com',
            'transcription.provider' => 'deepgram',
            'transcription.keyterms_hook.url' => 'https://app.example.com/api/webhooks/hooks/keyterms',
            'preview.access' => 'granted',
            'preview.ice.public_ips' => ['203.0.113.7'],
        ],
        NodeRole::Edge => $values + [
            'webhooks.url' => $webhooks,
            'ingest.verify_hook.url' => $publishHook,
            'relays.0.url' => 'rtmp://origin-atl-01.tsp24.com:1935/src',
        ],
        NodeRole::Transcoder => $values + ['webhooks.url' => $webhooks],
        NodeRole::Standalone => $values + ['delivery.origin.domain' => 'live.example.com'],
    };

    $parsed = Yaml::parse(app(ConfigRenderer::class)->renderRole($role, $node, facts([], $values))->reveal());

    foreach ($values as $path => $value) {
        expect(data_get($parsed, $path))->toBe($value);
    }
})->with(['edge', 'origin', 'standalone', 'transcoder']);

it('refuses a supplied SRT passphrase outside SRT\'s 10 to 79 bytes with the node\'s sentence, never the value', function (string $passphrase): void {
    try {
        app(ConfigRenderer::class)->suppliedSecrets(edge(), secretsSeam(['TMESIS_SRT_PASSPHRASE' => $passphrase]));
        $this->fail('accepted');
    } catch (EnrollmentException $e) {
        expect($e->getMessage())->toContain("TMESIS_SRT_PASSPHRASE is outside SRT's 10 to 79 bytes")
            ->and($e->getMessage())->not->toContain($passphrase);
    }
})->with(['nine-byte', str_repeat('p', 80)]);

it('takes a supplied SRT passphrase of 10 to 79 bytes, and an empty one as not set', function (string $passphrase): void {
    $supplied = app(ConfigRenderer::class)->suppliedSecrets(edge(), secretsSeam(['TMESIS_SRT_PASSPHRASE' => $passphrase]));

    expect($supplied['TMESIS_SRT_PASSPHRASE']->getValue())->toBe($passphrase);
})->with(['ten-bytes!', str_repeat('p', 79), '']);

it('takes the template kind from the file name, never the render mode', function (): void {
    expect(TemplateKind::forPath('/t/config.edge.yml'))->toBe(TemplateKind::Config)
        ->and(TemplateKind::forPath('/t/cloud-init.edge.yml'))->toBe(TemplateKind::App)
        ->and(TemplateKind::forPath('/t/bootstrap.sh'))->toBeNull();
});

it('applies values() and leaves an unused placeholder to the app template on a config document', function (): void {
    config()->set('tmesis.enrollment.templates', templateDir([
        'cloud-init.edge.yml' => "run: \${MY_FACT}\n",
        'config.edge.yml' => "node: { name: \"\" }\nwebhooks: { url: \"\" }\n",
    ]));
    $facts = facts(['MY_FACT' => 'x'], ['webhooks.url' => 'https://tsp.test/hooks']);

    expect(app(ConfigRenderer::class)->render("node: { name: \"\" }\nwebhooks: { url: \"\" }\n", edge(), $facts, RenderMode::Config, kind: TemplateKind::Config)->reveal())
        ->toBe("node: { name: \"ny.in.tsp24.com\" }\nwebhooks: { url: \"https://tsp.test/hooks\" }\n");
});

it('refuses an unused placeholder on a config document when the role has no app template', function (): void {
    config()->set('tmesis.enrollment.templates', templateDir(['config.edge.yml' => "webhooks: { url: \"\" }\n"]));

    app(ConfigRenderer::class)->render("webhooks: { url: \"\" }\n", edge(), facts(['MY_FACT' => 'x']), RenderMode::Config, kind: TemplateKind::Config);
})->throws(EnrollmentException::class, '[MY_FACT]');

it('fills placeholders() and never applies values() on an app template', function (): void {
    $facts = facts(['MY_FACT' => 'x'], ['webhooks.url' => 'https://tsp.test/hooks']);

    expect(app(ConfigRenderer::class)->render("run: \${MY_FACT}\n", edge(), $facts, RenderMode::Config, kind: TemplateKind::App)->reveal())
        ->toBe("run: x\n");
});

it('still refuses an unused placeholder on an app template', function (): void {
    app(ConfigRenderer::class)->render("run: ok\n", edge(), facts(['MY_FACT' => 'x']), RenderMode::Config, kind: TemplateKind::App);
})->throws(EnrollmentException::class, '[MY_FACT]');

it('treats a config.<role>.yml rendered in user-data mode as a config document', function (): void {
    config()->set('tmesis.enrollment.templates', templateDir([
        'config.edge.yml' => "webhooks: { url: \"\", secret: \"\${TMESIS_WEBHOOK_SECRET}\" }\n",
    ]));
    $node = edge();

    expect(app(ConfigRenderer::class)->renderRole('edge', $node, facts([], ['webhooks.url' => 'https://tsp.test/hooks']), mode: RenderMode::UserData)->reveal())
        ->toBe("webhooks: { url: \"https://tsp.test/hooks\", secret: \"{$node->webhookSecret()}\" }\n");
});

it('never fills node.name inside a flow collection an alias elsewhere depends on', function (): void {
    $template = "node: { name: &hostname old.example, role: edge }\napi:\n  tls:\n    domain: *hostname\n";

    expect(configRender($template))->toBe($template);
});

it('refuses a value fact inside a flow collection that holds an anchor, naming the path', function (): void {
    configRender("node: { name: &hostname old.example, role: edge }\napi:\n  tls:\n    domain: *hostname\n", values: ['node.name' => 'new.example']);
})->throws(EnrollmentException::class, 'node.name');

it('takes the bundled standalone floor of 5 as the template says, and never writes limits.disk_floor_gb itself (v0.9.0)', function (): void {
    // Handover 3.9: the default moved 10 to 5 and the standalone template moved with
    // it. The renderer sets only what an app's facts name, so the floor is the
    // template's (5 on standalone, the node's default of 5 where a template omits it).
    config()->set('tmesis.enrollment.templates', null);
    $renderer = app(ConfigRenderer::class);

    foreach (['edge', 'origin', 'standalone', 'transcoder'] as $role) {
        $node = app(SecretMinter::class)->enrollment('ny.in.tsp24.com', NodeRole::from($role))[0];
        $template = Yaml::parse(ConfigRenderer::read((string) $renderer->templatePath($role)));
        $rendered = Yaml::parse($renderer->renderRole($role, $node, facts([], everyValue($role)))->reveal());

        expect(data_get($rendered, 'limits.disk_floor_gb'))->toBe(data_get($template, 'limits.disk_floor_gb'), $role);
    }

    expect(data_get(Yaml::parse(ConfigRenderer::read((string) $renderer->templatePath('standalone'))), 'limits.disk_floor_gb'))->toBe(5);
});

it('names the batch and fallback transcription keys in the origin template only, as the empty-default placeholder (v0.9.3)', function (): void {
    foreach (glob(ConfigRenderer::bundledDirectory().'/config.*.yml') ?: [] as $file) {
        $text = (string) file_get_contents($file);

        if (basename($file) !== 'config.origin.yml') {
            expect($text)->not->toContain('batch_api_key')
                ->and($text)->not->toContain('fallback_api_key')
                ->and($text)->not->toContain('TMESIS_TRANSCRIPTION_BATCH_API_KEY')
                ->and($text)->not->toContain('TMESIS_TRANSCRIPTION_FALLBACK_API_KEY');

            continue;
        }

        expect($text)->toContain('batch_api_key: "${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}"')
            ->and($text)->toContain('fallback_api_key: "${TMESIS_TRANSCRIPTION_FALLBACK_API_KEY:-}"')
            ->and($text)->toContain('TMESIS_TRANSCRIPTION_BATCH_API_KEY')
            ->and($text)->toContain('TMESIS_TRANSCRIPTION_FALLBACK_API_KEY');
    }
});
