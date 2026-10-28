<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

use AlexHackney\Tmesis\Exceptions\EnrollmentException;

/**
 * Where each `${NAME}` sits in a template, and how a SECRET's value is written there
 * in a user-data render so the node reads back exactly that value.
 *
 * A user-data render puts a secret's literal value where the node would otherwise
 * have interpolated it, so the package has to do what the node's interpolator does
 * (tmesis `internal/config/interpolate.go`, `renderValue`): escape it inside a
 * double-quoted scalar, write it plain when it is safe as a plain scalar and quote it
 * when it is a whole scalar that is not. And because the node still runs that
 * interpolator over the rendered file at boot, the written text must also survive a
 * second pass: a `${` in a value is refused, and a `\$` is only ever written inside
 * double quotes, where its backslash is escaped (`\\$`) and the pass leaves it alone.
 *
 * The context scan is the node's own state machine, byte for byte (quotes, comments,
 * flow depth, the `\$` escape, the double-quoted escape passthrough,
 * `isWholeScalar`), plus one thing the node's does not do: a block scalar (`|`, `>`)
 * is recognised, because a cloud-init template carries files and commands in them
 * and their text is not YAML. There, and anywhere else this cannot place a value
 * exactly (a single-quoted scalar the value cannot live in, a plain scalar the value
 * is only part of), a value outside a conservative set is REFUSED, naming the
 * variable and never the value.
 *
 * @internal
 */
final class SecretPlacement
{
    public const DOUBLE_QUOTED = 'double';

    public const SINGLE_QUOTED = 'single';

    public const PLAIN = 'plain';

    public const BLOCK = 'block';

    /** What a value inside a block scalar, or with no known context, may hold verbatim. */
    private const INERT = '/^[A-Za-z0-9._~+\/=@:-]*\z/';

    private function __construct(
        public readonly string $state,
        public readonly bool $flow,
        public readonly bool $whole,
    ) {}

    /**
     * Every `${` the node would expand, keyed by its byte offset. A `${` in a comment
     * or after a `\$` escape has no entry.
     *
     * @return array<int, self>
     */
    public static function scan(string $text): array
    {
        $contexts = [];
        $state = self::PLAIN;
        $comment = false;
        $flow = 0;
        $length = strlen($text);
        $blockIndent = null;
        $lineStart = true;

        for ($i = 0; $i < $length;) {
            if ($lineStart) {
                $lineStart = false;
                $indent = strspn($text, ' ', $i);
                $end = strpos($text, "\n", $i);
                $line = substr($text, $i, ($end === false ? $length : $end) - $i);

                if ($blockIndent !== null && (trim($line) === '' || $indent > $blockIndent)) {
                    foreach (self::placeholders($line) as $offset) {
                        $contexts[$i + $offset] = new self(self::BLOCK, false, false);
                    }

                    $i = $end === false ? $length : $end + 1;
                    $lineStart = true;

                    continue;
                }

                $blockIndent = null;

                if ($state === self::PLAIN && $flow === 0 && self::opensBlock($line)) {
                    $blockIndent = $indent;
                }
            }

            $c = $text[$i];

            if ($c === "\n") {
                $comment = false;
                $lineStart = true;
                $i++;

                continue;
            }

            if ($comment) {
                $i++;

                continue;
            }

            $next = $text[$i + 1] ?? '';

            if ($c === '\\' && $next === '$') {
                $i += 2;

                continue;
            }

            if ($state === self::DOUBLE_QUOTED && $c === '\\' && $next !== '') {
                $i += 2;

                continue;
            }

            if ($c === '$' && $next === '{') {
                $close = self::matchBrace($text, $i + 1);

                if ($close === null) {
                    $i += 2;

                    continue;
                }

                $contexts[$i] = new self($state, $flow > 0, self::isWholeScalar($text, $i, $close));
                $i = $close + 1;

                continue;
            }

            if ($state === self::PLAIN) {
                if ($c === '#' && ($i === 0 || in_array($text[$i - 1], [' ', "\t", "\n"], true))) {
                    $comment = true;
                } elseif ($c === "'") {
                    $state = self::SINGLE_QUOTED;
                } elseif ($c === '"') {
                    $state = self::DOUBLE_QUOTED;
                } elseif ($c === '[' || $c === '{') {
                    $flow++;
                } elseif (($c === ']' || $c === '}') && $flow > 0) {
                    $flow--;
                }
            } elseif ($state === self::SINGLE_QUOTED && $c === "'") {
                if ($next === "'") {
                    $i += 2;

                    continue;
                }

                $state = self::PLAIN;
            } elseif ($state === self::DOUBLE_QUOTED && $c === '"') {
                $state = self::PLAIN;
            }

            $i++;
        }

        return $contexts;
    }

    /**
     * The text to write for `$name`'s `$value` at this context.
     *
     * @throws EnrollmentException (unplaceableSecret()) naming the variable, never the value
     */
    public static function place(?self $context, string $name, string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw EnrollmentException::unplaceableSecret($name, 'it is not valid UTF-8, which a YAML file cannot carry');
        }

        if (str_contains($value, '${')) {
            throw EnrollmentException::unplaceableSecret($name, 'it carries "${", which the node would interpolate again');
        }

        if ($context === null || $context->state === self::BLOCK) {
            if (preg_match(self::INERT, $value) === 1) {
                return $value;
            }

            throw EnrollmentException::unplaceableSecret($name, $context === null
                ? 'it is not in a YAML context the renderer can place a value in exactly, and the value is not inert'
                : 'it is inside a block scalar, whose text is not YAML, and the value carries characters that would need quoting there');
        }

        return match ($context->state) {
            self::DOUBLE_QUOTED => ConfigRenderer::escape($value),
            self::SINGLE_QUOTED => self::singleQuoted($name, $value),
            default => self::plain($context, $name, $value),
        };
    }

    private static function plain(self $context, string $name, string $value): string
    {
        if (self::plainSafe($value, $context->flow)) {
            return $value;
        }

        if ($context->whole) {
            return ConfigRenderer::quote($value);
        }

        throw EnrollmentException::unplaceableSecret($name, 'it is part of an unquoted scalar and the value needs quoting; wrap the whole scalar in double quotes');
    }

    private static function singleQuoted(string $name, string $value): string
    {
        $bare = str_replace(['"', '\\'], '', $value);

        // escape() touches only quotes, backslashes, line breaks and non-printables;
        // with the first two taken out, any change is one a single quote cannot hold.
        if (str_contains($value, '\\$') || ConfigRenderer::escape($bare) !== $bare) {
            throw EnrollmentException::unplaceableSecret($name, 'it is inside a single-quoted scalar and the value carries a line break, a control character or "\\$"; use double quotes');
        }

        return str_replace("'", "''", $value);
    }

    /**
     * The node's `plainSafe` (written plain, exactly as it is), and stricter in one
     * way: anything the double-quoted escaper would touch (a backslash, a quote, a
     * line break YAML folds, a non-printable character) is quoted instead, which costs
     * nothing and also keeps a `\$` away from the node's second interpolation pass.
     */
    private static function plainSafe(string $value, bool $flow): bool
    {
        if ($value === '' || ConfigRenderer::escape($value) !== $value) {
            return false;
        }

        if (preg_match('/^[\s\p{Z}\x{85}]|[\s\p{Z}\x{85}]\z/u', $value) === 1) {
            return false;
        }

        if (str_contains($value, ': ') || str_ends_with($value, ':') || str_contains($value, ' #')) {
            return false;
        }

        if (str_contains(",[]{}#&*!|>'\"%@`", $value[0])) {
            return false;
        }

        if (str_contains('-?:', $value[0]) && (strlen($value) === 1 || $value[1] === ' ' || $value[1] === "\t")) {
            return false;
        }

        return ! ($flow && strpbrk($value, ',[]{}:') !== false);
    }

    private static function matchBrace(string $text, int $open): ?int
    {
        $depth = 0;
        $length = strlen($text);

        for ($i = $open; $i < $length; $i++) {
            $c = $text[$i];

            if ($c === "\n") {
                return null;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** The node's `isWholeScalar`: the expansion is the whole scalar it sits in. */
    private static function isWholeScalar(string $text, int $start, int $end): bool
    {
        $i = $start - 1;

        while ($i >= 0 && ($text[$i] === ' ' || $text[$i] === "\t")) {
            $i--;
        }

        if ($i >= 0) {
            $c = $text[$i];
            $after = $text[$i + 1];
            $blankAfter = $after === ' ' || $after === "\t";

            if ($c === ':' && ! $blankAfter) {
                return false;
            }

            if ($c === '-' && (! $blankAfter || ($i > 0 && ! in_array($text[$i - 1], [' ', "\t", "\n"], true)))) {
                return false;
            }

            if (! in_array($c, ["\n", "\r", ',', '[', '{', ':', '-'], true)) {
                return false;
            }
        }

        $j = $end + 1;
        $length = strlen($text);

        while ($j < $length && ($text[$j] === ' ' || $text[$j] === "\t")) {
            $j++;
        }

        return $j >= $length || in_array($text[$j], ["\n", "\r", '#', ',', '}', ']'], true);
    }

    /** A line whose value is a block scalar indicator (`key: |`, `- >-`, `key: |2 # c`). */
    private static function opensBlock(string $line): bool
    {
        return preg_match('/(?:^|[:\-])[ \t]+[|>][1-9+\-]{0,2}[ \t]*(?:#.*)?\r?$/', $line) === 1
            || preg_match('/^[ \t]*[|>][1-9+\-]{0,2}[ \t]*(?:#.*)?\r?$/', $line) === 1;
    }

    /**
     * @return list<int>
     */
    private static function placeholders(string $line): array
    {
        preg_match_all('/\$\{/', $line, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $match): int => $match[1], $matches[0]);
    }
}
