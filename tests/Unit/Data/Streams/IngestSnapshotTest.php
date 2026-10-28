<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\Stats\IngestSnapshot;

it('reads dropped_bytes_total as payload bytes, apart from the packet count (tmesis v0.9.1, K4)', function (): void {
    $ingest = IngestSnapshot::fromArray([
        'dropped_packets_total' => 14,
        'dropped_bytes_total' => 18424,
    ]);

    expect($ingest->droppedBytesTotal())->toBe(18424)
        ->and($ingest->droppedPacketsTotal())->toBe(14);
});

it('reads dropped_bytes_total as zero when an older body lacks it', function (): void {
    expect(IngestSnapshot::fromArray([])->droppedBytesTotal())->toBe(0);
});
