<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Keys;

use AlexHackney\Tmesis\Exceptions\InvalidPublishTemplateException;
use AlexHackney\Tmesis\Exceptions\MalformedStreamKeyException;
use Stringable;

/**
 * A publish key: `stream.epoch.mac`.
 *
 * Self validating at the node, which recomputes the MAC from its own copy of the
 * secret and makes no call back to the control plane. That is why the format is a
 * byte-for-byte contract (`test/vectors/keys.json`) rather than an implementation
 * detail.
 *
 * A key IS a credential. This class is Stringable and casts to the FULL key, MAC
 * included, so never interpolate one into a log line or an exception message; nothing
 * in this package logs one. Use fragment() wherever a key would otherwise reach a log.
 */
final readonly class StreamKey implements Stringable
{
    public const MAC_PATTERN = '/^[0-9a-f]{64}\z/';

    public function __construct(
        public string $stream,
        public KeyEpoch $epoch,
        public string $mac,
    ) {}

    /**
     * Parse a presented key.
     *
     * Split on the LAST two dots. The stream id charset forbids a dot, so a key with
     * more than three fields is malformed rather than reinterpreted, which is what
     * stops `shows/../etc` style ambiguity from ever becoming a parse question.
     *
     * @throws MalformedStreamKeyException on any bad field, with no secret consulted
     */
    public static function parse(string $key): self
    {
        $lastDot = strrpos($key, '.');

        if ($lastDot === false) {
            throw new MalformedStreamKeyException('A stream key must be stream.epoch.mac.');
        }

        $mac = substr($key, $lastDot + 1);
        $head = substr($key, 0, $lastDot);

        $firstDot = strrpos($head, '.');

        if ($firstDot === false) {
            throw new MalformedStreamKeyException('A stream key must be stream.epoch.mac.');
        }

        $stream = substr($head, 0, $firstDot);
        $epoch = substr($head, $firstDot + 1);

        StreamId::assert($stream);

        if (preg_match(self::MAC_PATTERN, $mac) !== 1) {
            throw new MalformedStreamKeyException('A stream key MAC must be 64 lowercase hex characters.');
        }

        return new self($stream, KeyEpoch::fromString($epoch), $mac);
    }

    public function toString(): string
    {
        return $this->stream.'.'.$this->epoch->toString().'.'.$this->mac;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * The bytes the MAC is computed over: `stream + "." + epoch`, and nothing else.
     * No trailing newline, no length prefix, no separator after the epoch.
     */
    public function signedString(): string
    {
        return $this->stream.'.'.$this->epoch->toString();
    }

    /**
     * The redacted form the node puts in `key_fragment`: the stream and epoch in
     * full, then the first four and last four hex characters of the MAC with three
     * dots between.
     *
     * This is the ONLY form of a key that may be logged.
     */
    public function fragment(): string
    {
        return $this->stream.'.'.$this->epoch->toString().'.'
            .substr($this->mac, 0, 4).'...'.substr($this->mac, -4);
    }

    /**
     * Render a publish URL from a template carrying a literal `{key}`.
     *
     * The node never returns or stores a key, so a publish URL template comes back
     * with the placeholder still in it and the control plane substitutes.
     *
     * @throws InvalidPublishTemplateException when the template has no placeholder
     */
    public function substituteInto(string $template): string
    {
        if (! str_contains($template, '{key}')) {
            throw new InvalidPublishTemplateException(
                'A publish URL template must carry a literal {key} placeholder.'
            );
        }

        return str_replace('{key}', $this->toString(), $template);
    }
}
