<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Enrollment\TemplateValues;
use AlexHackney\Tmesis\Tests\Contract\ContractDir;
use Symfony\Component\Yaml\Yaml;

const VALUES_TEMPLATE = <<<'YAML'
# a comment: with a colon
node:
  name: ""   # trailing
  role: origin
ingest:
  srt:  { listen: ":6001", latency_ms: 120 }
  keys:
    verify_secrets:
      - kid: "install"
        secret: ${TMESIS_STREAM_KEY_SECRET}
      - { kid: 'two''s', secret: x }
relays:
- { stream: "*", url: "rtmp://a.invalid/src", mode: copy }
- url: plain # c
preview:
  ice: { udp_listen: ":8189", public_ips: [] }
  multi: {
    a: 1,   # inside
    b: [x, "y, z"]
  }
  after: 2
block: |
  key: not a key
  other: 1
tail: 'single # not a comment'
YAML;

it('addresses every value by its config path, through flow and block collections', function (): void {
    $values = TemplateValues::parse(VALUES_TEMPLATE);

    expect($values->raw('node.name'))->toBe('""')
        ->and($values->raw('node.role'))->toBe('origin')
        ->and($values->raw('ingest.srt.listen'))->toBe('":6001"')
        ->and($values->raw('ingest.srt.latency_ms'))->toBe('120')
        ->and($values->raw('ingest.keys.verify_secrets.0.kid'))->toBe('"install"')
        ->and($values->raw('ingest.keys.verify_secrets.0.secret'))->toBe('${TMESIS_STREAM_KEY_SECRET}')
        ->and($values->raw('ingest.keys.verify_secrets.1.kid'))->toBe("'two''s'")
        ->and($values->raw('relays.0.url'))->toBe('"rtmp://a.invalid/src"')
        ->and($values->raw('relays.1.url'))->toBe('plain')
        ->and($values->raw('preview.ice.public_ips'))->toBe('[]')
        ->and($values->raw('preview.multi.a'))->toBe('1')
        ->and($values->raw('preview.multi.b.1'))->toBe('"y, z"')
        ->and($values->raw('preview.after'))->toBe('2')
        ->and($values->raw('tail'))->toBe("'single # not a comment'");
});

it('knows what kind of thing each path holds, and only scalars and flow sequences are settable', function (): void {
    $values = TemplateValues::parse(VALUES_TEMPLATE);

    expect($values->kind('node'))->toBe(TemplateValues::COLLECTION)
        ->and($values->kind('ingest.srt'))->toBe(TemplateValues::COLLECTION)
        ->and($values->kind('relays'))->toBe(TemplateValues::COLLECTION)
        ->and($values->kind('relays.0'))->toBe(TemplateValues::COLLECTION)
        ->and($values->kind('preview.multi.b'))->toBe(TemplateValues::SEQUENCE)
        ->and($values->kind('block'))->toBe(TemplateValues::BLOCK)
        ->and($values->settable('preview.ice.public_ips'))->toBeTrue()
        ->and($values->settable('node.name'))->toBeTrue()
        ->and($values->settable('node'))->toBeFalse()
        ->and($values->settable('block'))->toBeFalse();
});

it('never reads into a block scalar or a comment', function (): void {
    $values = TemplateValues::parse(VALUES_TEMPLATE);

    expect($values->has('block.key'))->toBeFalse()
        ->and($values->has('key'))->toBeFalse()
        ->and($values->has('other'))->toBeFalse()
        ->and($values->paths())->not->toContain('a comment');
});

it('splices values in place, leaving every other byte alone', function (): void {
    $values = TemplateValues::parse(VALUES_TEMPLATE);

    $out = $values->replace(['node.name' => '"ny"', 'relays.1.url' => '"rtmp://b/src"', 'preview.ice.public_ips' => '["1.2.3.4"]', 'preview.multi.a' => '5']);

    expect($out)->toBe(str_replace(
        ['name: ""   # trailing', 'url: plain # c', 'public_ips: []', 'a: 1,   # inside'],
        ['name: "ny"   # trailing', 'url: "rtmp://b/src" # c', 'public_ips: ["1.2.3.4"]', 'a: 5,   # inside'],
        VALUES_TEMPLATE,
    ));
});

it('refuses overlapping replacements rather than guessing', function (): void {
    expect(TemplateValues::parse(VALUES_TEMPLATE)->replace(['preview.multi.b' => '[]', 'preview.multi.b.0' => '"q"']))->toBeNull();
});

it('marks a key that appears twice as a duplicate, which is not settable', function (): void {
    $values = TemplateValues::parse("a:\n  b: 1\n  b: 2\n");

    expect($values->kind('a.b'))->toBe(TemplateValues::DUPLICATE)
        ->and($values->settable('a.b'))->toBeFalse();
});

it('makes everything under a line it cannot read unaddressable, never misaddressed', function (): void {
    $values = TemplateValues::parse("a:\n  \"we.ird\":\n    url: x\n  ok: 1\n");

    expect($values->has('a.url'))->toBeFalse()
        ->and($values->has('url'))->toBeFalse()
        ->and($values->raw('a.ok'))->toBe('1');
});

it('spells paths as dot-separated keys and indices', function (): void {
    expect(TemplateValues::isPath('relays.0.url'))->toBeTrue()
        ->and(TemplateValues::isPath('transcription.keyterms_hook.url'))->toBeTrue()
        ->and(TemplateValues::isPath('a..b'))->toBeFalse()
        ->and(TemplateValues::isPath(''))->toBeFalse()
        ->and(TemplateValues::isPath("a.\0"))->toBeFalse();
});

it('reads every value of the four bundled role templates', function (string $file): void {
    $text = (string) file_get_contents(ContractDir::template($file));
    $values = TemplateValues::parse($text);

    // Every key the YAML parser finds is a path here, so no value a fact might aim at
    // is invisible to the renderer.
    $flatten = function (array $data, string $prefix = '') use (&$flatten): array {
        $paths = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $paths[] = $path;

            if (is_array($value)) {
                $paths = [...$paths, ...$flatten($value, $path)];
            }
        }

        return $paths;
    };

    $expected = $flatten(Yaml::parse($text));
    sort($expected);
    $paths = $values->paths();
    sort($paths);

    expect($paths)->toBe($expected);
})->with(['config.edge.yml', 'config.origin.yml', 'config.standalone.yml', 'config.transcoder.yml']);

/*
 * The review of 2026-10-06 (findings 3 to 5): anything the reader cannot read EXACTLY is
 * an opaque span. It is never settable, nothing inside it is addressable, and the reader
 * never resumes indexing inside it.
 */

it('reads a multi-line plain scalar as opaque, never as its first line', function (string $template, string $opaque, array $readable): void {
    $values = TemplateValues::parse($template);

    expect($values->kind($opaque))->toBe(TemplateValues::OPAQUE)
        ->and($values->settable($opaque))->toBeFalse();

    foreach ($readable as $path => $raw) {
        expect($values->raw($path))->toBe($raw);
    }
})->with([
    'under a key' => ["a:\n  value: first\n    second\n  next: 1\nb: 2\n", 'a.value', ['a.next' => '1', 'b' => '2']],
    'a sequence item' => ["a:\n  - first\n    second\n  - x\n", 'a.0', ['a.1' => 'x']],
    'on the line under its key' => ["a:\n  text here\nb: 1\n", 'a', ['b' => '1']],
]);

it('reads a nested block sequence as opaque, never as a scalar', function (): void {
    $values = TemplateValues::parse("a:\n  - - one\n    - two\n  - three\n");

    expect($values->kind('a.0'))->toBe(TemplateValues::OPAQUE)
        ->and($values->has('a.0.0'))->toBeFalse()
        ->and($values->has('a.0.1'))->toBeFalse()
        ->and($values->raw('a.1'))->toBe('three');
});

it('never exposes the children of a flow collection it could not read, under any path', function (): void {
    $values = TemplateValues::parse("a: {\n  foo.bar: x,\n  url: old\n}\nb: 2\n");

    expect($values->kind('a'))->toBe(TemplateValues::OPAQUE)
        ->and($values->has('url'))->toBeFalse()
        ->and($values->has('a.url'))->toBeFalse()
        ->and($values->has('foo'))->toBeFalse()
        ->and($values->raw('b'))->toBe('2');
});

it('reads a flow collection holding an anchor, alias or tag as opaque, as a block value is', function (string $template, string $path, string $collection): void {
    $values = TemplateValues::parse($template);

    expect($values->settable($path))->toBeFalse()
        ->and($values->kind($collection))->toBe(TemplateValues::OPAQUE)
        ->and($values->opaqueAncestor($path))->toBe($collection)
        ->and($values->replace([]))->toBe($template);
})->with([
    'an anchored mapping value an alias names' => ["node: { name: &host old.example, role: edge }\napi:\n  tls:\n    domain: *host\n", 'node.name', 'node'],
    'an alias in a mapping' => ["node: { name: *host, role: edge }\n", 'node.name', 'node'],
    'a tag in a mapping' => ["node: { name: !!str old, role: edge }\n", 'node.name', 'node'],
    'an anchored nested collection' => ["node: { name: x, tags: &t [a] }\n", 'node.name', 'node'],
    'an anchor in a sequence' => ["relays: [ &a x, y ]\n", 'relays.1', 'relays'],
    'an alias in a sequence' => ["relays: [ x, *a ]\n", 'relays.0', 'relays'],
    'a tag in a nested sequence' => ["node: { name: x, tags: [ !t y ] }\n", 'node.name', 'node'],
]);

it('reads an unclosed flow collection as the end of everything addressable', function (): void {
    $values = TemplateValues::parse("a: 1\nb: [x,\nc: 2\n");

    expect($values->raw('a'))->toBe('1')
        ->and($values->kind('b'))->toBe(TemplateValues::OPAQUE)
        ->and($values->has('c'))->toBeFalse();
});

it('reads a multi-line quoted scalar as opaque, never indexing its content', function (string $template): void {
    $values = TemplateValues::parse($template);

    expect($values->kind('a'))->toBe(TemplateValues::OPAQUE)
        ->and($values->has('url'))->toBeFalse()
        ->and($values->raw('b'))->toBe('3');
})->with([
    'double' => ["a: \"first\n  url: second\"\nb: 3\n"],
    'double, an escaped quote inside' => ["a: \"first \\\"\n  url: second\"\nb: 3\n"],
    'single' => ["a: 'first\n  url: it''s'\nb: 3\n"],
]);

it('skips a sequence item\'s block scalar whole, whatever its content\'s indentation', function (string $template, string $block): void {
    $values = TemplateValues::parse($template);

    expect($values->kind($block))->toBe(TemplateValues::BLOCK)
        ->and($values->has("{$block}.key"))->toBeFalse()
        ->and($values->has('a.0.key'))->toBeFalse()
        ->and($values->has('key'))->toBeFalse()
        ->and($values->raw('a.1'))->toBe('x');
})->with([
    'at the dash column plus two' => ["a:\n  - |\n    key: content\n  - x\n", 'a.0'],
    'deeper' => ["a:\n  - >-\n        key: content\n  - x\n", 'a.0'],
    'a key in the item' => ["a:\n  - text: |\n      key: content\n    other: 1\n  - x\n", 'a.0.text'],
]);

it('resumes at the next key of the item after a key\'s block scalar', function (): void {
    expect(TemplateValues::parse("a:\n  - text: |\n      key: content\n    other: 1\n")->raw('a.0.other'))->toBe('1');
});

it('reads anchors, aliases and tags as opaque', function (): void {
    $values = TemplateValues::parse("a: &x 1\nb: *x\nc: !!str 2\nd: &m\n  e: 1\nf: 4\n");

    foreach (['a', 'b', 'c', 'd'] as $path) {
        expect($values->kind($path))->toBe(TemplateValues::OPAQUE);
    }

    expect($values->has('d.e'))->toBeFalse()
        ->and($values->raw('f'))->toBe('4');
});

it('reads a flow collection with more lines under it as opaque', function (): void {
    $values = TemplateValues::parse("a: { url: x }\n  stray: 1\nb: 2\n");

    expect($values->kind('a'))->toBe(TemplateValues::OPAQUE)
        ->and($values->has('a.url'))->toBeFalse()
        ->and($values->has('stray'))->toBeFalse()
        ->and($values->raw('b'))->toBe('2');
});

it('addresses the first document only, which is the one the node reads', function (): void {
    $values = TemplateValues::parse("---\na: 1\n---\nb: 2\n");

    expect($values->raw('a'))->toBe('1')
        ->and($values->has('b'))->toBeFalse();
});

it('names the opaque span a path falls inside', function (): void {
    $values = TemplateValues::parse("a:\n  - |\n    key: content\nb: { c: [x,\n  d: 1\n}\n");

    expect($values->opaqueAncestor('a.0.key'))->toBe('a.0')
        ->and($values->opaqueAncestor('a.0'))->toBe('a.0')
        ->and($values->opaqueAncestor('b.d'))->toBe('b')
        ->and($values->opaqueAncestor('a'))->toBeNull();
});
