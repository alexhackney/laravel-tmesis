<?php

declare(strict_types=1);

pest()->group('provenance');

/**
 * The CI checkout leg reads tmesis at `TMESIS_TAG` in `.github/workflows/tests.yml`,
 * and `PROVENANCE.md`'s Tag row is the pin the bundled copies were taken at. The
 * review of 2026-10-06 (finding 10) found the v0.8.0 repin left the workflow at
 * v0.7.0, so the checkout leg tested the new pin against the previous release. The
 * two are one fact; this holds them equal (`bin/repin` writes both).
 */
it('checks tmesis out in CI at the tag PROVENANCE.md records', function (): void {
    $root = dirname(__DIR__, 2);
    $provenance = (string) file_get_contents($root.'/tests/Fixtures/contract/PROVENANCE.md');
    $workflow = (string) file_get_contents($root.'/.github/workflows/tests.yml');

    expect(preg_match('/^\| Tag \| `([^`]+)` \|$/m', $provenance, $tag))->toBe(1)
        ->and(preg_match('/^\| Commit \| `([0-9a-f]+)`/m', $provenance, $commit))->toBe(1)
        ->and(preg_match('/^  TMESIS_TAG: (\S+)$/m', $workflow, $env))->toBe(1);

    // An untagged pin is checked out by its full commit.
    expect($env[1])->toBe(str_starts_with($tag[1], 'untagged') ? $commit[1] : $tag[1]);
});
