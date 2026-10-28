<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Data\Webhooks;

/**
 * One caption language's URLs: an entry of `stream.live` `playback.captions` and
 * `stream.sealed` `vod.captions` (both keyed by RFC 5646 tag), and the whole of
 * `caption.session_ended` `artifacts`. One shape on all three (the webhook
 * `$defs.CaptionPlayback`, since tmesis v0.3.0).
 *
 * On `stream.live` the playlist is the language's DVR caption playlist
 * (`captions_<lang>_dvr.m3u8`) and the three sidecars are null, because the show is
 * not sealed. Once sealed the playlist is the language's `captions_<lang>.m3u8`,
 * ended in place (since tmesis `v0.8.0`; it was `captions_<lang>_vod.m3u8`, and the
 * generated `stream.sealed` and `caption.session_ended` examples at the `v0.8.0` tag
 * still show that stale name) and the sidecars are the URLs the drain actually wrote: a sidecar the sink refused
 * stays null rather than being a URL that 404s.
 */
final readonly class CaptionPlayback
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(private array $raw) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The caption playlist at the stream root: DVR while live, VOD once sealed.
     */
    public function playlist(): string
    {
        $value = $this->raw['playlist'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The WebVTT transcript sidecar (`captions/<lang>/transcript.vtt`). Null while
     * live, and null after the seal when the sink refused it.
     */
    public function vtt(): ?string
    {
        return $this->nullableString('vtt');
    }

    /**
     * The SRT transcript sidecar (`captions/<lang>/transcript.srt`). Null while
     * live, and null after the seal when the sink refused it.
     */
    public function srt(): ?string
    {
        return $this->nullableString('srt');
    }

    /**
     * The JSON transcript sidecar (`captions/<lang>/transcript.json`): the same feed
     * `getStreamTranscript()` answers, one row per final cue with word timings, so a
     * consumer that indexes after the show need not poll the route. Null while live,
     * and null after the seal when the sink refused it.
     */
    public function json(): ?string
    {
        return $this->nullableString('json');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
