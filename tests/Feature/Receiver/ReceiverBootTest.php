<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('receiver');

/**
 * The reference receiver boots and serves both inbound routes.
 *
 * Shelled out on purpose: the point is that `vendor/bin/testbench` builds a REAL
 * application out of `testbench.yaml` and `workbench/`, which is exactly what the
 * in-process test harness does not do. Nothing else in the suite proves the file the
 * M1 gate points at is loadable.
 *
 * D4: it runs on the primary matrix cell only, because Workbench is a gate tool
 * rather than shipped runtime. `TMESIS_RECEIVER_SMOKE=1` is what turns it on, and the
 * skip names the variable so a local run says why it did nothing.
 */
beforeEach(function (): void {
    if (getenv('TMESIS_RECEIVER_SMOKE') !== '1') {
        $this->markTestSkipped('Set TMESIS_RECEIVER_SMOKE=1 to boot the reference receiver (D4: primary matrix cell only).');
    }
});

it('registers both inbound routes when the workbench application boots', function (): void {
    $process = new Process(['vendor/bin/testbench', 'route:list', '--json'], dirname(__DIR__, 3));
    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $output = $process->getOutput();

    expect($output)->toContain('tmesis.webhooks.receive')
        ->and($output)->toContain('tmesis.hooks.publish')
        ->and($output)->toContain('tmesis.hooks.keyterms');
});

it('passes its own doctor', function (): void {
    $process = new Process(['vendor/bin/testbench', 'tmesis:doctor'], dirname(__DIR__, 3));
    $process->setTimeout(120);
    $process->run();

    // The doctor is what a receiver operator runs first, so a receiver that cannot
    // pass it is not one to point a node at.
    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

    expect($process->getOutput())
        ->toContain('hook route')
        ->toContain('keyterms route')
        ->toContain('contract validation');
});
