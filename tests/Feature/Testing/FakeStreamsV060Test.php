<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Facades\Tmesis;

it('echoes the node default fallback and a null destination_ref on a created stream', function (): void {
    Tmesis::fake();

    $record = Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make());

    expect($record->fallback()->endSeconds())->toBe(3600)
        ->and($record->recording()->ingest()->destinationRef())->toBeNull()
        ->and($record->deliveryMode())->toBe('origin');
});

it('stores destination_ref and fallback.end_seconds from the spec', function (): void {
    Tmesis::fake();

    Tmesis::node()->streams()->createStream(
        'show-2041-main',
        StreamSpec::make()->ingestDestinationRef('csn-vod')->fallbackEndSeconds(600),
    );

    $record = Tmesis::node()->getStream('show-2041-main');

    expect($record->recording()->ingest()->destinationRef())->toBe('csn-vod')
        ->and($record->recording()->ingest()->destination())->toBeNull()
        ->and($record->recording()->ingest()->enabled())->toBeTrue()
        ->and($record->fallback()->endSeconds())->toBe(600);
});

it('applies both fields live on a patch, and a null end_seconds reads the default back', function (): void {
    Tmesis::fake();

    Tmesis::node()->streams()->createStream('show-2041-main', StreamSpec::make()->ingestDestination('/mnt/archive')->fallbackEndSeconds(600));

    $patch = Tmesis::node()->streams()->updateStream(
        'show-2041-main',
        StreamSpec::make()->ingestDestination(null)->ingestDestinationRef('csn-vod')->fallbackEndSeconds(null),
    );

    $record = Tmesis::node()->getStream('show-2041-main');

    expect($patch->applied())->toBe([
        'recording.ingest.destination' => 'live',
        'recording.ingest.destination_ref' => 'live',
        'fallback.end_seconds' => 'live',
    ])
        ->and($patch->pending())->toBe([])
        ->and($record->recording()->ingest()->destination())->toBeNull()
        ->and($record->recording()->ingest()->destinationRef())->toBe('csn-vod')
        ->and($record->fallback()->endSeconds())->toBe(3600);
});
