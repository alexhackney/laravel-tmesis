<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\TableNodeResolver;
use AlexHackney\Tmesis\Enrollment\EnrollmentFacts;
use AlexHackney\Tmesis\Enrollment\EnrollmentSecrets;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRepository;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Testing\ArrayNodeRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class CommandTestFacts implements EnrollmentFacts
{
    /**
     * @param  bool  $placeholders  answer the two placeholders commandTemplate() uses
     * @param  array<string, mixed>  $values
     */
    public function __construct(private bool $placeholders = false, private array $values = []) {}

    public function values(NodeEnrollment $node): array
    {
        /** @var array<string, string> */
        return $this->values;
    }

    public function placeholders(NodeEnrollment $node): array
    {
        return $this->placeholders
            ? ['TMESIS_NODE_NAME' => $node->name, 'TMESIS_WEBHOOK_URL' => 'https://tsp.test/api/webhooks/tmesis']
            : [];
    }
}

/**
 * Facts for an app template that still interpolates its non-secrets.
 */
function usePlaceholderFacts(): void
{
    app()->instance(EnrollmentFacts::class, new CommandTestFacts(placeholders: true));
}

function commandTemplate(string $contents = "name: \${TMESIS_NODE_NAME}\nurl: \${TMESIS_WEBHOOK_URL}\ntoken: \${TMESIS_ADMIN_TOKEN}\n"): string
{
    $path = sys_get_temp_dir().'/tmesis-cmd-'.bin2hex(random_bytes(4)).'.yml';
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function enrol(array $parameters): array
{
    $code = Artisan::call('tmesis:enroll-node', $parameters);

    return [$code, Artisan::output()];
}

beforeEach(function (): void {
    $this->repository = ArrayNodeRepository::fake();
    app()->instance(EnrollmentFacts::class, new CommandTestFacts);
});

it('enrols a node and shows the .env block once, with no template available', function (): void {
    Log::spy();
    config()->set('tmesis.enrollment.templates', '/nonexistent/tmesis-templates');

    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--region' => 'ny', '--plan' => 'vc2']);

    expect($code)->toBe(0);
    $this->repository->assertEnrolled('ny.in.tsp24.com')->assertNotRegistered('ny.in.tsp24.com');

    $node = $this->repository->enrolled('ny.in.tsp24.com');

    expect($node->role)->toBe(NodeRole::Edge)
        ->and($node->region)->toBe('ny')
        ->and($node->plan)->toBe('vc2')
        ->and($output)->toContain('.env block only')
        ->and($output)->toContain('Shown once, store it now')
        ->and($output)->toContain($node->envBlock())
        ->and(substr_count($output, $node->adminToken()))->toBe(1)
        ->and($output)->toContain('bootstrap token')
        ->and($output)->not->toContain('install ');

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Http::assertNothingSent();
});

it('prints a token that hashes to the stored sha', function (): void {
    [, $output] = enrol(['name' => 'ny.in.tsp24.com']);

    preg_match('/bootstrap token\s+(\S+)/', $output, $m);

    expect(hash('sha256', $m[1]))->toBe($this->repository->enrolled('ny.in.tsp24.com')->bootstrapTokenSha256);
});

it('fails naming the key when no repository is configured', function (): void {
    app()->forgetInstance(NodeRepository::class);
    app()->offsetUnset(NodeRepository::class);

    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com'])
        ->expectsOutputToContain('[tmesis.enrollment.repository] is required')
        ->doesntExpectOutputToContain('Shown once')
        ->assertFailed();
});

it('refuses a bad role and a bad name without storing anything', function (array $parameters, string $message): void {
    $this->artisan('tmesis:enroll-node', $parameters)->expectsOutputToContain($message)->assertFailed();

    $this->repository->assertNotEnrolled('ny.in.tsp24.com');
})->with([
    [['name' => 'ny.in.tsp24.com', '--role' => 'relay'], 'is not one of edge'],
    [['name' => 'NY_EDGE'], 'is not a hostname'],
]);

it('refuses an enrolled name without --replace, and re-mints with it', function (): void {
    enrol(['name' => 'ny.in.tsp24.com']);
    $first = $this->repository->enrolled('ny.in.tsp24.com');

    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com'])
        ->expectsOutputToContain('already enrolled')
        ->assertFailed();
    expect($this->repository->enrolled('ny.in.tsp24.com'))->toBe($first);

    [$code] = enrol(['name' => 'ny.in.tsp24.com', '--replace' => true, '--role' => 'origin']);
    $second = $this->repository->enrolled('ny.in.tsp24.com');

    expect($code)->toBe(0)
        ->and($second->role)->toBe(NodeRole::Origin)
        ->and($second->adminToken())->not->toBe($first->adminToken())
        ->and($this->repository->pending($first->bootstrapTokenSha256))->toBeNull();
});

it('mints the hook secret as the webhook secret unless asked for a distinct one', function (): void {
    enrol(['name' => 'a.in.tsp24.com']);
    enrol(['name' => 'b.in.tsp24.com', '--distinct-hook-secret' => true]);

    expect($this->repository->enrolled('a.in.tsp24.com')->hasDistinctHookSecret())->toBeFalse()
        ->and($this->repository->enrolled('b.in.tsp24.com')->hasDistinctHookSecret())->toBeTrue();
});

it('mints the keyterms hook secret as the webhook secret unless asked for a distinct one, and prints it in the .env block', function (): void {
    [, $output] = enrol(['name' => 'a.in.tsp24.com', '--role' => 'origin']);
    enrol(['name' => 'b.in.tsp24.com', '--role' => 'origin', '--distinct-keyterms-secret' => true]);

    $a = $this->repository->enrolled('a.in.tsp24.com');
    $b = $this->repository->enrolled('b.in.tsp24.com');

    expect($a->hasDistinctKeytermsHookSecret())->toBeFalse()
        ->and($b->hasDistinctKeytermsHookSecret())->toBeTrue()
        ->and($b->hasDistinctHookSecret())->toBeFalse()
        ->and($output)->toContain('TMESIS_KEYTERMS_HOOK_SECRET='.$a->keytermsHookSecret())
        // The bundled origin template names it, and a config render leaves it for .env.
        ->and($output)->toContain('secret: "${TMESIS_KEYTERMS_HOOK_SECRET:-}"');
});

it('refuses a distinct secret the table resolver has no column to read back, before minting or storing anything', function (string $option, string $column): void {
    config()->set('tmesis.nodes_resolver', TableNodeResolver::class);
    config()->set("tmesis.nodes_table.columns.{$column}", null);

    [$code, $output] = enrol(['name' => 'b.in.tsp24.com', '--role' => 'origin', "--{$option}" => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain("--{$option}")
        ->and($output)->toContain("tmesis.nodes_table.columns.{$column}")
        ->and($this->repository->enrolled('b.in.tsp24.com'))->toBeNull();

    // With the column configured, the same enrolment goes through.
    config()->set("tmesis.nodes_table.columns.{$column}", 'distinct_secret');

    expect(enrol(['name' => 'b.in.tsp24.com', '--role' => 'origin', "--{$option}" => true])[0])->toBe(0);
})->with([
    'keyterms' => ['distinct-keyterms-secret', 'keyterms_hook_secret'],
    'publish hook' => ['distinct-hook-secret', 'hook_secret'],
]);

it('writes the app\'s values into the bundled template at their config paths', function (): void {
    app()->instance(EnrollmentFacts::class, new CommandTestFacts(values: [
        'webhooks.url' => 'https://tsp.test/api/webhooks/tmesis',
        'transcription.keyterms_hook.url' => 'https://tsp.test/api/webhooks/hooks/keyterms',
        'preview.ice.public_ips' => ['203.0.113.7'],
    ]));

    [$code, $output] = enrol(['name' => 'atl.origin.tsp24.com', '--role' => 'origin']);

    expect($code)->toBe(0)
        ->and($output)->toContain('  name: "atl.origin.tsp24.com"')
        ->and($output)->toContain('webhooks: { url: "https://tsp.test/api/webhooks/tmesis", secret: "${TMESIS_WEBHOOK_SECRET}" }')
        ->and($output)->toContain('    url: "https://tsp.test/api/webhooks/hooks/keyterms"')
        ->and($output)->toContain('  ice: { public_ips: ["203.0.113.7"] }');
});

it('stores nothing when a fact names a path the role template does not carry, or a v0.7-style placeholder', function (CommandTestFacts $facts, string $message): void {
    app()->instance(EnrollmentFacts::class, $facts);

    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--role' => 'edge'])
        ->expectsOutputToContain($message)
        ->doesntExpectOutputToContain('Shown once')
        ->assertFailed();

    $this->repository->assertNotEnrolled('ny.in.tsp24.com');
})->with([
    'an origin-only path on an edge' => [new CommandTestFacts(values: ['transcription.keyterms_hook.url' => 'https://x']), '[transcription.keyterms_hook.url]'],
    'placeholders the bundled template no longer uses' => [new CommandTestFacts(placeholders: true), '[TMESIS_NODE_NAME]'],
]);

it('prints the rendered document with the env placeholders left in place', function (): void {
    usePlaceholderFacts();
    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--template' => commandTemplate()]);

    $node = $this->repository->enrolled('ny.in.tsp24.com');

    expect($code)->toBe(0)
        ->and($output)->toContain("name: ny.in.tsp24.com\nurl: https://tsp.test/api/webhooks/tmesis\ntoken: \${TMESIS_ADMIN_TOKEN}")
        ->and(substr_count($output, $node->adminToken()))->toBe(1);
});

it('writes the document to --out with mode 0600', function (): void {
    usePlaceholderFacts();
    $out = sys_get_temp_dir().'/tmesis-out-'.bin2hex(random_bytes(4)).'.yml';
    touch($out);
    chmod($out, 0644);

    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--template' => commandTemplate(), '--out' => $out]);

    clearstatcache();

    expect($code)->toBe(0)
        ->and(fileperms($out) & 0777)->toBe(0600)
        ->and((string) file_get_contents($out))->toStartWith('name: ny.in.tsp24.com')
        ->and($output)->toContain('mode 0600')
        ->and($output)->not->toContain('name: ny.in.tsp24.com');

    @unlink($out);
});

it('keeps the enrolment and still shows the secrets when --out cannot be written', function (): void {
    usePlaceholderFacts();
    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--template' => commandTemplate(), '--out' => '/nonexistent/dir/out.yml']);

    expect($code)->toBe(1)
        ->and($output)->toContain('Could not write the document')
        ->and($output)->toContain('Shown once');
    $this->repository->assertEnrolled('ny.in.tsp24.com');
});

it('renders the configured directory\'s template for the role', function (): void {
    $dir = sys_get_temp_dir().'/tmesis-cmd-dir-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("{$dir}/config.origin.yml", 'origin: ${TMESIS_NODE_NAME} ${TMESIS_WEBHOOK_URL}');
    config()->set('tmesis.enrollment.templates', $dir);
    usePlaceholderFacts();

    [$code, $output] = enrol(['name' => 'atl.origin.tsp24.com', '--role' => 'origin']);
    expect($code)->toBe(0)->and($output)->toContain('origin: atl.origin.tsp24.com https://tsp.test/api/webhooks/tmesis');

    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com'])
        ->expectsOutputToContain('expected cloud-init.edge.yml or config.edge.yml')
        ->assertFailed();
    $this->repository->assertNotEnrolled('ny.in.tsp24.com');
});

it('stores nothing when the template has an unfilled placeholder', function (): void {
    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => commandTemplate('x: ${TMESIS_UNKNOWN}')])
        ->expectsOutputToContain('[${TMESIS_UNKNOWN}]')
        ->doesntExpectOutputToContain('Shown once')
        ->assertFailed();

    $this->repository->assertNotEnrolled('ny.in.tsp24.com');
});

it('fails when a template is given and no facts are configured', function (): void {
    app()->offsetUnset(EnrollmentFacts::class);

    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => commandTemplate()])
        ->expectsOutputToContain('[tmesis.enrollment.facts] is required')
        ->assertFailed();
});

it('fails on an unreadable --template', function (): void {
    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => '/nonexistent.yml'])
        ->expectsOutputToContain('cannot be read')
        ->assertFailed();
});

it('prints a user-data document once, under the shown-once line, with the secrets in it', function (): void {
    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--template' => commandTemplate("t: \${TMESIS_ADMIN_TOKEN}\nb: \${TMESIS_BOOTSTRAP_TOKEN}\n"), '--mode' => 'user-data']);

    $node = $this->repository->enrolled('ny.in.tsp24.com');
    preg_match('/bootstrap token\s+(\S+)/', $output, $m);

    expect($code)->toBe(0)
        ->and(strpos($output, 't: '.$node->adminToken()))->toBeGreaterThan(strpos($output, 'Shown once'))
        ->and($output)->toContain('b: '.$m[1])
        ->and(substr_count($output, 't: '.$node->adminToken()))->toBe(1);
});

it('renders a cloud-init.* --template as user-data by default, and config with --mode=config', function (): void {
    $path = sys_get_temp_dir().'/cloud-init.'.bin2hex(random_bytes(4)).'.yml';
    file_put_contents($path, 't: ${TMESIS_ADMIN_TOKEN}');

    [, $userData] = enrol(['name' => 'a.in.tsp24.com', '--template' => $path]);
    [, $config] = enrol(['name' => 'b.in.tsp24.com', '--template' => $path, '--mode' => 'config']);

    expect($userData)->toContain('t: '.$this->repository->enrolled('a.in.tsp24.com')->adminToken())
        ->and($config)->toContain('t: ${TMESIS_ADMIN_TOKEN}');
});

it('writes a user-data document to --out with mode 0600 and does not print it', function (): void {
    $out = sys_get_temp_dir().'/tmesis-ud-'.bin2hex(random_bytes(4)).'.yml';

    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--template' => commandTemplate('t: ${TMESIS_ADMIN_TOKEN}'), '--mode' => 'user-data', '--out' => $out]);

    clearstatcache();
    $node = $this->repository->enrolled('ny.in.tsp24.com');

    expect($code)->toBe(0)
        ->and(fileperms($out) & 0777)->toBe(0600)
        ->and((string) file_get_contents($out))->toBe('t: '.$node->adminToken())
        ->and($output)->toContain('Wrote the user-data document')
        ->and($output)->not->toContain('t: '.$node->adminToken());

    @unlink($out);
});

it('refuses a --mode that is not one', function (): void {
    usePlaceholderFacts();
    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com', '--template' => commandTemplate(), '--mode' => 'yaml'])
        ->expectsOutputToContain('is not one of config, user-data')
        ->assertFailed();

    $this->repository->assertNotEnrolled('ny.in.tsp24.com');
});

it('renders the bundled contract template for the role by default, in config mode', function (): void {
    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--role' => 'transcoder']);

    $node = $this->repository->enrolled('ny.in.tsp24.com');

    expect($code)->toBe(0)
        ->and($output)->not->toContain('.env block only')
        ->and($output)->toContain('role: transcoder')
        ->and($output)->toContain('name: "ny.in.tsp24.com"')
        ->and($output)->toContain('admin_token: ${TMESIS_ADMIN_TOKEN}')
        // The secrets appear once: in the .env block, not in the config document.
        ->and(substr_count($output, $node->adminToken()))->toBe(1);
});

it('renders a shipped template as user-data when --mode asks, with the supplied secrets in it and in the .env block', function (): void {
    app()->instance(EnrollmentSecrets::class, new class implements EnrollmentSecrets
    {
        public function secrets(NodeEnrollment $node): array
        {
            return ['TMESIS_LIVE_ACCESS_KEY' => 'AK_live', 'TMESIS_LIVE_SECRET_KEY' => 'SK_live'];
        }
    });

    $out = sys_get_temp_dir().'/tmesis-cmd-out-'.bin2hex(random_bytes(4)).'.yml';

    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--role' => 'origin', '--mode' => 'user-data', '--out' => $out]);

    $document = (string) file_get_contents($out);
    @unlink($out);

    $node = $this->repository->enrolled('ny.in.tsp24.com');

    expect($code)->toBe(0)
        ->and($output)->toContain('Wrote the user-data document')
        ->and($document)->toContain('access_key: "AK_live"')
        ->and($document)->toContain('secret_key: "SK_live"')
        ->and($document)->toContain($node->adminToken())
        ->and($document)->not->toContain('${')
        ->and($output)->toContain("TMESIS_LIVE_ACCESS_KEY=AK_live\nTMESIS_LIVE_SECRET_KEY=SK_live")
        ->and($output)->not->toContain('TMESIS_TRANSCRIPTION_API_KEY=');
});

it('carries a supplied batch and fallback key in the origin user-data document, and shows each once in the .env block', function (): void {
    app()->instance(EnrollmentSecrets::class, new class implements EnrollmentSecrets
    {
        public function secrets(NodeEnrollment $node): array
        {
            return ['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET', 'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => 'FB_SECRET'];
        }
    });

    $out = sys_get_temp_dir().'/tmesis-cmd-out-'.bin2hex(random_bytes(4)).'.yml';

    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--role' => 'origin', '--mode' => 'user-data', '--out' => $out]);

    $document = (string) file_get_contents($out);
    @unlink($out);

    expect($code)->toBe(0)
        ->and(substr_count($output, 'BK_SECRET'))->toBe(1)
        ->and(substr_count($output, 'FB_SECRET'))->toBe(1)
        ->and($output)->toContain("TMESIS_TRANSCRIPTION_BATCH_API_KEY=BK_SECRET\nTMESIS_TRANSCRIPTION_FALLBACK_API_KEY=FB_SECRET")
        ->and($document)->toContain('batch_api_key: "BK_SECRET"')
        ->and($document)->toContain('fallback_api_key: "FB_SECRET"')
        ->and($document)->not->toContain('${');
});

it('keeps a supplied transcription key out of the origin config document, which names it by reference', function (): void {
    app()->instance(EnrollmentSecrets::class, new class implements EnrollmentSecrets
    {
        public function secrets(NodeEnrollment $node): array
        {
            return ['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => 'BK_SECRET', 'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => 'FB_SECRET'];
        }
    });

    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com', '--role' => 'origin']);

    expect($code)->toBe(0)
        ->and($output)->toContain('batch_api_key: "${TMESIS_TRANSCRIPTION_BATCH_API_KEY:-}"')
        ->and($output)->toContain('fallback_api_key: "${TMESIS_TRANSCRIPTION_FALLBACK_API_KEY:-}"')
        // Once each: the .env block. The document carries the reference, never the value.
        ->and(substr_count($output, 'BK_SECRET'))->toBe(1)
        ->and(substr_count($output, 'FB_SECRET'))->toBe(1);
});

it('stores nothing when the secrets seam answers a name it may not supply', function (): void {
    app()->instance(EnrollmentSecrets::class, new class implements EnrollmentSecrets
    {
        public function secrets(NodeEnrollment $node): array
        {
            return ['TMESIS_ADMIN_TOKEN' => 'leak_value'];
        }
    });

    $this->artisan('tmesis:enroll-node', ['name' => 'ny.in.tsp24.com'])
        ->expectsOutputToContain('[TMESIS_ADMIN_TOKEN]')
        ->doesntExpectOutputToContain('leak_value')
        ->assertExitCode(1);

    $this->repository->assertNotEnrolled('ny.in.tsp24.com');
});

it('prints the .env block alone over the bundled templates when no EnrollmentFacts is configured', function (): void {
    app()->forgetInstance(EnrollmentFacts::class);
    app()->offsetUnset(EnrollmentFacts::class);

    [$code, $output] = enrol(['name' => 'ny.in.tsp24.com']);

    expect($code)->toBe(0)
        ->and($output)->toContain('no template, or no EnrollmentFacts to render one with; .env block only')
        ->and($output)->not->toContain('role: edge');

    $this->repository->assertEnrolled('ny.in.tsp24.com');
});
