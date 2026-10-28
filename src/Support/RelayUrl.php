<?php

declare(strict_types=1);

namespace AlexHackney\Tmesis\Support;

/**
 * Redacts a relay destination URL to scheme, host and port.
 *
 * A relay URL is a credential: an SRT passphrase or an RTMP stream key travels in the
 * query string or the path. The node already redacts before sending, and this package
 * redacts again on the way in, so nothing here can hold, log, or persist an
 * unredacted one even if a node ever sent it.
 */
final class RelayUrl
{
    public static function redact(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            // Unparseable: return the scheme alone when there is one, and nothing
            // else. Anything more risks passing a credential through.
            $scheme = str_contains($url, '://') ? strstr($url, '://', true) : null;

            return is_string($scheme) && $scheme !== '' ? $scheme.'://' : '';
        }

        $redacted = '';

        if (isset($parts['scheme'])) {
            $redacted .= $parts['scheme'].'://';
        }

        $redacted .= $parts['host'];

        if (isset($parts['port'])) {
            $redacted .= ':'.$parts['port'];
        }

        return $redacted;
    }
}
