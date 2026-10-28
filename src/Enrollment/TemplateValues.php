<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Enrollment;

/**
 * Where each value of a YAML template sits in its TEXT, addressed by config path, and
 * the one edit the renderer makes: splice a new value's text over an old one.
 *
 * Since tmesis `v0.8.0` the role templates interpolate secrets only and every other
 * setting is a plain YAML value (handover 3.7, tmesis decision D9), so a render
 * writes values at their keys. This is not a YAML parser and the document is never
 * round-tripped through one: every comment, blank line and layout choice survives
 * byte for byte, and only the spans named are replaced.
 *
 * It reads the subset the templates are written in: block mappings; block sequences
 * (`- ` items, addressed by index); flow mappings and sequences, on one line or
 * several; plain, single- and double-quoted scalars on one line, with trailing
 * comments; and block scalars (`|`, `>`), which are skipped whole and are never a
 * settable value.
 *
 * **Anything it cannot read EXACTLY is an opaque span** (the review of 2026-10-06):
 * a multi-line plain or quoted scalar, a nested block sequence (`- - x`), a flow
 * collection it cannot parse, an anchor, alias or tag, a line that is neither a key
 * nor an item (which makes the collection holding it opaque), and a second document
 * (the node reads the first). An opaque span is never settable, nothing inside it is
 * addressable (opaqueAncestor() names it), and the reader never resumes indexing
 * inside it: it skips to the span's closing bracket or quote, and past every line
 * nested deeper than the span's owner.
 *
 * A path is dot-separated keys and indices: `node.name`, `ingest.verify_hook.url`,
 * `relays.0.url`, `preview.ice.public_ips`. A key this cannot spell as a path (a
 * quoted `"we.ird"`) is read, and everything under it is unaddressable.
 */
final class TemplateValues
{
    /** A scalar value: settable. */
    public const SCALAR = 'scalar';

    /** A flow sequence (`[]`, `[a, b]`): settable, as a whole. */
    public const SEQUENCE = 'sequence';

    /** A mapping, or a block sequence's parent: holds values, is not one. */
    public const COLLECTION = 'collection';

    /** A block scalar (`|`, `>`): not settable. */
    public const BLOCK = 'block';

    /** A key that appears twice under one parent: not settable. */
    public const DUPLICATE = 'duplicate';

    /** A span this cannot read exactly: not settable, and nothing inside is addressable. */
    public const OPAQUE = 'opaque';

    /** One segment of a path the reader could not follow. Never valid in a fact path. */
    private const UNREADABLE = "\0";

    private const KEY = '[A-Za-z0-9_][A-Za-z0-9_-]*';

    /**
     * @param  array<string, array{start: int, end: int, kind: string}>  $entries
     */
    private function __construct(private readonly string $text, private readonly array $entries) {}

    public static function parse(string $text): self
    {
        $entries = [];

        /** @var list<array{indent: int, segment: string, item: bool}> $stack */
        $stack = [];

        /** @var array<string, int> $counters the next block sequence index, by parent path */
        $counters = [];

        $length = strlen($text);
        $offset = 0;
        $skipUntil = 0;
        $opaqueIndent = null;
        $seenContent = false;

        while ($offset < $length) {
            $eol = strpos($text, "\n", $offset);
            $lineStart = $offset;
            $lineEnd = $eol === false ? $length : $eol;
            $offset = $eol === false ? $length : $eol + 1;

            // Inside a span already read (a flow collection or quoted scalar that
            // spans lines), or one skipped as opaque.
            if ($lineStart < $skipUntil) {
                continue;
            }

            $line = rtrim(substr($text, $lineStart, $lineEnd - $lineStart), "\r");
            $content = ltrim($line, ' ');
            $indent = strlen($line) - strlen($content);

            // Everything nested under an opaque span or a block scalar belongs to it.
            if ($opaqueIndent !== null) {
                if (trim($content) === '' || $indent > $opaqueIndent) {
                    continue;
                }

                $opaqueIndent = null;
            }

            if (trim($content) === '' || $content[0] === '#') {
                continue;
            }

            if (preg_match('/^(---|\.\.\.)(\s|$)/', $content) === 1 || $content[0] === '%') {
                // The node reads the FIRST document; nothing after it is addressable.
                if ($seenContent) {
                    break;
                }

                $stack = [];

                continue;
            }

            $seenContent = true;
            $column = $indent;
            $rest = $content;

            if (preg_match('/^-( +|$)/', $content, $dash) === 1) {
                while ($stack !== [] && (end($stack)['indent'] > $indent || (end($stack)['indent'] === $indent && end($stack)['item']))) {
                    array_pop($stack);
                }

                $parent = self::join($stack);
                $index = $counters[$parent] ?? 0;
                $counters[$parent] = $index + 1;
                $stack[] = ['indent' => $indent, 'segment' => (string) $index, 'item' => true];

                $item = self::join($stack);
                $column = $indent + strlen($dash[0]);
                $rest = substr($content, strlen($dash[0]));

                if (trim($rest) === '' || $rest[0] === '#') {
                    self::record($entries, $item, $lineStart + $column, $lineStart + $column, self::COLLECTION);

                    continue;
                }

                // A nested block sequence (`- - x`): not read, so opaque, with
                // everything deeper than this dash.
                if (preg_match('/^-( |$)/', $rest) === 1) {
                    self::record($entries, $item, $lineStart + $column, $lineStart + $column, self::OPAQUE);
                    $opaqueIndent = $indent;

                    continue;
                }

                if (preg_match('/^'.self::anyKeyPattern().'/', $rest) !== 1) {
                    self::value($text, $lineStart + $column, $item, $entries, $skipUntil, $opaqueIndent, $indent);

                    continue;
                }

                // A mapping item (`- kid: x`): the item holds values, then its first
                // key is read below like any other.
                self::record($entries, $item, $lineStart + $column, $lineStart + $column, self::COLLECTION);
            } else {
                while ($stack !== [] && end($stack)['indent'] >= $indent) {
                    array_pop($stack);
                }
            }

            if (preg_match('/^'.self::anyKeyPattern().'/', $rest, $match) !== 1) {
                // Neither a key nor an item: the collection holding it is not what the
                // reader thinks it is (a scalar on the line under its key, a
                // continuation), so it is opaque, with the rest of its content. At the
                // root nothing after it is addressable.
                if ($stack === []) {
                    break;
                }

                self::markOpaque($entries, self::join($stack));
                $opaqueIndent = end($stack)['indent'];

                continue;
            }

            // A key this cannot spell as a path is read, and everything under it is
            // unaddressable.
            $key = preg_match('/^'.self::keyPattern().'/', $rest, $addressable) === 1 && strlen($addressable[0]) === strlen($match[0])
                ? self::keyOf($addressable)
                : self::UNREADABLE;
            $path = self::join([...$stack, ['indent' => $column, 'segment' => $key, 'item' => false]]);
            $after = substr($rest, strlen($match[0]));
            $valueAt = $lineStart + $column + strlen($match[0]) + (strlen($after) - strlen(ltrim($after, ' ')));
            $after = ltrim($after, ' ');

            if ($after === '' || $after[0] === '#') {
                $stack[] = ['indent' => $column, 'segment' => $key, 'item' => false];
                self::record($entries, $path, $valueAt, $valueAt, self::COLLECTION);

                continue;
            }

            self::value($text, $valueAt, $path, $entries, $skipUntil, $opaqueIndent, $column);
        }

        return new self($text, $entries);
    }

    /**
     * Whether the template carries a value, a collection or anything else at `$path`.
     */
    public function has(string $path): bool
    {
        return isset($this->entries[$path]);
    }

    /**
     * What sits at `$path`: one of the kind constants, or null when nothing does.
     */
    public function kind(string $path): ?string
    {
        return $this->entries[$path]['kind'] ?? null;
    }

    /**
     * Whether `$path` is a single value a fact may set: a scalar or a flow sequence.
     */
    public function settable(string $path): bool
    {
        return in_array($this->kind($path), [self::SCALAR, self::SEQUENCE], true)
            && $this->opaqueAncestor($path) === null;
    }

    /**
     * The span `$path` is, or falls inside, that this cannot read exactly (an opaque
     * span, a block scalar, a duplicated key), or null when there is none.
     */
    public function opaqueAncestor(string $path): ?string
    {
        $prefix = '';

        foreach (explode('.', $path) as $segment) {
            $prefix = $prefix === '' ? $segment : "{$prefix}.{$segment}";

            if (in_array($this->kind($prefix), [self::OPAQUE, self::BLOCK, self::DUPLICATE], true)) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * The value's text as the template writes it (quotes included), or null.
     */
    public function raw(string $path): ?string
    {
        $entry = $this->entries[$path] ?? null;

        return $entry === null ? null : substr($this->text, $entry['start'], $entry['end'] - $entry['start']);
    }

    /**
     * Every path the template carries, in document order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $entries = $this->entries;
        uasort($entries, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_values(array_filter(array_keys($entries), fn (string $path): bool => ! str_contains($path, self::UNREADABLE)));
    }

    /**
     * The text with each path's value replaced by the YAML text given. The caller has
     * checked every path is settable(); two paths whose spans overlap (a sequence and
     * one of its items) answer null rather than guess which one wins.
     *
     * @param  array<string, string>  $values  path => YAML text
     */
    public function replace(array $values): ?string
    {
        $spans = [];

        foreach ($values as $path => $yaml) {
            $entry = $this->entries[$path];
            $spans[] = [$entry['start'], $entry['end'], $yaml];
        }

        usort($spans, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $text = $this->text;
        $floor = PHP_INT_MAX;

        foreach ($spans as [$start, $end, $yaml]) {
            if ($end > $floor) {
                return null;
            }

            $text = substr($text, 0, $start).$yaml.substr($text, $end);
            $floor = $start;
        }

        return $text;
    }

    /**
     * Whether `$path` is spelled the way a TemplateValues path is: dot-separated keys
     * and indices, nothing else.
     */
    public static function isPath(string $path): bool
    {
        return preg_match('/^'.self::KEY.'(\.'.self::KEY.')*\z/', $path) === 1;
    }

    /**
     * A value at `$at` in block context, owned by a key or item at `$indent`: a flow
     * collection, a block scalar, or a scalar on this line. Anything not read exactly
     * is recorded opaque and skipped whole.
     *
     * @param  array<string, array{start: int, end: int, kind: string}>  $entries
     */
    private static function value(string $text, int $at, string $path, array &$entries, int &$skipUntil, ?int &$opaqueIndent, int $indent): void
    {
        $char = $text[$at] ?? '';

        if ($char === '{' || $char === '[') {
            $found = [];
            $end = self::flow($text, $at, $path, $found);

            if ($end !== null && self::restIsComment($text, $end) && ! self::continues($text, $end, $indent)) {
                foreach ($found as $child => $entry) {
                    self::record($entries, $child, $entry['start'], $entry['end'], $entry['kind']);
                }

                $skipUntil = $end;

                return;
            }

            self::opaque($entries, $path, $at, $skipUntil, $opaqueIndent, $indent, self::matchingClose($text, $at), strlen($text));

            return;
        }

        if ($char === '|' || $char === '>') {
            // The content is every line deeper than the OWNER (a sequence item's
            // dash, or the key), however deep its first line sits.
            self::record($entries, $path, $at, $at, self::BLOCK);
            $opaqueIndent = $indent;

            return;
        }

        if ($char === '"' || $char === "'") {
            $end = self::scalarEnd($text, $at, false);

            if ($end === null) {
                // Closes on a later line, or never: a multi-line quoted scalar.
                self::opaque($entries, $path, $at, $skipUntil, $opaqueIndent, $indent, self::quotedEnd($text, $at), strlen($text));

                return;
            }
        } elseif (str_contains('&*!%@`', $char)) {
            // An anchor, alias or tag (or a reserved indicator): not read.
            self::opaque($entries, $path, $at, $skipUntil, $opaqueIndent, $indent, $at, $at);

            return;
        } else {
            $end = self::scalarEnd($text, $at, false);
        }

        if ($end !== null && self::restIsComment($text, $end) && ! self::continues($text, $end, $indent)) {
            self::record($entries, $path, $at, $end, self::SCALAR);

            return;
        }

        // A plain scalar that continues on the next lines (or a line with more after
        // the value than a comment).
        self::opaque($entries, $path, $at, $skipUntil, $opaqueIndent, $indent, $at, $at);
    }

    /**
     * Record `$path` opaque and skip it: to `$close` (the span's own end, the whole
     * text when it never closes), then every line nested deeper than its owner.
     *
     * @param  array<string, array{start: int, end: int, kind: string}>  $entries
     *
     * @param-out int $opaqueIndent
     */
    private static function opaque(array &$entries, string $path, int $at, int &$skipUntil, ?int &$opaqueIndent, int $indent, ?int $close, int $length): void
    {
        self::record($entries, $path, $at, $at, self::OPAQUE);
        $skipUntil = max($skipUntil, $close ?? $length);
        $opaqueIndent = $indent;
    }

    /**
     * Mark an already recorded collection opaque.
     *
     * @param  array<string, array{start: int, end: int, kind: string}>  $entries
     */
    private static function markOpaque(array &$entries, string $path): void
    {
        if (isset($entries[$path])) {
            $entries[$path]['kind'] = self::OPAQUE;
        } else {
            $entries[$path] = ['start' => 0, 'end' => 0, 'kind' => self::OPAQUE];
        }
    }

    /**
     * Whether the first content line after the one holding `$from` is nested deeper
     * than `$indent`: the value at `$from` continues there (or the structure is not one
     * this reads). Blank and comment-only lines do not count.
     */
    private static function continues(string $text, int $from, int $indent): bool
    {
        $length = strlen($text);
        $eol = strpos($text, "\n", $from);
        $p = $eol === false ? $length : $eol + 1;

        while ($p < $length) {
            $eol = strpos($text, "\n", $p);
            $line = rtrim(substr($text, $p, ($eol === false ? $length : $eol) - $p), "\r");
            $content = ltrim($line, ' ');
            $p = $eol === false ? $length : $eol + 1;

            if (trim($content) === '' || $content[0] === '#') {
                continue;
            }

            return strlen($line) - strlen($content) > $indent;
        }

        return false;
    }

    /**
     * The offset after the bracket closing the flow collection that opens at `$at`,
     * across lines, quotes and comments; null when it never closes.
     */
    private static function matchingClose(string $text, int $at): ?int
    {
        $length = strlen($text);
        $depth = 0;

        for ($p = $at; $p < $length; $p++) {
            $char = $text[$p];

            if ($char === '"' || $char === "'") {
                $end = self::quotedEnd($text, $p);

                if ($end === null) {
                    return null;
                }

                $p = $end - 1;
            } elseif ($char === '#' && in_array($text[$p - 1] ?? "\n", [' ', "\t", "\n", "\r"], true)) {
                $eol = strpos($text, "\n", $p);

                if ($eol === false) {
                    return null;
                }

                $p = $eol;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;

                if ($depth === 0) {
                    return $p + 1;
                }
            }
        }

        return null;
    }

    /**
     * The offset after the quote closing the quoted scalar that opens at `$at`, across
     * lines (`\"` escapes a double quote, `''` a single one); null when it never closes.
     */
    private static function quotedEnd(string $text, int $at): ?int
    {
        $quote = $text[$at];
        $length = strlen($text);

        for ($p = $at + 1; $p < $length; $p++) {
            if ($quote === '"' && $text[$p] === '\\') {
                $p++;

                continue;
            }

            if ($text[$p] === $quote) {
                if ($quote === "'" && ($text[$p + 1] ?? '') === "'") {
                    $p++;

                    continue;
                }

                return $p + 1;
            }
        }

        return null;
    }

    /**
     * A flow collection opening at `$at`, its children recorded into `$found`; the
     * offset after its closing bracket, or null when it does not read.
     *
     * @param  array<string, array{start: int, end: int, kind: string}>  $found
     */
    private static function flow(string $text, int $at, string $path, array &$found): ?int
    {
        $close = $text[$at] === '{' ? '}' : ']';
        $mapping = $close === '}';
        $p = $at + 1;
        $index = 0;

        while (true) {
            $p = self::skipSpace($text, $p);

            if ($p >= strlen($text)) {
                return null;
            }

            if ($text[$p] === $close) {
                self::record($found, $path, $at, $p + 1, $mapping ? self::COLLECTION : self::SEQUENCE);

                return $p + 1;
            }

            if ($mapping) {
                if (preg_match('/\G'.self::keyPattern(flow: true).'/', $text, $match, 0, $p) !== 1) {
                    return null;
                }

                $child = $path.'.'.self::keyOf($match);
                $p = self::skipSpace($text, $p + strlen($match[0]));
            } else {
                $child = $path.'.'.$index++;
            }

            $char = $text[$p] ?? '';

            if ($char === '{' || $char === '[') {
                $end = self::flow($text, $p, $child, $found);
            } elseif (str_contains('&*!%@`', $char)) {
                // An anchor, alias or tag (or a reserved indicator), as in value(): the
                // whole flow collection is opaque, so a value set here can never drop
                // an anchor that an alias elsewhere still names.
                return null;
            } elseif ($char === ',' || $char === $close) {
                $end = $p;
                self::record($found, $child, $p, $p, self::SCALAR);
            } else {
                $end = self::scalarEnd($text, $p, true);

                if ($end !== null) {
                    self::record($found, $child, $p, $end, self::SCALAR);
                }
            }

            if ($end === null) {
                return null;
            }

            $p = self::skipSpace($text, $end);
            $char = $text[$p] ?? '';

            if ($char === ',') {
                $p++;
            } elseif ($char !== $close) {
                return null;
            }
        }
    }

    /**
     * The offset just after a scalar starting at `$at`, on its own line, or null for a
     * quoted scalar that does not close there. A plain scalar ends at a comment or the
     * line's end, and in flow context at `,`, `]` or `}` too; trailing blanks are not
     * part of it.
     */
    private static function scalarEnd(string $text, int $at, bool $inFlow): ?int
    {
        $length = strlen($text);
        $char = $text[$at];

        if ($char === '"' || $char === "'") {
            for ($p = $at + 1; $p < $length && $text[$p] !== "\n"; $p++) {
                if ($char === '"' && $text[$p] === '\\') {
                    $p++;

                    continue;
                }

                if ($text[$p] === $char) {
                    if ($char === "'" && ($text[$p + 1] ?? '') === "'") {
                        $p++;

                        continue;
                    }

                    return $p + 1;
                }
            }

            return null;
        }

        $p = $at;

        while ($p < $length && $text[$p] !== "\n" && $text[$p] !== "\r") {
            if ($inFlow && in_array($text[$p], [',', ']', '}'], true)) {
                break;
            }

            if ($text[$p] === '#' && $p > $at && in_array($text[$p - 1], [' ', "\t"], true)) {
                break;
            }

            $p++;
        }

        while ($p > $at && in_array($text[$p - 1], [' ', "\t"], true)) {
            $p--;
        }

        return $p;
    }

    /**
     * Past blanks, line breaks and comments, inside a flow collection.
     */
    private static function skipSpace(string $text, int $p): int
    {
        $length = strlen($text);

        while ($p < $length) {
            $char = $text[$p];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $p++;
            } elseif ($char === '#') {
                $eol = strpos($text, "\n", $p);
                $p = $eol === false ? $length : $eol;
            } else {
                break;
            }
        }

        return $p;
    }

    /**
     * Whether nothing but blanks and a comment follow `$at` on its line.
     */
    private static function restIsComment(string $text, int $at): bool
    {
        $eol = strpos($text, "\n", $at);
        $rest = ltrim(rtrim(substr($text, $at, ($eol === false ? strlen($text) : $eol) - $at), "\r"), " \t");

        return $rest === '' || $rest[0] === '#';
    }

    /**
     * Any block mapping key line, addressable or not: a quoted key, or a plain one
     * that does not start with an indicator.
     */
    private static function anyKeyPattern(): string
    {
        return '(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s#\'"{}\[\],&*!|>%@`?:-][^:#]*?|-[^\s:#][^:#]*?) *:(?: +|$)';
    }

    private static function keyPattern(bool $flow = false): string
    {
        $key = '(?:"('.self::KEY.')"|\'('.self::KEY.')\'|('.self::KEY.'))';

        return $flow ? $key.'[ \t]*:(?=[\s,{\[])[ \t]*' : $key.' *:(?: +|$)';
    }

    /**
     * @param  array<int, string>  $match
     */
    private static function keyOf(array $match): string
    {
        foreach ([1, 2, 3] as $group) {
            if (($match[$group] ?? '') !== '') {
                return $match[$group];
            }
        }

        return self::UNREADABLE;
    }

    /**
     * @param  list<array{indent: int, segment: string, item: bool}>  $stack
     */
    private static function join(array $stack): string
    {
        return implode('.', array_column($stack, 'segment'));
    }

    /**
     * @param  array<string, array{start: int, end: int, kind: string}>  $entries
     */
    private static function record(array &$entries, string $path, int $start, int $end, string $kind): void
    {
        if (isset($entries[$path])) {
            $entries[$path]['kind'] = self::DUPLICATE;

            return;
        }

        $entries[$path] = ['start' => $start, 'end' => $end, 'kind' => $kind];
    }
}
