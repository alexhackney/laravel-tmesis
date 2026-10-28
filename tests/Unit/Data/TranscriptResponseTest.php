<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\TranscriptCue;
use AlexHackney\Tmesis\Data\Streams\TranscriptResponse;
use AlexHackney\Tmesis\Data\Streams\TranscriptWord;
use AlexHackney\Tmesis\Tests\Contract\Support\SchemaValidator;

/**
 * A two-cue feed: the first cue has no cue-level speaker (the key is absent) and
 * words whose speaker is present and null; the second carries both. `start` of the
 * first word is an integral 1 on the wire.
 *
 * @return array<string, mixed>
 */
function transcriptBody(): array
{
    return [
        'stream' => 'show-2041-main',
        'session' => '01J8ZC8XKWQ4M7T0K3PZ2B9RVN',
        'lang' => 'en',
        'provider' => 'deepgram',
        'cues' => [
            [
                'seq' => 1,
                'provider' => 'deepgram',
                'start' => 1,
                'end' => 3.2,
                'program_date' => '2026-09-22T19:12:02.982Z',
                'text' => 'Good evening.',
                'words' => [
                    ['word' => 'good', 'punctuated_word' => 'Good', 'start' => 1, 'end' => 1.8, 'confidence' => 1, 'speaker' => null],
                    ['word' => 'evening', 'punctuated_word' => 'evening.', 'start' => 1.8, 'end' => 3.2, 'confidence' => 0.98, 'speaker' => null],
                ],
            ],
            [
                'seq' => 2,
                'provider' => 'self_hosted',
                'start' => 3.4,
                'end' => 5.0,
                'program_date' => '2026-09-22T19:12:04.882Z',
                'text' => 'Welcome.',
                'speaker' => 0,
                'words' => [
                    ['word' => 'welcome', 'punctuated_word' => 'Welcome.', 'start' => 3.4, 'end' => 5.0, 'confidence' => 0.97, 'speaker' => 0],
                ],
            ],
        ],
    ];
}

it('is a valid TranscriptResponse at the pin', function (): void {
    SchemaValidator::forOpenApi()->assertValid(
        json_decode((string) json_encode(transcriptBody()), false),
        '/components/schemas/TranscriptResponse',
        'The unit test transcript body',
    );
});

it('reads the envelope and the cues in order', function (): void {
    $transcript = TranscriptResponse::fromArray(transcriptBody());

    expect($transcript->stream())->toBe('show-2041-main')
        ->and($transcript->session())->toBe('01J8ZC8XKWQ4M7T0K3PZ2B9RVN')
        ->and($transcript->lang())->toBe('en')
        ->and($transcript->provider())->toBe('deepgram')
        ->and($transcript->cues())->toHaveCount(2)
        ->and($transcript->cues()[0])->toBeInstanceOf(TranscriptCue::class)
        ->and(array_map(static fn (TranscriptCue $cue): int => $cue->seq(), $transcript->cues()))->toBe([1, 2])
        ->and($transcript->lastSeq())->toBe(2)
        ->and($transcript->toArray())->toBe(transcriptBody());
});

it('answers an empty page with no cues and no last seq', function (): void {
    $transcript = TranscriptResponse::fromArray(['cues' => []] + transcriptBody());

    // Keep the since you sent: there is nothing newer.
    expect($transcript->cues())->toBe([])
        ->and($transcript->lastSeq())->toBeNull();
});

it('reads a cue: floats for the timings, the program date parsed, the speaker optional', function (): void {
    [$first, $second] = TranscriptResponse::fromArray(transcriptBody())->cues();

    // An integral 1 on the wire is still a float here.
    expect($first->start())->toBe(1.0)
        ->and($first->end())->toBe(3.2)
        ->and($first->programDateRaw())->toBe('2026-09-22T19:12:02.982Z')
        ->and($first->programDate()->format('Y-m-d H:i:s.v'))->toBe('2026-09-22 19:12:02.982')
        ->and($first->programDate()->getTimezone()->getName())->toBe('UTC')
        ->and($first->text())->toBe('Good evening.')
        // Absent on the wire.
        ->and($first->speaker())->toBeNull()
        ->and($second->speaker())->toBe(0)
        ->and($first->words())->toHaveCount(2)
        ->and($first->words()[0])->toBeInstanceOf(TranscriptWord::class);
});

it('reads a cue provider only as a string', function (): void {
    expect(TranscriptCue::fromArray(['provider' => 'self_hosted'])->provider())->toBe('self_hosted')
        ->and(TranscriptCue::fromArray([])->provider())->toBe('')
        ->and(TranscriptCue::fromArray(['provider' => 12])->provider())->toBe('');
});

it('reads a word: the two spellings, the timings as floats, the speaker present and nullable', function (): void {
    [$first, $second] = TranscriptResponse::fromArray(transcriptBody())->cues();
    $good = $first->words()[0];
    $welcome = $second->words()[0];

    expect($good->word())->toBe('good')
        ->and($good->punctuatedWord())->toBe('Good')
        ->and($good->start())->toBe(1.0)
        ->and($good->end())->toBe(1.8)
        ->and($good->confidence())->toBe(1.0)
        ->and($good->speaker())->toBeNull()
        ->and($welcome->speaker())->toBe(0)
        ->and($welcome->toArray()['punctuated_word'])->toBe('Welcome.');
});

it('tolerates missing and unknown keys without throwing', function (): void {
    $transcript = TranscriptResponse::fromArray(['x_future' => 1, 'cues' => [['x_future' => 2, 'words' => [[]]], 'not a cue']]);

    $cue = $transcript->cues()[0];

    expect($transcript->stream())->toBe('')
        ->and($transcript->cues())->toHaveCount(1)
        ->and($cue->seq())->toBe(0)
        ->and($cue->start())->toBe(0.0)
        ->and($cue->words()[0]->confidence())->toBe(0.0)
        ->and($cue->words()[0]->speaker())->toBeNull()
        ->and(TranscriptResponse::fromArray([])->cues())->toBe([]);
});
