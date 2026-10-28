<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Relays\RelayInput;
use AlexHackney\Tmesis\Data\Relays\RelayPatch;

it('never prints the credential through var_dump, print_r or var_export of its debug info', function (): void {
    $input = RelayInput::make('srt://backup-den-02.internal:8890?passphrase=hunter2', id: 'backup');

    ob_start();
    var_dump($input);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain('hunter2')
        ->and(print_r($input, true))->not->toContain('hunter2')
        ->and($input->__debugInfo()['url'])->toBe($input->redacted())
        ->and($input->toArray()['url'])->toContain('hunter2');
});

it('never prints a patched credential through var_dump or print_r', function (): void {
    $patch = RelayPatch::make(url: 'rtmp://cdn.example.com/live/abc123secret', enabled: false);

    ob_start();
    var_dump($patch);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain('abc123secret')
        ->and(print_r($patch, true))->not->toContain('abc123secret')
        ->and($patch->__debugInfo())->toBe(['url' => $patch->redacted(), 'enabled' => false])
        ->and($patch->toArray()['url'])->toContain('abc123secret');
});
