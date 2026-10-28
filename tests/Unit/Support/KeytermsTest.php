<?php

declare(strict_types=1);

use AlexHackney\Tmesis\Data\Streams\StreamSpec;
use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\Keyterms;

it('normalises as the node stores: trimmed, duplicates removed case-insensitively, first spelling and order kept', function (): void {
    expect(Keyterms::normalize(['  Ilia Topuria ', 'Atlanta', 'ATLANTA', "State\u{00A0}Farm Arena", 'atlanta ']))
        ->toBe(['Ilia Topuria', 'Atlanta', "State\u{00A0}Farm Arena"])
        ->and(Keyterms::normalize([]))->toBe([]);
});

it('folds case the way Go\'s strings.ToLower does, rune by rune (review 9)', function (): void {
    // Probed against `go run` at review time: ToLower("ΟΣ") is "οσ" (no final sigma)
    // and ToLower("İ") is "i", so the node keeps only the first of each pair.
    expect(Keyterms::normalize(['ΟΣ', 'οσ']))->toBe(['ΟΣ'])
        ->and(Keyterms::normalize(['İ', 'i']))->toBe(['İ'])
        ->and(Keyterms::normalize(['ǅ', 'ǆ', 'Ǆ']))->toBe(['ǅ'])
        // Not folded by Go either: a full-mapping ligature stays distinct.
        ->and(Keyterms::normalize(['ﬀ', 'ff']))->toBe(['ﬀ', 'ff']);
});

it('names the first rule a list breaks, in the node\'s words', function (array $terms, string $problem): void {
    expect(Keyterms::problem($terms))->toBe($problem)
        ->and(Keyterms::normalize($terms))->toBeNull();
})->with([
    '101 terms' => [array_fill(0, 101, 'x'), 'at most 100 keyterms, got 101'],
    'empty after trimming' => [['ok', '   '], 'keyterms[1] is empty'],
    '51 characters' => [[str_repeat('é', 51)], 'keyterms[0] is 51 characters, at most 50'],
    'a control character' => [["Ilia\tTopuria"], 'keyterms[0] carries a control character'],
    'not UTF-8' => [["\xff\xfe"], 'keyterms[0] is not valid UTF-8'],
    'not a string' => [[42], 'keyterms[0] is not a string'],
]);

it('takes exactly 100 terms of 50 characters', function (): void {
    expect(Keyterms::problem(array_map(static fn (int $i): string => str_pad((string) $i, 50, 'x'), range(1, 100))))->toBeNull();
});

it('sets, clears and leaves captions.keyterms on the stream body', function (): void {
    expect(StreamSpec::make()->captionsKeyterms(['Ilia Topuria', 'Atlanta'])->toArray())
        ->toBe(['captions' => ['keyterms' => ['Ilia Topuria', 'Atlanta']]])
        // [] and null both clear at the node; the builder sends what it was given.
        ->and(json_encode(StreamSpec::make()->captionsKeyterms([])->toRequestBody()))->toBe('{"captions":{"keyterms":[]}}')
        ->and(json_encode(StreamSpec::make()->captionsKeyterms(null)->toRequestBody()))->toBe('{"captions":{"keyterms":null}}')
        // Omitted keeps: a spec that never called it does not mention it.
        ->and(StreamSpec::make()->captionsEnabled(true)->mentions('captions.keyterms'))->toBeFalse()
        // Re-indexed, so a filtered array still goes out as a JSON array.
        ->and(json_encode(StreamSpec::make()->captionsKeyterms([3 => 'Atlanta'])->toRequestBody()))->toBe('{"captions":{"keyterms":["Atlanta"]}}');
});

it('refuses a keyterms list that breaks the grammar with the node\'s sentence, and leaves the provider cap to the node', function (): void {
    expect(fn () => StreamSpec::make()->captionsKeyterms(['ok', '']))
        ->toThrow(InvalidConfigurationException::class, 'keyterms[1] is empty (at most 100 terms, each 1 to 50 characters after trimming, no control characters)');

    // About 500 provider tokens is the node's 422, never mirrored here: 100 long terms
    // pass the grammar.
    expect(StreamSpec::make()->captionsKeyterms(array_fill(0, 100, str_repeat('word ', 9).'w'))->mentions('captions.keyterms'))->toBeTrue();
});
