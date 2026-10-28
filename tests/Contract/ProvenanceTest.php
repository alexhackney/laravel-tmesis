<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Tests\Contract\ContractDir;

pest()->group('provenance');

beforeAll(function (): void {
    fwrite(STDERR, PHP_EOL.ContractDir::describe().PHP_EOL);
});

/**
 * @return list<array{0: string, 1: string}> sha256, relative path
 */
function provenanceRows(): array
{
    $path = dirname(__DIR__).'/Fixtures/contract/PROVENANCE.md';
    $markdown = (string) file_get_contents($path);

    // The one bare ``` fence in the file, which is what the acceptance sed matches.
    preg_match('/^```$(.*?)^```$/ms', $markdown, $matches);

    $rows = [];

    foreach (explode("\n", $matches[1] ?? '') as $line) {
        if (preg_match('/^([0-9a-f]{64})  (.+)$/', trim($line), $row) === 1) {
            $rows[] = [$row[1], $row[2]];
        }
    }

    return $rows;
}

/**
 * @return list<string>
 */
function notCopied(): array
{
    $markdown = (string) file_get_contents(dirname(__DIR__).'/Fixtures/contract/PROVENANCE.md');

    preg_match_all('/^- `([^`]+)` not copied$/m', $markdown, $matches);

    return $matches[1];
}

it('lists every artifact in the provenance table', function (): void {
    // 30 rows at rc5: the 28 of rc2 plus the two hold events' schemas. 32 at v0.3.0:
    // the two caption.session_* schemas. 54 at v0.3.1: the 22 generated examples.
    // 56 at v0.4.0: lease.fenced's schema and example. 68 at v0.5.0: the six
    // artifact.* and transfer.* schemas and their examples. 88 at v0.6.0: seven
    // schemas and their examples (five job.*, marker.placed, publish.verify.response),
    // test/vectors/hook.json, and the four role templates with their MD5SUMS. 90 at
    // v0.8.0: keyterms.hook.response's schema (it has no example: it is not a delivery)
    // and test/vectors/keyterms.json. 98 at v0.9.0: the four new node-scoped events'
    // schemas and examples (jobs.paused, jobs.resumed, node.capacity_changed,
    // node.disk_floor_near).
    expect(provenanceRows())->toHaveCount(98)
        ->and(notCopied())->toHaveCount(2);
});

it('hashes every copied artifact to its recorded sha256', function (): void {
    $skip = notCopied();
    $checked = 0;

    foreach (provenanceRows() as [$sha, $relative]) {
        if (in_array($relative, $skip, true)) {
            continue;
        }

        $path = ContractDir::root().'/'.$relative;

        expect(is_file($path))->toBeTrue("Missing contract artifact [{$path}].");
        expect(hash_file('sha256', $path))->toBe(
            $sha,
            "[{$relative}] does not match PROVENANCE.md. This is a repin signal, not a flake."
        );

        $checked++;
    }

    expect($checked)->toBe(96);
});

it('holds exactly the 45 schema files of the pin', function (): void {
    $expected = [
        'artifact.deleted',
        'artifact.finalized',
        'artifact.released',
        'caption.session_ended',
        'caption.session_started',
        'fleet.enroll.request',
        'fleet.enroll.response',
        'fleet.heartbeat.request',
        'fleet.heartbeat.response',
        'job.completed',
        'job.failed',
        'job.progress',
        'job.queued',
        'job.started',
        'jobs.paused',
        'jobs.resumed',
        'keyterms.hook.response',
        'lease.fenced',
        'marker.placed',
        'node.capacity_changed',
        'node.disk_floor_near',
        'node.draining',
        'node.online',
        'publish.verify',
        'publish.verify.response',
        'recording.completed',
        'recording.degraded',
        'recording.failed',
        'recording.registered',
        'recording.started',
        'relay.degraded',
        'relay.failed',
        'relay.reconnected',
        'relay.started',
        'relay.stopped',
        'stream.input_lost',
        'stream.input_returned',
        'stream.live',
        'stream.publish_rejected',
        'stream.published',
        'stream.sealed',
        'stream.unpublished',
        'transfer.authorization_required',
        'transfer.completed',
        'transfer.failed',
    ];

    expect(ContractDir::schemaFiles())->toBe($expected)->toHaveCount(45);
});

it('holds exactly the 40 generated examples of the pin, one per event plus the hook request and response', function (): void {
    $events = [...ContractDir::eventSchemaFiles(), ...ContractDir::HOOK_SCHEMAS];
    sort($events);

    // The examples are the schema set minus the four fleet.* documents, which are
    // request and response bodies rather than deliveries. publish.verify.response
    // (v0.6.0) pairs with its own example like publish.verify does. A schema that
    // arrives without its example, or the reverse, fails here naming the difference.
    expect(ContractDir::exampleFiles())->toBe($events)->toHaveCount(40);
});

it('has a provenance row for every example in the root', function (): void {
    $rows = array_column(provenanceRows(), 1);

    foreach (ContractDir::exampleFiles() as $name) {
        $relative = "docs/contract/examples/{$name}.json";

        expect(in_array($relative, $rows, true))->toBeTrue(
            "[{$relative}] is in the contract root but not in PROVENANCE.md. This is a repin signal: run bin/repin."
        );
    }
});

it('holds exactly the four role templates of the pin and their MD5SUMS', function (): void {
    expect(ContractDir::templateFiles())->toBe([
        'config.edge.yml',
        'config.origin.yml',
        'config.standalone.yml',
        'config.transcoder.yml',
    ]);

    $rows = array_column(provenanceRows(), 1);

    foreach ([...ContractDir::templateFiles(), 'MD5SUMS'] as $file) {
        expect(in_array("docs/contract/templates/{$file}", $rows, true))->toBeTrue(
            "[docs/contract/templates/{$file}] is in the contract root but not in PROVENANCE.md. Run bin/repin."
        );
    }
});

it('verifies every template against the MD5SUMS shipped beside it', function (): void {
    $sums = ContractDir::templateSums();

    // Exactly the four templates, no more and no fewer: MD5SUMS is the node's own
    // statement of the set (handover 3.3), so a file it does not list is a finding.
    expect(array_keys($sums))->toBe(ContractDir::templateFiles());

    foreach ($sums as $file => $md5) {
        expect(md5_file(ContractDir::template($file)))->toBe(
            $md5,
            "[docs/contract/templates/{$file}] does not match MD5SUMS. The copy is torn or the checkout is mid-edit."
        );
    }
});
