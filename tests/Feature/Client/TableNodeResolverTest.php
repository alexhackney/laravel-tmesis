<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Client\NodeRegistry;
use AlexHackney\Tmesis\Client\TableNodeResolver;
use AlexHackney\Tmesis\Events\StreamPublished;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Facades\Tmesis;
use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/nodes');

    config()->set('tmesis.nodes_resolver', TableNodeResolver::class);

    DB::table('ingest_servers')->insert([
        [
            'hostname' => 'origin-dc-01', 'type' => 'tmesis', 'webhook_token' => 'wh-origin',
            'admin_token' => Crypt::encryptString('adm-origin'), 'dns_name' => 'origin-dc-01.tsp.internal',
            'ip_address' => '10.0.0.5', 'role' => 'origin', 'deleted_at' => null,
        ],
        [
            'hostname' => 'edge-nodns-01', 'type' => 'tmesis', 'webhook_token' => 'wh-edge',
            'admin_token' => null, 'dns_name' => null,
            'ip_address' => '10.0.0.6', 'role' => 'edge', 'deleted_at' => null,
        ],
        [
            'hostname' => 'nginx-legacy-01', 'type' => 'nginx', 'webhook_token' => 'wh-nginx',
            'admin_token' => null, 'dns_name' => null,
            'ip_address' => '10.0.0.7', 'role' => null, 'deleted_at' => null,
        ],
        [
            'hostname' => 'edge-gone-01', 'type' => 'tmesis', 'webhook_token' => 'wh-gone',
            'admin_token' => null, 'dns_name' => null,
            'ip_address' => '10.0.0.8', 'role' => 'edge', 'deleted_at' => now(),
        ],
    ]);
});

it('finds a node by its name column, decrypting the admin token', function (): void {
    $node = app(TableNodeResolver::class)->find('origin-dc-01');

    expect($node)->not->toBeNull()
        ->and($node->name)->toBe('origin-dc-01')
        ->and($node->webhookSecret)->toBe('wh-origin')
        ->and($node->token)->toBe('adm-origin')
        ->and($node->hookSecret)->toBeNull()
        ->and($node->url)->toBe('');

    // A null hook_secret column falls back to the webhook secret, as config does.
    expect(app(NodeRegistry::class)->hookSecret('origin-dc-01'))->toBe('wh-origin');
});

it('keeps rows of another type and soft-deleted rows out', function (): void {
    $registry = app(NodeRegistry::class);

    expect($registry->has('nginx-legacy-01'))->toBeFalse()
        ->and($registry->has('edge-gone-01'))->toBeFalse()
        ->and($registry->names())->toBe([
            TestCase::NODE,
            'edge-nodns-01',
            'origin-dc-01',
        ]);
});

it('reads a hook secret column when one is configured', function (): void {
    config()->set('tmesis.nodes_table.columns.hook_secret', 'dns_name');

    expect(app(NodeRegistry::class)->hookSecret('origin-dc-01'))->toBe('origin-dc-01.tsp.internal');
});

it('reads a keyterms hook secret column when one is configured, and falls back to the webhook secret without one', function (): void {
    expect(app(TableNodeResolver::class)->find('origin-dc-01')?->keytermsHookSecret)->toBeNull()
        ->and(app(NodeRegistry::class)->keytermsHookSecret('origin-dc-01'))->toBe('wh-origin');

    config()->set('tmesis.nodes_table.columns.keyterms_hook_secret', 'ip_address');
    app()->forgetScopedInstances();

    expect(app(NodeRegistry::class)->keytermsHookSecret('origin-dc-01'))->toBe('10.0.0.5');
});

it('treats a secret that will not decrypt as missing, logging no value', function (): void {
    DB::table('ingest_servers')->where('hostname', 'edge-nodns-01')->update(['admin_token' => 'plain-not-ciphertext']);
    Log::spy();

    expect(app(TableNodeResolver::class)->find('edge-nodns-01')?->token)->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['node'] === 'edge-nodns-01'
        && $context['column'] === 'admin_token'
        && ! str_contains((string) json_encode($context), 'plain-not-ciphertext'));
});

it('renders the url template, and no url when a placeholder is blank', function (): void {
    config()->set('tmesis.nodes_table.url', 'http://{dns_name}:7000/');
    $resolver = app(TableNodeResolver::class);

    expect($resolver->find('origin-dc-01')?->url)->toBe('http://origin-dc-01.tsp.internal:7000')
        ->and($resolver->find('edge-nodns-01')?->url)->toBe('');

    config()->set('tmesis.nodes_table.url', 'http://{ip_address}:7000');

    expect($resolver->find('edge-nodns-01')?->url)->toBe('http://10.0.0.6:7000');
});

it('calls the admin API of a table node that has a url', function (): void {
    config()->set('tmesis.nodes_table.url', 'http://{ip_address}:7000');
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    Tmesis::node('origin-dc-01')->getHealth();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.0.0.5:7000/api/v1/health'
        && $request->header('Authorization') === ['Bearer adm-origin']);
});

it('throws the required-at-use error naming the url for an admin call to a url-less node', function (): void {
    Http::fake();

    expect(fn () => Tmesis::node('origin-dc-01')->getHealth())
        ->toThrow(InvalidConfigurationException::class, 'The config key [tmesis.nodes_resolver[origin-dc-01].url] is required and is not set.');

    Http::assertNothingSent();
});

it('makes one query per lookup, selecting only the columns it needs', function (): void {
    config()->set('tmesis.nodes_table.url', 'http://{ip_address}:7000');
    DB::enableQueryLog();

    $registry = app(NodeRegistry::class);
    $registry->has('origin-dc-01');
    $registry->webhookSecret('origin-dc-01');
    $registry->token('origin-dc-01');

    $queries = DB::getQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toBe(
            'select "hostname", "webhook_token", "admin_token", "ip_address" from "ingest_servers" '
            .'where "type" = ? and "deleted_at" is null and "hostname" = ? limit 1'
        )
        ->and($queries[0]['bindings'])->toBe(['tmesis', 'origin-dc-01']);
});

it('accepts a signed delivery from a table node, and 401s a soft-deleted one', function (): void {
    Event::fake();

    $payload = $this->exampleAsTestNode('stream.published');
    $payload['node'] = 'origin-dc-01';

    $this->signedPost('stream.published', $payload, ['HTTP_X_TMESIS_NODE' => 'origin-dc-01'], 'wh-origin')
        ->assertNoContent();

    Event::assertDispatchedTimes(StreamPublished::class, 1);

    $payload = $this->exampleAsTestNode('stream.live');
    $payload['node'] = 'edge-gone-01';

    $this->signedPost('stream.live', $payload, ['HTTP_X_TMESIS_NODE' => 'edge-gone-01'], 'wh-gone')
        ->assertUnauthorized();
});

it('refuses a node that is soft-deleted between requests', function (): void {
    Event::fake();
    $headers = ['HTTP_X_TMESIS_NODE' => 'origin-dc-01'];

    $payload = $this->exampleAsTestNode('stream.published');
    $payload['node'] = 'origin-dc-01';

    $this->signedPost('stream.published', $payload, $headers, 'wh-origin')->assertNoContent();

    DB::table('ingest_servers')->where('hostname', 'origin-dc-01')->update(['deleted_at' => now()]);
    app()->forgetScopedInstances();

    $payload = $this->exampleAsTestNode('stream.live');
    $payload['node'] = 'origin-dc-01';

    $this->signedPost('stream.live', $payload, $headers, 'wh-origin')->assertUnauthorized();
});

it('names the key for a missing table setting', function (): void {
    config()->set('tmesis.nodes_table.table', '');

    expect(fn () => app(NodeRegistry::class)->has('origin-dc-01'))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.nodes_table.table');
});

it('refuses a where filter that is not a column map', function (): void {
    config()->set('tmesis.nodes_table.where', ['tmesis']);

    expect(fn () => app(TableNodeResolver::class)->find('origin-dc-01'))
        ->toThrow(InvalidConfigurationException::class, 'tmesis.nodes_table.where');
});

it('ignores the soft-delete column when it is disabled', function (): void {
    config()->set('tmesis.nodes_table.soft_delete', null);

    expect(app(TableNodeResolver::class)->find('edge-gone-01')?->webhookSecret)->toBe('wh-gone');
});

it('has no problems with the shipped columns on the default table shape', function (): void {
    config()->set('tmesis.nodes_table.url', 'https://{dns_name}:7000');

    expect(app(TableNodeResolver::class)->problems())->toBe([]);
});

it('names every configured column the table lacks, with the key that names it', function (string $key, string $column): void {
    config()->set("tmesis.nodes_table.{$key}", match ($key) {
        'url' => "https://{{$column}}:7000",
        'where' => [$column => 'tmesis'],
        default => $column,
    });

    expect(app(TableNodeResolver::class)->problems())
        ->toBe(["table [ingest_servers] has no column [{$column}], named in tmesis.nodes_table.{$key}"]);
})->with([
    'name' => ['columns.name', 'host_name'],
    'webhook secret' => ['columns.webhook_secret', 'wh_secret'],
    'hook secret' => ['columns.hook_secret', 'hook_secret'],
    'keyterms hook secret' => ['columns.keyterms_hook_secret', 'keyterms_secret'],
    'admin token' => ['columns.token', 'api_token'],
    'url placeholder' => ['url', 'public_host'],
    'where' => ['where', 'kind'],
    'soft delete' => ['soft_delete', 'removed_at'],
]);

it('names a missing table', function (): void {
    config()->set('tmesis.nodes_table.table', 'no_such_servers');

    expect(app(TableNodeResolver::class)->problems()[0])->toContain('table [no_such_servers] (tmesis.nodes_table.table) does not exist');
});

it('fails the doctor naming the missing column, before any lookup', function (): void {
    config()->set('tmesis.nodes_table.columns.token', 'api_token');

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('has no column [api_token], named in tmesis.nodes_table.columns.token')
        ->assertFailed();
});
