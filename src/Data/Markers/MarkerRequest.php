<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Markers;

use AlexHackney\Tmesis\Exceptions\InvalidConfigurationException;
use AlexHackney\Tmesis\Support\Rfc3339;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * The body of `placeMarker`: one HLS date-range tag (an SCTE-35 splice out or in) on
 * every media rendition of a live stream. The openapi component MarkerRequest (tmesis
 * v0.6.0, `docs/API.md` 9.8a).
 *
 * **The id is minted HERE, as a ULID, unless given.** The node would mint one if the
 * id were omitted, but then the caller could not correlate the `marker.placed` that
 * lands it, or retry the POST idempotently. Keep `id()`: the same request under the
 * same id is idempotent (`200`, nothing queued twice), a different request under it
 * is `409 marker_exists`, and idempotency is per publish SESSION `(stream, session,
 * id)`, so a new session may reuse an id.
 *
 * **An `in` is its OWN marker** with its own id and tag, not an edit of the `out`.
 *
 * Only the grammar is checked here (`kind`, the id and class charsets, `at` as a
 * date, `duration_seconds` 0 to 86400, `scte35` as hex of at most 4099 bytes). The
 * window on `at` (more than one target duration in the past, more than a day ahead)
 * is the node's `422`: it depends on the session's segment grid.
 *
 * Every instance is immutable: each setter returns a new request.
 */
final readonly class MarkerRequest
{
    public const KIND_OUT = 'out';

    public const KIND_IN = 'in';

    public const KINDS = [self::KIND_OUT, self::KIND_IN];

    /** The CLASS the node writes when none is told. */
    public const DEFAULT_CLASS = 'com.hackney.tmesis.scte35';

    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/';

    public const CLASS_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/';

    public const MAX_DURATION_SECONDS = 86400;

    /** The largest splice_info_section. */
    public const MAX_SCTE35_BYTES = 4099;

    /**
     * @param  array<string, mixed>  $fields  only the optional keys that were set
     */
    private function __construct(
        private string $kind,
        private string $id,
        private array $fields,
    ) {}

    /**
     * A splice OUT: the break starts.
     */
    public static function out(?string $id = null): self
    {
        return self::make(self::KIND_OUT, $id);
    }

    /**
     * A splice IN: the break ends. Its own marker, with its own id.
     */
    public static function in(?string $id = null): self
    {
        return self::make(self::KIND_IN, $id);
    }

    /**
     * @param  string  $kind  `out` or `in`
     * @param  string|null  $id  the tag's ID; a ULID is minted when null
     */
    public static function make(string $kind, ?string $id = null): self
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw InvalidConfigurationException::invalid('kind', 'must be out or in');
        }

        $id ??= (string) Str::ulid();

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw InvalidConfigurationException::invalid('id', 'must match ^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$');
        }

        return new self($kind, $id, []);
    }

    /**
     * The tag's START-DATE, landed on the segment boundary nearest it. Null (the
     * default, sent as null) is NOW: the boundary the next cut closes.
     */
    public function at(DateTimeInterface|string|null $at): self
    {
        if ($at instanceof DateTimeInterface) {
            $at = Rfc3339::format($at);
        } elseif ($at !== null && ! self::isRfc3339($at)) {
            throw InvalidConfigurationException::invalid('at', 'must be an RFC3339 date-time');
        }

        return $this->with('at', $at);
    }

    /**
     * The tag's CLASS. Omitted is `com.hackney.tmesis.scte35`.
     */
    public function withClass(string $class): self
    {
        if (preg_match(self::CLASS_PATTERN, $class) !== 1) {
            throw InvalidConfigurationException::invalid('class', 'must match ^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$');
        }

        return $this->with('class', $class);
    }

    /**
     * The DURATION, 0 to 86400 seconds. For an `in` carrying `scte35` it MUST be the
     * actual out-to-in duration (RFC 8216bis 4.4.5.1.1); for an `out` it is what the
     * caller dispatched. Omitted writes no DURATION.
     */
    public function durationSeconds(int|float $seconds): self
    {
        if ($seconds < 0 || $seconds > self::MAX_DURATION_SECONDS) {
            throw InvalidConfigurationException::invalid('duration_seconds', 'must be 0 to 86400');
        }

        return $this->with('duration_seconds', $seconds);
    }

    /**
     * The caller's splice_info_section as hex, `0x` optional, rendered as SCTE35-OUT
     * or SCTE35-IN. Validated as hex only: the node never parses or mints SCTE-35.
     * Omitted, the tag carries CLASS and X-TMESIS-KIND alone.
     */
    public function scte35(string $hex): self
    {
        $digits = str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;

        if ($digits === '' || strlen($digits) % 2 !== 0 || ! ctype_xdigit($digits)) {
            throw InvalidConfigurationException::invalid('scte35', 'must be hex (an even number of hex digits, 0x optional)');
        }

        if (strlen($digits) / 2 > self::MAX_SCTE35_BYTES) {
            throw InvalidConfigurationException::invalid('scte35', 'must be at most 4099 bytes, the largest splice_info_section');
        }

        return $this->with('scte35', $hex);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * The marker id: keep it to correlate `marker.placed` and to retry.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * The request body: `kind` and `id` always, the rest only when set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id, ...$this->fields];
    }

    private function with(string $field, mixed $value): self
    {
        $fields = $this->fields;
        $fields[$field] = $value;

        return new self($this->kind, $this->id, $fields);
    }

    private static function isRfc3339(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/i', $value) === 1;
    }
}
