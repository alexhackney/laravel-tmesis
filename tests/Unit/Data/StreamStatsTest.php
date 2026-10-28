<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\Stats\PreviewSnapshot;
use AlexHackney\Tmesis\Data\Streams\StreamStats;

it('types the preview block and reads the rc7 counters', function (): void {
    $stats = StreamStats::fromArray($this->fixtureJson('responses/getStreamStats.json'));

    expect($stats->preview())->toBeInstanceOf(PreviewSnapshot::class)
        ->and($stats->preview()?->enabled())->toBeTrue()
        ->and($stats->preview()?->monitors())->toBe(1)
        ->and($stats->preview()?->maxMonitors())->toBe(50)
        ->and($stats->preview()?->sessionsTotal())->toBe(1)
        ->and($stats->session()->latencyMs())->toBe(120)
        ->and($stats->ingest()->droppedPacketsTotal())->toBe(0)
        ->and($stats->ingest()->tsCcErrorsTotal())->toBe(0)
        ->and($stats->recording()[0]->path())->toContain('-ingest-0.ts')
        ->and($stats->recording()[0]->container())->toBe('mpegts');
});

it('answers null for a stream that has never had a tap armed', function (): void {
    $body = $this->fixtureJson('responses/getStreamStats.json');
    $body['preview'] = null;

    expect(StreamStats::fromArray($body)->preview())->toBeNull();
});

it('tells the preview tap rows from the ladder rungs', function (): void {
    $body = $this->fixtureJson('responses/getStreamStats.json');
    $body['transport'][] = ['rung' => '_preview_video'] + $body['transport'][0];
    $body['transport'][] = ['rung' => '_preview_audio'] + $body['transport'][0];

    $rungs = StreamStats::fromArray($body)->transport();

    expect(array_map(static fn ($rung): bool => $rung->isPreviewTap(), $rungs))->toBe([false, false, true, true]);

    // The one place the underscore rule lives: a consumer building the rendition
    // list filters on it rather than remembering the convention.
    $renditions = array_values(array_filter($rungs, static fn ($rung): bool => ! $rung->isPreviewTap()));

    expect(array_map(static fn ($rung): string => $rung->rung(), $renditions))->toBe(['720p', '480p']);
});
