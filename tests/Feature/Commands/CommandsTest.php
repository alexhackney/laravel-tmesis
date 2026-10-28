<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('prints a health table', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $this->artisan('tmesis:health')
        ->expectsOutputToContain('origin-atl-01')
        ->assertSuccessful();
});

it('fails when a node cannot be reached', function (): void {
    Http::fake(['*' => Http::response(['error' => [
        'code' => 'draining', 'message' => 'draining', 'details' => null,
        'request_id' => 'r', 'milestone' => null,
    ]], 503)]);

    $this->artisan('tmesis:health')->assertFailed();
});

it('prints a streams table', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/listStreams.json'))]);

    $this->artisan('tmesis:streams')
        ->expectsOutputToContain('show-2041-main')
        ->assertSuccessful();
});

it('refuses a stream state the node does not have', function (): void {
    $this->artisan('tmesis:streams --state=cooking')->assertFailed();

    Http::assertNothingSent();
});

it('mints the docs example key', function (): void {
    $this->artisan('tmesis:mint-key show-2041-main 7')
        ->expectsOutputToContain('show-2041-main.7.a5e453ff6bb7ec27302c611f2caa4bef986759b280388bcd3d8ee0e829e32ff2')
        ->expectsOutputToContain('show-2041-main.7.a5e4...2ff2')
        ->assertSuccessful();
});

it('substitutes into a publish template', function (): void {
    $this->artisan('tmesis:mint-key show-2041-main 7 --template=srt://edge:9000?streamid={key}')
        ->expectsOutputToContain('srt://edge:9000?streamid=show-2041-main.7.')
        ->assertSuccessful();
});

it('refuses a malformed stream id or epoch', function (string $args): void {
    $this->artisan("tmesis:mint-key {$args}")->assertFailed();
})->with([
    'uppercase stream id' => ['Show-2041 7'],
    'epoch with a leading zero' => ['show-2041-main 07'],
    'non-numeric epoch' => ['show-2041-main seven'],
]);

it('passes the doctor on a healthy configuration', function (): void {
    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('pass')
        ->assertSuccessful();
});

it('fails the doctor for a secret with trailing whitespace, naming the key', function (): void {
    // A .env newline is the classic. The value is never printed.
    config()->set('tmesis.nodes.'.TestCase::NODE.'.webhook_secret', "whsec_tmesis_docs_example\n");

    // Both substrings land on one line, and the runner satisfies one expectation
    // per written line, so this asserts the whole sentence at once.
    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain(
            '[tmesis.nodes.'.TestCase::NODE.'.webhook_secret] has leading or trailing whitespace'
        )
        ->assertFailed();
});

it('warns that the cache driver is not durable', function (): void {
    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('the store is not durable')
        ->assertSuccessful();
});

it('fails when the database driver has no table, naming the publish tag', function (): void {
    config()->set('tmesis.webhooks.dedupe.driver', 'database');

    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain('tmesis-migrations')
        ->assertFailed();
});

it('warns about a delivery claimed over 24 hours ago and never processed', function (): void {
    $this->usesDatabaseDeduper();

    DB::table('tmesis_webhook_deliveries')->insert([
        'id' => (string) Str::ulid(),
        'delivery' => 'stale-one',
        'event' => 'stream.published',
        'node' => TestCase::NODE,
        'occurred_at' => null,
        'attempt' => 1,
        'headers' => '{}',
        'body' => '{}',
        'stored_at' => now()->subHours(25),
        'processed_at' => null,
    ]);

    DB::table('tmesis_webhook_deliveries')->insert([
        'id' => (string) Str::ulid(),
        'delivery' => 'handled-one',
        'event' => 'stream.published',
        'node' => TestCase::NODE,
        'occurred_at' => null,
        'attempt' => 1,
        'headers' => '{}',
        'body' => '{}',
        'stored_at' => now()->subHours(25),
        'processed_at' => now()->subHours(24),
    ]);

    // One expectation, because the whole warning is one written line: the count,
    // and the command that dispatches them again.
    $this->artisan('tmesis:doctor')
        ->expectsOutputToContain(
            'will not retry them: each is a dispatch that failed or never ran. '
            .'Run php artisan tmesis:webhooks:retry to dispatch them again.'
        )
        ->assertSuccessful();

    Artisan::call('tmesis:doctor');

    expect(Artisan::output())->toContain('1 delivery(s) claimed over 24h ago');
});

it('checks a node live when asked', function (): void {
    Http::fake(['*' => Http::response($this->fixtureJson('responses/getHealth.json'))]);

    $this->artisan('tmesis:doctor --live')
        ->expectsOutputToContain('live '.TestCase::NODE)
        ->assertSuccessful();
});
