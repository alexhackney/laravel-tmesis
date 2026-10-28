<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Support;

/**
 * The caption keyterms GRAMMAR (tmesis `v0.8.0`, WP12), mirrored from the node's
 * `captions.NormalizeKeyterms`: at most 100 terms, each 1 to 50 characters after
 * trimming, no control characters, valid UTF-8. Duplicates are removed
 * case-insensitively keeping the first spelling, and the order is kept (it is the
 * order the provider is told).
 *
 * This is the one place the rule lives in the package: `StreamSpec::captionsKeyterms()`
 * refuses with it, and anything that answers the node a list (the keyterms hook) can
 * normalise with it. The provider's own token cap (Deepgram, about 500 tokens by the
 * node's conservative estimate) is a NODE fact that depends on its configured
 * provider, and is never mirrored.
 *
 * Known deviation: the trim and the case fold use PHP's Unicode tables (PCRE and
 * mbstring), the node's are its Go release's. They agree on every code point but a
 * handful at the edges of the two Unicode versions (probed 2026-10-08 against Go
 * 1.27: U+180E, which PCRE still trims as a space, and 28 case pairs added in
 * Unicode 17). The node's answer wins; no keyterm a show would use is affected.
 */
final class Keyterms
{
    public const MAX_TERMS = 100;

    public const MAX_TERM_CHARACTERS = 50;

    /**
     * The tail the node appends to every keyterms refusal on the stream routes
     * (`internal/api/streamvalidate.go`).
     */
    public const RULE = '(at most 100 terms, each 1 to 50 characters after trimming, no control characters)';

    /**
     * The first rule the list breaks, in the node's words (`keyterms[2] is empty`),
     * or null when it breaks none.
     *
     * @param  array<array-key, mixed>  $terms
     */
    public static function problem(array $terms): ?string
    {
        if (count($terms) > self::MAX_TERMS) {
            return sprintf('at most %d keyterms, got %d', self::MAX_TERMS, count($terms));
        }

        foreach (array_values($terms) as $index => $raw) {
            if (! is_string($raw)) {
                return "keyterms[{$index}] is not a string";
            }

            if (preg_match('//u', $raw) !== 1) {
                return "keyterms[{$index}] is not valid UTF-8";
            }

            $term = self::trim($raw);
            $length = mb_strlen($term, 'UTF-8');

            if ($length === 0) {
                return "keyterms[{$index}] is empty";
            }

            if ($length > self::MAX_TERM_CHARACTERS) {
                return sprintf('keyterms[%d] is %d characters, at most %d', $index, $length, self::MAX_TERM_CHARACTERS);
            }

            if (preg_match('/\p{Cc}/u', $term) === 1) {
                return "keyterms[{$index}] carries a control character";
            }
        }

        return null;
    }

    /**
     * The list as the node stores it: each term trimmed, duplicates removed
     * case-insensitively keeping the first spelling, the order kept. Null when the
     * list breaks a rule (problem() says which); the node refuses or drops such a
     * list WHOLE, never in part.
     *
     * @param  array<array-key, mixed>  $terms
     * @return list<string>|null
     */
    public static function normalize(array $terms): ?array
    {
        if (self::problem($terms) !== null) {
            return null;
        }

        $out = [];
        $seen = [];

        foreach ($terms as $raw) {
            /** @var string $raw */
            $term = self::trim($raw);
            // Go's strings.ToLower is a per-rune SIMPLE mapping (unicode.ToLower): no
            // final sigma, and U+0130 to a bare `i`. mb_strtolower is the FULL mapping
            // and keeps `ΟΣ` beside `οσ` and `İ` beside `i`, which the node folds.
            $key = mb_convert_case($term, MB_CASE_LOWER_SIMPLE, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $term;
        }

        return $out;
    }

    private static function trim(string $term): string
    {
        // Go's strings.TrimSpace: Unicode white space, NEL and NBSP included.
        return (string) preg_replace('/^[\s\p{Z}\x{85}]+|[\s\p{Z}\x{85}]+$/u', '', $term);
    }
}
