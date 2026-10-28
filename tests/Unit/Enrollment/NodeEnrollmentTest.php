<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\BootstrapToken;
use AlexHackney\Tmesis\Enrollment\NodeEnrollment;
use AlexHackney\Tmesis\Enrollment\NodeRegistration;
use AlexHackney\Tmesis\Enrollment\NodeRole;
use AlexHackney\Tmesis\Exceptions\EnrollmentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

const ADMIN = 'adm_SECRET_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const WEBHOOK = 'whk_SECRET_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const HOOK = 'hok_SECRET_cccccccccccccccccccccccccccccccccc';
const KEYTERMS = 'kth_SECRET_dddddddddddddddddddddddddddddddddd';

function enrollment(string $hook = HOOK, string $name = 'ny.in.tsp24.com', ?string $keyterms = KEYTERMS): NodeEnrollment
{
    return new NodeEnrollment(
        name: $name,
        role: NodeRole::Edge,
        region: 'ny',
        plan: null,
        adminToken: ADMIN,
        webhookSecret: WEBHOOK,
        hookSecret: $hook,
        bootstrapTokenSha256: hash('sha256', 'boot'),
        bootstrapExpiresAt: new DateTimeImmutable('2026-09-27T12:15:00Z'),
        enrolledAt: new DateTimeImmutable('2026-09-27T12:00:00Z'),
        keytermsHookSecret: $keyterms,
    );
}

function expectNoSecret(string $rendered): void
{
    expect($rendered)->not->toContain('SECRET');
}

afterEach(fn () => Carbon::setTestNow());

it('reveals its secrets through the accessors only', function (): void {
    $node = enrollment();

    expect($node->adminToken())->toBe(ADMIN)
        ->and($node->webhookSecret())->toBe(WEBHOOK)
        ->and($node->hookSecret())->toBe(HOOK)
        ->and($node->hasDistinctHookSecret())->toBeTrue()
        ->and(enrollment(WEBHOOK)->hasDistinctHookSecret())->toBeFalse()
        ->and($node->keytermsHookSecret())->toBe(KEYTERMS)
        ->and($node->hasDistinctKeytermsHookSecret())->toBeTrue();
});

it('takes the webhook secret as its keyterms hook secret when rebuilt without one', function (): void {
    // A repository that stores no separate column rebuilds with the constructor as it
    // did before v0.8.0, and the registry falls back to the webhook secret the same way.
    $node = enrollment(keyterms: null);

    expect($node->keytermsHookSecret())->toBe(WEBHOOK)
        ->and($node->hasDistinctKeytermsHookSecret())->toBeFalse();
});

it('shows nothing that signs in var_dump and print_r', function (): void {
    $node = enrollment();

    ob_start();
    var_dump($node);
    $dumped = (string) ob_get_clean();

    expectNoSecret($dumped);
    expectNoSecret(print_r($node, true));
    expect($dumped)->toContain('ny.in.tsp24.com')->toContain('[redacted]');
});

it('shows nothing that signs in var_export, json_encode or an array cast', function (): void {
    $node = enrollment();

    expectNoSecret(var_export($node, true));
    expectNoSecret((string) json_encode($node));
    expectNoSecret(print_r((array) $node, true));
    expectNoSecret((string) json_encode((array) $node));
    expect((string) json_encode($node))->toContain('ny.in.tsp24.com');
});

it('refuses to be serialised', function (): void {
    serialize(enrollment());
})->throws(Exception::class);

it('shows nothing that signs in a log context', function (): void {
    $path = sys_get_temp_dir().'/tmesis-enrol-'.bin2hex(random_bytes(4)).'.log';
    config()->set('logging.channels.enrolment', ['driver' => 'single', 'path' => $path]);

    Log::channel('enrolment')->info('enrolled', ['node' => enrollment(), 'token' => new BootstrapToken('tok_SECRET_x', new DateTimeImmutable)]);

    $logged = (string) file_get_contents($path);
    @unlink($path);

    expect($logged)->toContain('enrolled');
    expectNoSecret($logged);
});

it('renders the .env block with the fleet stream key secret', function (): void {
    expect(enrollment()->envBlock())->toBe(
        'TMESIS_ADMIN_TOKEN='.ADMIN."\n"
        .'TMESIS_WEBHOOK_SECRET='.WEBHOOK."\n"
        .'TMESIS_HOOK_SECRET='.HOOK."\n"
        .'TMESIS_KEYTERMS_HOOK_SECRET='.KEYTERMS."\n"
        .'TMESIS_STREAM_KEY_SECRET=sk_tmesis_docs_example'."\n"
    );
});

it('adds an SRT passphrase the secrets seam supplied as a .env line, quoted when it must be', function (): void {
    expect(enrollment()->envBlock(['TMESIS_SRT_PASSPHRASE' => new SensitiveParameterValue('pass phrase SECRET')]))
        ->toEndWith("TMESIS_SRT_PASSPHRASE=\"pass phrase SECRET\"\n");
});

it('adds the transcription keys the secrets seam supplied as .env lines, and no line for a key it did not supply', function (): void {
    expect(enrollment()->envBlock([
        'TMESIS_TRANSCRIPTION_BATCH_API_KEY' => new SensitiveParameterValue('BK_SECRET'),
        'TMESIS_TRANSCRIPTION_FALLBACK_API_KEY' => new SensitiveParameterValue('FB_SECRET'),
    ]))->toEndWith("TMESIS_TRANSCRIPTION_BATCH_API_KEY=BK_SECRET\nTMESIS_TRANSCRIPTION_FALLBACK_API_KEY=FB_SECRET\n");

    expect(enrollment()->envBlock(['TMESIS_TRANSCRIPTION_BATCH_API_KEY' => new SensitiveParameterValue('BK_SECRET')]))
        ->not->toContain('TMESIS_TRANSCRIPTION_FALLBACK_API_KEY');

    expect(enrollment()->envBlock())->not->toContain('TRANSCRIPTION');
});

it('comments the stream key secret out when it is blank', function (): void {
    config()->set('tmesis.keys.secret', '');

    expect(enrollment()->envBlock())
        ->toContain('# TMESIS_STREAM_KEY_SECRET is unset')
        ->not->toContain('TMESIS_STREAM_KEY_SECRET=');
});

it('quotes a stream key secret a dotenv parser would misread', function (): void {
    config()->set('tmesis.keys.secret', 'has space#and"quote');

    expect(enrollment()->envBlock())->toContain('TMESIS_STREAM_KEY_SECRET="has space#and\"quote"');
});

it('names the five env placeholders', function (): void {
    expect(enrollment()->envPlaceholders())->toBe([
        'TMESIS_ADMIN_TOKEN', 'TMESIS_WEBHOOK_SECRET', 'TMESIS_HOOK_SECRET', 'TMESIS_KEYTERMS_HOOK_SECRET', 'TMESIS_STREAM_KEY_SECRET',
    ]);
});

it('knows when its bootstrap token has expired', function (): void {
    $node = enrollment();

    expect($node->bootstrapExpired(new DateTimeImmutable('2026-09-27T12:14:59Z')))->toBeFalse()
        ->and($node->bootstrapExpired(new DateTimeImmutable('2026-09-27T12:15:00Z')))->toBeTrue();

    Carbon::setTestNow('2026-09-27T12:20:00Z');

    expect($node->bootstrapExpired())->toBeTrue();
});

it('refuses a name that is not a hostname', function (string $name): void {
    enrollment(name: $name);
})->with(['', 'Edge-NY', 'edge_ny', '-edge', 'edge.', 'a b'])->throws(EnrollmentException::class, 'is not a hostname');

it('redacts a bootstrap token', function (): void {
    $token = new BootstrapToken('tok_SECRET_x', new DateTimeImmutable('2026-09-27T12:15:00Z'));

    expect($token->plain())->toBe('tok_SECRET_x')
        ->and($token->sha256())->toBe(BootstrapToken::hash('tok_SECRET_x'));

    expectNoSecret(print_r($token, true));
    expectNoSecret(var_export($token, true));
    expectNoSecret((string) json_encode($token));
    expectNoSecret(print_r((array) $token, true));
    expect(fn () => serialize($token))->toThrow(Exception::class);
});

it('lists the roles', function (): void {
    expect(NodeRole::values())->toBe(['edge', 'origin', 'transcoder', 'standalone']);
});

it('holds a registration and refuses a bad one', function (): void {
    $at = new DateTimeImmutable('2026-09-27T12:05:00Z');
    $registration = new NodeRegistration('v0.6.0', 'https://ny.in.tsp24.com:7443', $at);

    expect($registration->version)->toBe('v0.6.0')
        ->and($registration->apiUrl)->toBe('https://ny.in.tsp24.com:7443')
        ->and($registration->registeredAt)->toBe($at)
        ->and(NodeRegistration::isUrl('http://10.0.0.5:7000'))->toBeTrue()
        ->and(NodeRegistration::isUrl('ftp://x'))->toBeFalse()
        ->and(NodeRegistration::isUrl('/relative'))->toBeFalse()
        ->and(fn () => new NodeRegistration(' ', 'https://x', $at))->toThrow(EnrollmentException::class, '[version]')
        ->and(fn () => new NodeRegistration('v0.6.0', 'nope', $at))->toThrow(EnrollmentException::class, '[api_url]');

    $receiveOnly = new NodeRegistration('v0.6.0', null, $at, doctor: ['exit' => 0]);

    expect($receiveOnly->isReceiveOnly())->toBeTrue()
        ->and($receiveOnly->doctor)->toBe(['exit' => 0])
        ->and($receiveOnly->bench)->toBeNull()
        ->and($registration->isReceiveOnly())->toBeFalse();
});

it('derives the admin url from a version report where cleartext is allowed, and never guesses one', function (?string $listen, ?string $scheme, ?string $domain, ?string $expected): void {
    expect(NodeRegistration::deriveUrl($listen, $scheme, $domain, allowCleartext: true))->toBe($expected);
})->with([
    'ACME: the domain and the listen port' => [':7443', 'https', 'ny.in.tsp24.com', 'https://ny.in.tsp24.com:7443'],
    'ACME wins over a listen host' => ['0.0.0.0:7443', 'https', 'ny.in.tsp24.com', 'https://ny.in.tsp24.com:7443'],
    'ACME with no listen port' => [null, 'https', 'ny.in.tsp24.com', null],
    'a named host' => ['api.ny.tsp24.com:7000', 'http', null, 'http://api.ny.tsp24.com:7000'],
    'a private address' => ['10.0.0.9:7000', 'http', '', 'http://10.0.0.9:7000'],
    'an IPv6 host' => ['[2001:db8::7]:7443', 'https', null, 'https://[2001:db8::7]:7443'],
    'hostless' => [':7000', 'http', null, null],
    'IPv4 wildcard' => ['0.0.0.0:7000', 'http', null, null],
    'IPv6 wildcard' => ['[::]:7000', 'http', null, null],
    'loopback' => ['127.0.0.1:7000', 'http', null, null],
    'IPv6 loopback' => ['[::1]:7000', 'http', null, null],
    'localhost' => ['localhost:7000', 'http', null, null],
    'no scheme reported' => ['10.0.0.9:7000', null, null, null],
    'no port' => ['10.0.0.9', 'http', null, null],
    'port out of range' => ['10.0.0.9:70000', 'http', null, null],
    'nothing reported' => [null, null, null, null],
]);

it('derives no http url by default, so a cleartext report registers receive-only', function (): void {
    expect(NodeRegistration::deriveUrl('api.ny.tsp24.com:7000', 'http', null))->toBeNull()
        ->and(NodeRegistration::deriveUrl('10.0.0.9:7000', 'http', ''))->toBeNull()
        ->and(NodeRegistration::deriveUrl('10.0.0.9:7443', 'https', null))->toBe('https://10.0.0.9:7443')
        ->and(NodeRegistration::deriveUrl(':7443', 'http', 'ny.in.tsp24.com'))->toBe('https://ny.in.tsp24.com:7443');
});
