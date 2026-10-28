<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Support;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;

/**
 * The relay `feed` grammar: what a destination is sent (tmesis `v0.4.0`, 4.2 /
 * EDGE-5; `RelayInput.feed`, `RelayPatch.feed`, `RelayRecord.feed` and the resolved
 * `RelayDestination.feed` on the five `relay.*` events).
 *
 * Three forms. `auto` (the node's default) sends the copy when the source suits the
 * destination and the top ladder rung when it does not and the node encodes;
 * `ingest` sends the publisher's bytes as sent; `rung:<name>` sends one video rung of
 * one of the node's ladders plus its audio, with no second encode. The prefixes are
 * lower case, and a rung name is letters, digits, `-` and `_`, not starting with `-`
 * or `_` (the `RelayInput.feed` description in openapi.json).
 *
 * This validates the GRAMMAR only. Whether the rung exists on the node's ladders is
 * the node's `422`, never mirrored here (the D3 rule: the fake models no ladders,
 * and a builder that guessed would be wrong the day a ladder was renamed).
 */
final class RelayFeed
{
    public const AUTO = 'auto';

    public const INGEST = 'ingest';

    public const RUNG_PREFIX = 'rung:';

    public static function auto(): string
    {
        return self::AUTO;
    }

    public static function ingest(): string
    {
        return self::INGEST;
    }

    /**
     * `rung:<name>`, with the name checked against the grammar.
     */
    public static function rung(string $name): string
    {
        $feed = self::RUNG_PREFIX.$name;

        self::guard($feed);

        return $feed;
    }

    /**
     * The rung name of a `rung:<name>` feed, or null for `auto`, `ingest` and the
     * empty string a record or event carries while nothing is resolved.
     */
    public static function rungName(string $feed): ?string
    {
        if (! str_starts_with($feed, self::RUNG_PREFIX)) {
            return null;
        }

        $name = substr($feed, strlen(self::RUNG_PREFIX));

        return $name === '' ? null : $name;
    }

    /**
     * Refuse a feed the node would refuse on its grammar, with the reason, before it
     * is sent. `$field` names the request field in the exception.
     */
    public static function guard(string $feed, string $field = 'feed'): void
    {
        if ($feed === self::AUTO || $feed === self::INGEST) {
            return;
        }

        if (str_starts_with($feed, self::RUNG_PREFIX)) {
            $name = substr($feed, strlen(self::RUNG_PREFIX));

            // \z, not $: PCRE's $ also matches before a final newline, so "rung:720p\n"
            // would pass here, go out unchanged, and be refused by the node's parser.
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\z/', $name) === 1) {
                return;
            }

            throw InvalidConfigurationException::invalid(
                $field,
                'rung:<name> needs a rung name of letters, digits, - and _, not starting with - or _; '
                ."got [{$feed}]. Whether the rung exists on the node's ladders is the node's 422"
            );
        }

        throw InvalidConfigurationException::invalid(
            $field,
            "must be auto, ingest, or rung:<name>, lower case; got [{$feed}]"
        );
    }
}
